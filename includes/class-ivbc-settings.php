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
            // off = apagado | admins = solo administradores (pruebas) | all = todos los clientes
            'mode'         => 'admins',
            // En modo "admins" ignora las fechas de acumulación para poder probar antes del 16/10
            'test_mode'    => 1,
            'campaign'     => 'PREBLACK',
            'start'        => '2026-10-16',
            'end'          => '2026-10-31',
            'percent'      => 5,
            'min_units'    => 20,
            'redeem_limit' => 20,
            'expiry'       => '2026-12-04',
            'redeem_note'  => 'Inicio del canje: pendiente de confirmación.',
            // Códigos de descuento que NO bloquean la acumulación (el resto sí). Separados por comas.
            'allowed_coupons' => '2desctf',
        );
    }

    /** Códigos permitidos en minúsculas. */
    public static function allowed_coupons() {
        $raw = (string) self::get('allowed_coupons');
        $out = array();
        foreach (preg_split('/[\s,;]+/', strtolower($raw)) as $c) {
            if ($c !== '') {
                $out[] = $c;
            }
        }
        return $out;
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
        return array(
            'mode'         => $mode,
            'test_mode'    => empty($in['test_mode']) ? 0 : 1,
            'campaign'     => isset($in['campaign']) ? sanitize_text_field($in['campaign']) : $d['campaign'],
            'start'        => $date(isset($in['start']) ? $in['start'] : '', $d['start']),
            'end'          => $date(isset($in['end']) ? $in['end'] : '', $d['end']),
            'percent'      => isset($in['percent']) ? max(0, min(100, (float) str_replace(',', '.', $in['percent']))) : $d['percent'],
            'min_units'    => isset($in['min_units']) ? max(1, (int) $in['min_units']) : $d['min_units'],
            'redeem_limit' => isset($in['redeem_limit']) ? max(0, min(100, (float) str_replace(',', '.', $in['redeem_limit']))) : $d['redeem_limit'],
            'expiry'       => $date(isset($in['expiry']) ? $in['expiry'] : '', $d['expiry']),
            'redeem_note'  => isset($in['redeem_note']) ? sanitize_text_field($in['redeem_note']) : $d['redeem_note'],
            'allowed_coupons' => isset($in['allowed_coupons']) ? strtolower(sanitize_text_field($in['allowed_coupons'])) : $d['allowed_coupons'],
        );
    }
}
