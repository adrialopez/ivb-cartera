<?php
/**
 * Libro de movimientos de la cartera. Una fila por apunte.
 *
 * type:   accrual (acumulado por un pedido) | adjust (ajuste manual) | redeem (saldo gastado en un pedido, importe negativo)
 * status: pending (pedido sin pagar) | confirmed (pedido pagado, ajuste o saldo gastado) | void (anulado)
 * El saldo disponible es la suma de los apuntes en estado confirmed (los canjes restan desde que se hace el pedido).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Ivbc_Ledger {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'ivbc_ledger';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table   = self::table();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            type varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            amount decimal(12,2) NOT NULL DEFAULT 0,
            base decimal(12,2) DEFAULT NULL,
            units int(11) DEFAULT NULL,
            campaign varchar(40) NOT NULL DEFAULT '',
            note varchar(255) NOT NULL DEFAULT '',
            created_gmt datetime NOT NULL,
            updated_gmt datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY user_status (user_id,status),
            KEY order_type (order_id,type)
        ) {$charset};");
        update_option('ivbc_db_version', IVBC_VERSION);
    }

    public static function maybe_upgrade() {
        if (get_option('ivbc_db_version') !== IVBC_VERSION) {
            self::install();
        }
    }

    public static function get_accrual($order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE order_id = %d AND type = 'accrual' ORDER BY id DESC LIMIT 1",
            $order_id
        ));
    }

    /** Crea o actualiza el apunte de acumulación de un pedido. */
    public static function save_accrual($order_id, $user_id, $status, $amount, $base, $units, $campaign) {
        global $wpdb;
        $now      = current_time('mysql', true);
        $existing = self::get_accrual($order_id);
        $data     = array(
            'user_id'     => (int) $user_id,
            'status'      => $status,
            'amount'      => round((float) $amount, 2),
            'base'        => round((float) $base, 2),
            'units'       => (int) $units,
            'campaign'    => (string) $campaign,
            'updated_gmt' => $now,
        );
        if ($existing) {
            $changed = ($existing->status !== $status)
                || abs((float) $existing->amount - $data['amount']) > 0.004
                || (int) $existing->units !== $data['units']
                || (int) $existing->user_id !== $data['user_id'];
            if ($changed) {
                $wpdb->update(self::table(), $data, array('id' => $existing->id));
            }
            return (int) $existing->id;
        }
        $data['order_id']    = (int) $order_id;
        $data['type']        = 'accrual';
        $data['created_gmt'] = $now;
        $wpdb->insert(self::table(), $data);
        return (int) $wpdb->insert_id;
    }

    /** Anula el apunte de un pedido si existe y no está ya anulado. */
    public static function void_accrual($order_id) {
        global $wpdb;
        $existing = self::get_accrual($order_id);
        if ($existing && $existing->status !== 'void') {
            $wpdb->update(
                self::table(),
                array('status' => 'void', 'updated_gmt' => current_time('mysql', true)),
                array('id' => $existing->id)
            );
        }
    }

    public static function get_redeem($order_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE order_id = %d AND type = 'redeem' ORDER BY id DESC LIMIT 1",
            $order_id
        ));
    }

    /** Crea o actualiza el apunte de saldo gastado en un pedido ($amount en positivo; se guarda en negativo). */
    public static function save_redeem($order_id, $user_id, $status, $amount) {
        global $wpdb;
        $now      = current_time('mysql', true);
        $existing = self::get_redeem($order_id);
        $data     = array(
            'user_id'     => (int) $user_id,
            'status'      => $status,
            'amount'      => -abs(round((float) $amount, 2)),
            'campaign'    => (string) Ivbc_Settings::get('campaign'),
            'updated_gmt' => $now,
        );
        if ($existing) {
            if ($existing->status !== $status || abs((float) $existing->amount - $data['amount']) > 0.004 || (int) $existing->user_id !== $data['user_id']) {
                $wpdb->update(self::table(), $data, array('id' => $existing->id));
            }
            return (int) $existing->id;
        }
        $data['order_id']    = (int) $order_id;
        $data['type']        = 'redeem';
        $data['created_gmt'] = $now;
        $wpdb->insert(self::table(), $data);
        return (int) $wpdb->insert_id;
    }

    /** Devuelve el saldo gastado en un pedido (anula el apunte). */
    public static function void_redeem($order_id) {
        global $wpdb;
        $existing = self::get_redeem($order_id);
        if ($existing && $existing->status !== 'void') {
            $wpdb->update(self::table(), array('status' => 'void', 'updated_gmt' => current_time('mysql', true)), array('id' => $existing->id));
        }
    }

    public static function add_adjustment($user_id, $amount, $note) {
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->insert(self::table(), array(
            'user_id'     => (int) $user_id,
            'order_id'    => null,
            'type'        => 'adjust',
            'status'      => 'confirmed',
            'amount'      => round((float) $amount, 2),
            'campaign'    => (string) Ivbc_Settings::get('campaign'),
            'note'        => mb_substr((string) $note, 0, 255),
            'created_gmt' => $now,
            'updated_gmt' => $now,
        ));
        return (int) $wpdb->insert_id;
    }

    /** Saldo de un cliente: disponible (confirmado) y pendiente de confirmar. */
    public static function balance($user_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'confirmed' THEN amount ELSE 0 END), 0) AS confirmed,
                COALESCE(SUM(CASE WHEN status = 'pending' AND type = 'accrual' THEN amount ELSE 0 END), 0) AS pending
             FROM " . self::table() . ' WHERE user_id = %d',
            $user_id
        ));
        return array(
            'confirmed' => $row ? round((float) $row->confirmed, 2) : 0.0,
            'pending'   => $row ? round((float) $row->pending, 2) : 0.0,
        );
    }

    public static function movements($user_id, $limit = 100) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY created_gmt DESC, id DESC LIMIT %d',
            $user_id,
            $limit
        ));
    }

    /** Listado de clientes con movimientos, con saldo y pendiente. */
    public static function customers($search = '', $paged = 1, $per_page = 30) {
        global $wpdb;
        $where = '';
        if ($search !== '') {
            $ids = get_users(array(
                'search'         => '*' . $search . '*',
                'search_columns' => array('user_login', 'user_email', 'display_name'),
                'fields'         => 'ID',
                'number'         => 500,
            ));
            $meta_ids = get_users(array(
                'meta_query' => array('relation' => 'OR',
                    array('key' => 'billing_company', 'value' => $search, 'compare' => 'LIKE'),
                    array('key' => 'billing_first_name', 'value' => $search, 'compare' => 'LIKE'),
                ),
                'fields' => 'ID',
                'number' => 500,
            ));
            $ids = array_unique(array_merge(array_map('intval', $ids), array_map('intval', $meta_ids)));
            if (!$ids) {
                return array('rows' => array(), 'total' => 0);
            }
            $where = ' WHERE user_id IN (' . implode(',', array_map('intval', $ids)) . ')';
        }
        $table  = self::table();
        $total  = (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$table}{$where}");
        $offset = max(0, ($paged - 1) * $per_page);
        $rows   = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id,
                COALESCE(SUM(CASE WHEN status = 'confirmed' THEN amount ELSE 0 END), 0) AS confirmed,
                COALESCE(SUM(CASE WHEN status = 'pending' AND type = 'accrual' THEN amount ELSE 0 END), 0) AS pending,
                COUNT(CASE WHEN type = 'accrual' AND status <> 'void' THEN 1 END) AS orders,
                MAX(updated_gmt) AS last_gmt
             FROM {$table}{$where} GROUP BY user_id ORDER BY confirmed DESC, pending DESC LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        return array('rows' => $rows, 'total' => $total);
    }

    public static function totals() {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT
                COALESCE(SUM(CASE WHEN status = 'confirmed' THEN amount ELSE 0 END), 0) AS confirmed,
                COALESCE(SUM(CASE WHEN status = 'pending' AND type = 'accrual' THEN amount ELSE 0 END), 0) AS pending,
                COUNT(DISTINCT CASE WHEN status <> 'void' THEN user_id END) AS customers,
                COUNT(CASE WHEN type = 'accrual' AND status <> 'void' THEN 1 END) AS orders
             FROM " . self::table()
        );
        return array(
            'confirmed' => $row ? (float) $row->confirmed : 0.0,
            'pending'   => $row ? (float) $row->pending : 0.0,
            'customers' => $row ? (int) $row->customers : 0,
            'orders'    => $row ? (int) $row->orders : 0,
        );
    }

    /** Total de saldo gastado en pedidos (en positivo). */
    public static function redeemed_total() {
        global $wpdb;
        return abs((float) $wpdb->get_var('SELECT COALESCE(SUM(amount), 0) FROM ' . self::table() . " WHERE type = 'redeem' AND status = 'confirmed'"));
    }

    public static function all_rows($limit = 20000) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' ORDER BY id ASC LIMIT %d', $limit));
    }
}
