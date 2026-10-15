<?php
/**
 * Reglas de la campaña: quién participa, cuándo y qué pedidos acumulan.
 *
 * Un pedido acumula si tiene al menos N unidades (por defecto 20) que no sean material promocional
 * ni regalos. Se acumula el porcentaje (por defecto 5 %) del importe de esas líneas, sin IVA y sin envío.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Ivbc_Rules {

    /** Capacidad que se exige en modo "admins" (solo administradores). */
    const ADMIN_CAP = 'manage_options';

    private static $promo_cache = array();

    public static function mode() {
        $m = Ivbc_Settings::get('mode');
        return in_array($m, array('off', 'admins', 'all'), true) ? $m : 'admins';
    }

    /** ¿Está la cartera activa para este usuario? */
    public static function enabled_for_user($user_id) {
        $user_id = (int) $user_id;
        if (!$user_id) {
            return false;
        }
        $mode = self::mode();
        if ($mode === 'off') {
            return false;
        }
        if ($mode === 'all') {
            return true;
        }
        return user_can($user_id, self::ADMIN_CAP);
    }

    /** ¿Cae la fecha dentro del periodo de acumulación? En modo prueba, los administradores lo ignoran. */
    public static function in_window($timestamp, $user_id) {
        if (Ivbc_Settings::get('test_mode') && user_can((int) $user_id, self::ADMIN_CAP)) {
            return true;
        }
        $start = Ivbc_Settings::ts((string) Ivbc_Settings::get('start'));
        $end   = Ivbc_Settings::ts((string) Ivbc_Settings::get('end'), true);
        return $timestamp >= $start && $timestamp <= $end;
    }

    /** ¿Es un producto de material promocional? */
    public static function is_promo_product_id($product_id) {
        $product_id = (int) $product_id;
        if (!$product_id) {
            return false;
        }
        if (isset(self::$promo_cache[$product_id])) {
            return self::$promo_cache[$product_id];
        }
        $is = get_post_meta($product_id, '_mpbob_is_promotional', true) === 'yes'
            || has_term('material-promocional', 'product_cat', $product_id);
        self::$promo_cache[$product_id] = (bool) apply_filters('ivbc_is_promo_product', $is, $product_id);
        return self::$promo_cache[$product_id];
    }

    /** Resultado estándar a partir de unidades e importe base. */
    public static function result($units, $base) {
        $min = (int) Ivbc_Settings::get('min_units');
        $pct = (float) Ivbc_Settings::get('percent');
        $ok  = $units >= $min;
        return array(
            'units'    => (int) $units,
            'base'     => round((float) $base, 2),
            'min'      => $min,
            'pct'      => $pct,
            'eligible' => $ok,
            'missing'  => max(0, $min - (int) $units),
            'amount'   => $ok ? round($base * $pct / 100, 2) : 0.0,
        );
    }

    /** Evalúa un pedido. */
    public static function evaluate_order($order) {
        $units = 0;
        $base  = 0.0;
        foreach ($order->get_items('line_item') as $item) {
            $qty   = (int) $item->get_quantity();
            $total = (float) $item->get_total();
            if ($qty <= 0 || $total <= 0) {
                continue; // regalos y unidades gratis (10+2)
            }
            if ($item->get_meta('_mpbob_source') !== '' || $item->get_meta('_ivb_promo') !== '') {
                continue;
            }
            if (self::is_promo_product_id($item->get_product_id())) {
                continue;
            }
            $units += $qty;
            $base  += $total;
        }
        return self::result($units, $base);
    }

    /** Evalúa el carrito actual. */
    public static function evaluate_cart($cart) {
        $units = 0;
        $base  = 0.0;
        foreach ($cart->get_cart() as $ci) {
            $qty   = (int) $ci['quantity'];
            $total = isset($ci['line_total']) ? (float) $ci['line_total'] : 0.0;
            if ($qty <= 0 || $total <= 0) {
                continue;
            }
            $promo = false;
            foreach (array_keys($ci) as $k) {
                if (strpos($k, 'mpbob') === 0 || strpos($k, '_mpbob') === 0 || strpos($k, '_ivb_promo') === 0) {
                    $promo = true;
                    break;
                }
            }
            if ($promo || self::is_promo_product_id($ci['product_id'])) {
                continue;
            }
            $units += $qty;
            $base  += $total;
        }
        return self::result($units, $base);
    }
}
