<?php
/**
 * Reglas de la campaña: quién participa, cuándo y qué pedidos acumulan.
 *
 * Un pedido acumula si tiene al menos N unidades (por defecto 20) que no sean material promocional
 * ni regalos. Se acumula el porcentaje (por defecto 5 %) del importe de esas líneas, con IVA y recargo de equivalencia (si lo tiene) y sin envío.
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

    /** ¿Está activo el interruptor de acumular? */
    public static function earn_on() {
        return !empty(Ivbc_Settings::get('earn'));
    }

    /** ¿Está activo el interruptor de gastar? */
    public static function spend_on() {
        return !empty(Ivbc_Settings::get('spend'));
    }

    /** ¿Puede este usuario gastar saldo ahora? Interruptor activo, cartera visible para él y dentro de las fechas de uso. */
    public static function spend_open($user_id, $now = null) {
        if (!self::spend_on() || !self::enabled_for_user($user_id)) {
            return false;
        }
        $now   = $now === null ? time() : (int) $now;
        $start = (string) Ivbc_Settings::get('redeem_start');
        $end   = (string) Ivbc_Settings::get('expiry');
        if ($start !== '' && $now < Ivbc_Settings::ts($start)) {
            return false;
        }
        return $end === '' || $now <= Ivbc_Settings::ts($end, true);
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


    /* ---------- IVA: el saldo se gana y se gasta sobre el importe CON IVA y recargo de equivalencia (si lo tiene) ---------- */

    /** ¿Es el impuesto «recargo de equivalencia» (R.E.)? Nunca entra en la cartera. */
    public static function is_re_rate($rate_id) {
        static $cache = array();
        $rate_id = (int) $rate_id;
        if (!isset($cache[$rate_id])) {
            $r = class_exists('WC_Tax') ? WC_Tax::_get_tax_rate($rate_id, ARRAY_A) : null;
            $name  = is_array($r) ? (string) $r['tax_rate_name'] : '';
            $class = is_array($r) ? (string) $r['tax_rate_class'] : '';
            $cache[$rate_id] = (bool) preg_match('/^\s*r\.?\s*e\.?\s*$/i', $name) || (substr($class, -3) === '-re' && is_array($r) && (int) $r['tax_rate_priority'] > 1);
        }
        return $cache[$rate_id];
    }

    /** Suma de impuestos por tipo (id => importe) sin el recargo de equivalencia. */
    public static function vat_only($taxes) {
        $sum = 0.0;
        foreach ((array) $taxes as $rate_id => $amount) {
            if (!self::is_re_rate($rate_id)) {
                $sum += (float) $amount;
            }
        }
        return $sum;
    }


    /** Fracción de impuestos (IVA + R.E. si el cliente lo tiene) de una clase de impuesto para el cliente actual, p. ej. 0,262. */
    public static function vat_fraction($tax_class) {
        if (!class_exists('WC_Tax') || !function_exists('WC')) {
            return 0.0;
        }
        $rates = WC_Tax::get_rates((string) $tax_class, WC()->customer);
        $f = 0.0;
        foreach ($rates as $r) {
            $f += (float) $r['rate'] / 100;
        }
        return $f;
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
            $base  += $total + array_sum((array) ($item->get_taxes()['total'] ?? array())); // con IVA y recargo de equivalencia (si lo tiene)
        }
        return self::result($units, $base);
    }

    /**
     * Importe (con IVA, antes de cupones) de las líneas del carrito sobre las que se puede gastar saldo: las mismas que cuentan para acumular
     * (sin material promocional ni líneas a 0 €). Se calcula con el precio de cada producto porque se consulta mientras se calculan los totales.
     */
    public static function cart_spend_base($cart) {
        $r = self::cart_spend_lines($cart);
        return $r['base'];
    }

    /** Base (con IVA) e ids de producto de las líneas sobre las que se puede gastar saldo. @return array{base:float,ids:int[]} */
    public static function cart_spend_lines($cart) {
        $base = 0.0;
        $ids  = array();
        foreach ($cart->get_cart() as $ci) {
            $qty = (int) $ci['quantity'];
            if ($qty <= 0 || empty($ci['data']) || !is_object($ci['data'])) {
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
            $line = (float) wc_get_price_excluding_tax($ci['data'], array('qty' => $qty));
            $line = $line * (1 + self::vat_fraction($ci['data']->get_tax_class())); // con IVA y recargo de equivalencia (si lo tiene)
            if ($line > 0) {
                $base += $line;
                $ids[(int) $ci['product_id']] = (int) $ci['product_id'];
            }
        }
        return array('base' => round($base, 2), 'ids' => array_values($ids));
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
            $base  += $total + array_sum(isset($ci['line_tax_data']['total']) ? (array) $ci['line_tax_data']['total'] : array()); // con IVA y recargo de equivalencia (si lo tiene)
        }
        return self::result($units, $base);
    }
}
