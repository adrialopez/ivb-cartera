<?php
/**
 * Administración: WooCommerce > Cartera IVB (resumen, clientes, detalle, ajustes), caja en el pedido,
 * saldo en la ficha del usuario y exportación CSV.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Ivbc_Admin {

    const SLUG = 'ivb-cartera';
    const CAP  = 'manage_woocommerce';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_ivbc_save_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_ivbc_adjust', array(__CLASS__, 'save_adjustment'));
        add_action('admin_post_ivbc_export', array(__CLASS__, 'export_csv'));
        add_action('admin_post_ivbc_recalc', array(__CLASS__, 'recalc_order'));
        add_action('add_meta_boxes', array(__CLASS__, 'meta_box'));
        add_action('show_user_profile', array(__CLASS__, 'profile_box'));
        add_action('edit_user_profile', array(__CLASS__, 'profile_box'));
    }

    public static function menu() {
        add_submenu_page('woocommerce', 'Cartera IVB', 'Cartera IVB', self::CAP, self::SLUG, array(__CLASS__, 'page'));
    }

    public static function assets($hook) {
        if (strpos((string) $hook, self::SLUG) !== false || in_array($hook, array('post.php', 'woocommerce_page_wc-orders', 'user-edit.php', 'profile.php'), true)) {
            wp_enqueue_style('ivbc-admin', IVBC_URL . 'assets/css/admin.css', array(), IVBC_VERSION);
        }
    }

    private static function url($args = array()) {
        return add_query_arg(array_merge(array('page' => self::SLUG), $args), admin_url('admin.php'));
    }

    private static function money($v) {
        return wc_price((float) $v);
    }

    private static function mode_label() {
        $labels = array('off' => 'Apagada', 'admins' => 'Solo administradores (pruebas)', 'all' => 'Todos los clientes');
        return $labels[Ivbc_Rules::mode()];
    }

    /* ---------- páginas ---------- */

    public static function page() {
        if (!current_user_can(self::CAP)) {
            wp_die('No tienes permiso para ver esta página.');
        }
        $tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'clientes';
        $user = isset($_GET['user']) ? (int) $_GET['user'] : 0;
        echo '<div class="wrap ivbc-admin"><h1>Cartera IVB</h1>';
        self::notices();
        $mode = Ivbc_Rules::mode();
        echo '<div class="ivbc-modebar ivbc-modebar--' . esc_attr($mode) . '"><strong>Estado:</strong> ' . esc_html(self::mode_label())
            . ' &middot; campaña ' . esc_html((string) Ivbc_Settings::get('campaign')) . ' del ' . esc_html(Ivbc_Frontend::date_es(Ivbc_Settings::get('start')))
            . ' al ' . esc_html(Ivbc_Frontend::date_es(Ivbc_Settings::get('end'))) . '</div>';
        echo '<h2 class="nav-tab-wrapper"><a class="nav-tab' . ($tab === 'clientes' ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url()) . '">Clientes</a>'
            . '<a class="nav-tab' . ($tab === 'ajustes' ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url(array('tab' => 'ajustes'))) . '">Ajustes</a></h2>';
        if ($user && $tab === 'clientes') {
            self::render_user($user);
        } elseif ($tab === 'ajustes') {
            self::render_settings();
        } else {
            self::render_customers();
        }
        echo '</div>';
    }

    private static function notices() {
        $m = isset($_GET['ivbc_msg']) ? sanitize_key($_GET['ivbc_msg']) : '';
        $texts = array('saved' => 'Ajustes guardados.', 'adjusted' => 'Ajuste registrado.', 'recalc' => 'Pedido recalculado.', 'bad' => 'Datos no válidos.');
        if (isset($texts[$m])) {
            echo '<div class="notice notice-' . ($m === 'bad' ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($texts[$m]) . '</p></div>';
        }
    }

    private static function render_customers() {
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged  = max(1, isset($_GET['paged']) ? (int) $_GET['paged'] : 1);
        $per    = 30;
        $t      = Ivbc_Ledger::totals();
        $list   = Ivbc_Ledger::customers($search, $paged, $per);

        echo '<div class="ivbc-kpis">'
            . '<div class="ivbc-kpi"><span>Saldo confirmado</span><strong>' . self::money($t['confirmed']) . '</strong></div>'
            . '<div class="ivbc-kpi"><span>Clientes con saldo</span><strong>' . (int) $t['customers'] . '</strong></div>'
            . '<div class="ivbc-kpi"><span>Pedidos que acumulan</span><strong>' . (int) $t['orders'] . '</strong></div></div>';

        echo '<form method="get" class="ivbc-search"><input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '">'
            . '<input type="search" name="s" value="' . esc_attr($search) . '" placeholder="Buscar por nombre, empresa o correo"> '
            . '<button class="button">Buscar</button> '
            . '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ivbc_export'), 'ivbc_export')) . '">Exportar movimientos (CSV)</a></form>';

        if (!$list['rows']) {
            echo '<p class="ivbc-empty">Todavía no hay movimientos en la cartera.' . (Ivbc_Rules::mode() === 'admins' ? ' Con el modo de pruebas, haz un pedido de al menos ' . (int) Ivbc_Settings::get('min_units') . ' unidades con una cuenta de administrador.' : '') . '</p>';
            return;
        }
        echo '<table class="widefat striped ivbc-table"><thead><tr><th>Cliente</th><th>Correo</th><th class="num">Pedidos</th><th class="num">Saldo</th><th>Último movimiento</th><th></th></tr></thead><tbody>';
        foreach ($list['rows'] as $r) {
            $u    = get_userdata($r->user_id);
            $name = $u ? trim(get_user_meta($u->ID, 'billing_company', true) ?: $u->display_name) : 'Usuario #' . (int) $r->user_id;
            echo '<tr><td><a href="' . esc_url(self::url(array('user' => (int) $r->user_id))) . '"><strong>' . esc_html($name) . '</strong></a></td>'
                . '<td>' . esc_html($u ? $u->user_email : '') . '</td><td class="num">' . (int) $r->orders . '</td>'
                . '<td class="num"><strong>' . self::money($r->confirmed) . '</strong></td>'
                . '<td>' . esc_html(get_date_from_gmt($r->last_gmt, 'd/m/Y H:i')) . '</td>'
                . '<td><a class="button button-small" href="' . esc_url(self::url(array('user' => (int) $r->user_id))) . '">Ver</a></td></tr>';
        }
        echo '</tbody></table>';
        $pages = (int) ceil($list['total'] / $per);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(array(
                'base' => add_query_arg('paged', '%#%', self::url(array('s' => $search))), 'format' => '', 'current' => $paged, 'total' => $pages,
            ))) . '</div></div>';
        }
    }

    private static function render_user($user_id) {
        $u = get_userdata($user_id);
        if (!$u) {
            echo '<p>Usuario no encontrado.</p>';
            return;
        }
        $b    = Ivbc_Ledger::balance($user_id);
        $rows = Ivbc_Ledger::movements($user_id, 200);
        $company = get_user_meta($user_id, 'billing_company', true);
        echo '<p><a href="' . esc_url(self::url()) . '">&larr; Volver al listado</a></p>';
        echo '<h2>' . esc_html($company ?: $u->display_name) . ' <small>(' . esc_html($u->user_email) . ')</small></h2>';
        echo '<div class="ivbc-kpis"><div class="ivbc-kpi"><span>Saldo confirmado</span><strong>' . self::money($b['confirmed']) . '</strong></div></div>';

        echo '<h3>Movimientos</h3>';
        echo '<table class="widefat striped ivbc-table"><thead><tr><th>Fecha</th><th>Tipo</th><th>Pedido</th><th>Unidades</th><th>Base</th><th>Estado</th><th class="num">Importe</th><th>Nota</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="8">Sin movimientos.</td></tr>';
        }
        foreach ($rows as $r) {
            $order = $r->order_id ? '<a href="' . esc_url(get_edit_post_link((int) $r->order_id) ?: admin_url('admin.php?page=wc-orders&action=edit&id=' . (int) $r->order_id)) . '">#' . (int) $r->order_id . '</a>' : '';
            echo '<tr><td>' . esc_html(get_date_from_gmt($r->created_gmt, 'd/m/Y H:i')) . '</td><td>' . esc_html($r->type === 'accrual' ? 'Acumulación' : ($r->type === 'adjust' ? 'Ajuste' : $r->type)) . '</td>'
                . '<td>' . $order . '</td><td>' . ($r->units !== null ? (int) $r->units : '') . '</td><td>' . ($r->base !== null ? self::money($r->base) : '') . '</td>'
                . '<td><span class="ivbc-pill ivbc-pill--' . esc_attr($r->status) . '">' . esc_html($r->status === 'confirmed' ? 'Confirmado' : 'Anulado') . '</span></td>'
                . '<td class="num">' . self::money($r->amount) . '</td><td>' . esc_html($r->note) . '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h3>Ajuste manual</h3><p class="description">Suma (positivo) o resta (negativo) saldo. Queda registrado en los movimientos.</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="ivbc-form">'
            . '<input type="hidden" name="action" value="ivbc_adjust"><input type="hidden" name="user_id" value="' . (int) $user_id . '">';
        wp_nonce_field('ivbc_adjust');
        echo 'Importe (€): <input type="text" name="amount" size="8" placeholder="10,00"> Motivo: <input type="text" name="note" size="40" maxlength="200" placeholder="Ej.: regularización pedido #1234" required> '
            . '<button class="button button-primary">Registrar ajuste</button></form>';
    }

    private static function render_settings() {
        $s = Ivbc_Settings::all();
        $field = function ($label, $html, $help = '') {
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>' . $html . ($help ? '<p class="description">' . esc_html($help) . '</p>' : '') . '</td></tr>';
        };
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="ivbc_save_settings">';
        wp_nonce_field('ivbc_save_settings');
        echo '<table class="form-table"><tbody>';
        $opts = '';
        foreach (array('admins' => 'Solo administradores (pruebas)', 'all' => 'Todos los clientes', 'off' => 'Apagada') as $k => $l) {
            $opts .= '<option value="' . esc_attr($k) . '"' . selected($s['mode'], $k, false) . '>' . esc_html($l) . '</option>';
        }
        $field('Quién la ve', '<select name="settings[mode]">' . $opts . '</select>', 'En "Solo administradores" nada cambia para los clientes: ni avisos, ni saldo, ni acumulación.');
        $field('Modo de prueba', '<label><input type="checkbox" name="settings[test_mode]" value="1"' . checked(!empty($s['test_mode']), true, false) . '> Los administradores acumulan aunque la fecha esté fuera de la campaña</label>', 'Solo afecta a administradores. Apágalo antes de abrir la cartera a todos.');
        $field('Nombre de la campaña', '<input type="text" name="settings[campaign]" value="' . esc_attr($s['campaign']) . '" class="regular-text">');
        $field('Acumulación desde', '<input type="date" name="settings[start]" value="' . esc_attr($s['start']) . '"> hasta <input type="date" name="settings[end]" value="' . esc_attr($s['end']) . '">', 'Se mira la fecha de creación del pedido.');
        $field('Porcentaje a acumular', '<input type="text" name="settings[percent]" value="' . esc_attr(str_replace('.', ',', (string) $s['percent'])) . '" size="5"> %', 'Sobre el importe de los productos del pedido sin IVA, sin envío y sin material promocional.');
        $field('Códigos que sí permiten acumular', '<input type="text" name="settings[allowed_coupons]" value="' . esc_attr($s['allowed_coupons']) . '" class="regular-text">', 'Los pedidos con cualquier otro código de descuento no acumulan. Aquí va el cupón automático de transferencia (2DESCTF). Separados por comas.');
        $field('Unidades mínimas', '<input type="number" min="1" name="settings[min_units]" value="' . (int) $s['min_units'] . '" size="5">', 'Unidades que no sean material promocional ni regalos.');
        $field('Límite de uso por pedido', '<input type="text" name="settings[redeem_limit]" value="' . esc_attr(str_replace('.', ',', (string) $s['redeem_limit'])) . '" size="5"> %', 'Solo informativo por ahora: el canje todavía no está activo.');
        $field('Fecha límite de uso', '<input type="date" name="settings[expiry]" value="' . esc_attr($s['expiry']) . '">');
        $field('Texto sobre el canje', '<input type="text" name="settings[redeem_note]" value="' . esc_attr($s['redeem_note']) . '" class="large-text">');
        echo '</tbody></table><p><button class="button button-primary">Guardar ajustes</button></p></form>';
    }

    /* ---------- acciones ---------- */

    private static function guard($nonce) {
        if (!current_user_can(self::CAP)) {
            wp_die('No tienes permiso.');
        }
        check_admin_referer($nonce);
    }

    public static function save_settings() {
        self::guard('ivbc_save_settings');
        $in = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : array();
        update_option(Ivbc_Settings::OPTION, Ivbc_Settings::sanitize($in), false);
        wp_safe_redirect(self::url(array('tab' => 'ajustes', 'ivbc_msg' => 'saved')));
        exit;
    }

    public static function save_adjustment() {
        self::guard('ivbc_adjust');
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $amount  = isset($_POST['amount']) ? (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['amount']))) : 0.0;
        $note    = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
        if (!$user_id || !get_userdata($user_id) || abs($amount) < 0.01 || $note === '') {
            wp_safe_redirect(self::url(array('user' => $user_id, 'ivbc_msg' => 'bad')));
            exit;
        }
        $by = wp_get_current_user();
        Ivbc_Ledger::add_adjustment($user_id, $amount, $note . ' (' . $by->user_login . ')');
        wp_safe_redirect(self::url(array('user' => $user_id, 'ivbc_msg' => 'adjusted')));
        exit;
    }

    public static function recalc_order() {
        self::guard('ivbc_recalc');
        $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
        if ($order_id) {
            Ivbc_Orders::sync($order_id);
        }
        wp_safe_redirect(wp_get_referer() ?: self::url());
        exit;
    }

    public static function export_csv() {
        if (!current_user_can(self::CAP)) {
            wp_die('No tienes permiso.');
        }
        check_admin_referer('ivbc_export');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="cartera-ivb-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array('id', 'cliente_id', 'correo', 'empresa', 'pedido', 'tipo', 'estado', 'importe', 'base', 'unidades', 'campana', 'nota', 'creado_gmt'), ';');
        foreach (Ivbc_Ledger::all_rows() as $r) {
            $u = get_userdata($r->user_id);
            fputcsv($out, array($r->id, $r->user_id, $u ? $u->user_email : '', $u ? get_user_meta($u->ID, 'billing_company', true) : '', $r->order_id, $r->type, $r->status,
                str_replace('.', ',', $r->amount), $r->base !== null ? str_replace('.', ',', $r->base) : '', $r->units, $r->campaign, $r->note, $r->created_gmt), ';');
        }
        fclose($out);
        exit;
    }

    /* ---------- caja en el pedido y ficha de usuario ---------- */

    public static function meta_box() {
        $screens = array('shop_order');
        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }
        add_meta_box('ivbc_order', 'Cartera IVB', array(__CLASS__, 'render_meta_box'), array_unique($screens), 'side', 'default');
    }

    public static function render_meta_box($post_or_order) {
        $order = ($post_or_order instanceof WC_Order) ? $post_or_order : wc_get_order($post_or_order->ID);
        if (!$order) {
            return;
        }
        $row  = Ivbc_Ledger::get_accrual($order->get_id());
        $eval = Ivbc_Rules::evaluate_order($order);
        echo '<p>Unidades que cuentan: <strong>' . (int) $eval['units'] . '</strong> de ' . (int) $eval['min'] . ' (sin material promocional ni regalos).<br>Base: ' . self::money($eval['base']) . '</p>';
        if ($row && $row->status !== 'void') {
            echo '<p>Acumulado en la cartera: <strong>' . self::money($row->amount) . '</strong></p>';
        } else {
            if (!empty($eval['blocked_by'])) {
                echo '<p>No acumula: lleva el código de descuento <strong>' . esc_html(strtoupper(implode(', ', $eval['blocked_by']))) . '</strong>.</p>';
            } else {
            echo '<p>' . ($eval['eligible'] ? 'Cumple las unidades, pero todavía no suma: el pedido tiene que estar procesando o completado (y dentro de campaña, con la cartera activa para este cliente).' : 'No acumula: no llega al mínimo de unidades.') . '</p>';
            }
        }
        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=ivbc_recalc&order_id=' . (int) $order->get_id()), 'ivbc_recalc')) . '">Recalcular</a></p>';
    }

    public static function profile_box($user) {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $b = Ivbc_Ledger::balance($user->ID);
        if ($b['confirmed'] == 0 && $b['pending'] == 0) {
            return;
        }
        echo '<h2>Cartera IVB</h2><table class="form-table"><tr><th>Saldo</th><td>' . self::money($b['confirmed']) . ' <span class="description">(pendiente: ' . self::money($b['pending']) . ')</span> '
            . '<a href="' . esc_url(self::url(array('user' => (int) $user->ID))) . '">Ver movimientos</a></td></tr></table>';
    }
}
