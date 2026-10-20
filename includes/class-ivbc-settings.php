<?php
/**
 * Ajustes de la cartera (opción ivbc_settings).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Ivbc_Settings {

    const OPTION = 'ivbc_settings';

    public static function defaults() {
        return array(
            // off = apagada | admins = solo administradores (pruebas) | all = todos los clientes
            'mode'         => 'admins',
            // En modo "admins" ignora las fechas de acumulación para poder probar fuera de campaña
            'test_mode'    => 1,
            // Interruptores independientes: acumular saldo con los pedidos y gastarlo como descuento en el carrito
            'earn'         => 1,
            'spend'        => 0,
            // Primer día en que se puede gastar (vacío = ya) y último (fecha límite de uso)
            'redeem_start' => '',
            'campaign'     => 'PREBLACK',
            'start'        => '2026-10-21',
            'end'          => '2026-11-15',
            'percent'      => 5,
            'min_units'    => 20,
            'redeem_limit' => 20,
            'expiry'       => '2026-12-04',
            // Fechas distintas por rol: el primer grupo cuyo rol tenga el cliente manda; si no encaja en ninguno, valen las fechas generales de arriba
            'groups'       => array(
                array('label' => '20desc, Canarias y 17.5desc', 'roles' => '20desc, canarias, 175desc', 'start' => '2026-10-21', 'end' => '2026-11-01', 'redeem_start' => '2026-11-02', 'expiry' => '2026-12-04'),
                array('label' => '15desc, Baleares y Ceuta-Melilla', 'roles' => '15desc, baleares, ceuta-melilla', 'start' => '2026-10-21', 'end' => '2026-11-04', 'redeem_start' => '2026-11-05', 'expiry' => '2026-12-04'),
                array('label' => '10desc', 'roles' => '10desc', 'start' => '2026-10-21', 'end' => '2026-11-09', 'redeem_start' => '2026-11-10', 'expiry' => '2026-12-04'),
            ),
        );
    }

    /** Roles de un grupo como lista de slugs. */
    public static function group_roles($group) {
        return array_values(array_filter(array_map('sanitize_key', preg_split('/[\s,;]+/', (string) ($group['roles'] ?? '')))));
    }

    /**
     * Fechas que valen para un cliente (por defecto el actual): start/end = acumula, redeem_start/expiry = gasta.
     * @return array{start:string,end:string,redeem_start:string,expiry:string}
     */
    public static function dates($user_id = null) {
        $all   = self::all();
        $uid   = $user_id === null ? get_current_user_id() : (int) $user_id;
        $user  = $uid ? get_userdata($uid) : false;
        $roles = $user ? (array) $user->roles : array();
        foreach ((array) $all['groups'] as $g) {
            if (array_intersect(self::group_roles($g), $roles)) {
                return array('start' => (string) $g['start'], 'end' => (string) $g['end'], 'redeem_start' => (string) $g['redeem_start'], 'expiry' => (string) $g['expiry']);
            }
        }
        return array('start' => (string) $all['start'], 'end' => (string) $all['end'], 'redeem_start' => (string) $all['redeem_start'], 'expiry' => (string) $all['expiry']);
    }

    /** Una de las cuatro fechas (start, end, redeem_start, expiry) para un cliente (por defecto el actual). */
    public static function date($key, $user_id = null) {
        $d = self::dates($user_id);
        return isset($d[$key]) ? $d[$key] : '';
    }

    public static function all() {
        $saved = get_option(self::OPTION, array());
        $all   = wp_parse_args(is_array($saved) ? $saved : array(), self::defaults());
        return apply_filters('ivbc_settings', $all);
    }

    public static function get($key) {
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    /** Marca de tiempo de una fecha Y-m-d en la zona horaria del sitio. */
    public static function ts($date, $end_of_day = false) {
        try {
            $d = new DateTimeImmutable($date . ($end_of_day ? ' 23:59:59' : ' 00:00:00'), wp_timezone());
            return $d->getTimestamp();
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function sanitize(array $in) {
        $d = self::defaults();
        $mode = isset($in['mode']) ? sanitize_key($in['mode']) : $d['mode'];
        if (!in_array($mode, array('off', 'admins', 'all'), true)) {
            $mode = 'admins';
        }
        $date = function ($v, $fallback) {
            return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : $fallback;
        };
        $groups = array();
        foreach (isset($in['groups']) && is_array($in['groups']) ? $in['groups'] : array() as $g) {
            if (!is_array($g) || trim((string) ($g['roles'] ?? '')) === '') {
                continue;
            }
            $groups[] = array(
                'label'        => sanitize_text_field($g['label'] ?? ''),
                'roles'        => implode(', ', self::group_roles($g)),
                'start'        => $date($g['start'] ?? '', $d['start']),
                'end'          => $date($g['end'] ?? '', $d['end']),
                'redeem_start' => $date($g['redeem_start'] ?? '', ''),
                'expiry'       => $date($g['expiry'] ?? '', $d['expiry']),
            );
        }
        return array(
            'groups'       => $groups,
            'mode'         => $mode,
            'test_mode'    => empty($in['test_mode']) ? 0 : 1,
            'earn'         => empty($in['earn']) ? 0 : 1,
            'spend'        => empty($in['spend']) ? 0 : 1,
            'redeem_start' => $date(isset($in['redeem_start']) ? $in['redeem_start'] : '', ''),
            'campaign'     => isset($in['campaign']) ? sanitize_text_field($in['campaign']) : $d['campaign'],
            'start'        => $date(isset($in['start']) ? $in['start'] : '', $d['start']),
            'end'          => $date(isset($in['end']) ? $in['end'] : '', $d['end']),
            'percent'      => isset($in['percent']) ? max(0, min(100, (float) str_replace(',', '.', $in['percent']))) : $d['percent'],
            'min_units'    => isset($in['min_units']) ? max(1, (int) $in['min_units']) : $d['min_units'],
            'redeem_limit' => isset($in['redeem_limit']) ? max(0, min(100, (float) str_replace(',', '.', $in['redeem_limit']))) : $d['redeem_limit'],
            'expiry'       => $date(isset($in['expiry']) ? $in['expiry'] : '', $d['expiry']),
        );
    }
}
