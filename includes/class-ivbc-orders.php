<?php
/**
 * Ciclo de vida de los pedidos: crea, confirma o anula el apunte de acumulación.
 *
 * - Pedido procesando o completado           -> apunte "confirmed" (se suma al saldo)
 * - Pedido pendiente de pago o en espera      -> sin apunte (si lo había, se anula: el pago no está confirmado)
 * - Pedido cancelado, fallido o reembolsado   -> apunte "void"
 * Es idempotente: se puede llamar tantas veces como se quiera.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Ivbc_Orders {

    private static $running = false;

    public static function init() {
        add_action('woocommerce_checkout_create_order', array(__CLASS__, 'store_intended'), 20, 2);
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'sync'), 40);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'sync'), 40);
        add_action('woocommerce_update_order', array(__CLASS__, 'sync'), 40);
        add_action('woocommerce_order_status_changed', array(__CLASS__, 'sync'), 40);
        add_action('woocommerce_order_refunded', array(__CLASS__, 'sync'), 40);
    }

    public static function target_status($order) {
        $s = $order->get_status();
        if (in_array($s, array('processing', 'completed'), true)) {
            return 'confirmed';
        }
        if (in_array($s, array('pending', 'on-hold'), true)) {
            return 'wait';
        }
        return 'void';
    }

    /**
     * Sincroniza el apunte de un pedido. Acepta ID o instancia. Devuelve un array con el resultado (útil para pruebas).
     */
    public static function sync($order_or_id) {
        if (self::$running) {
            return null;
        }
        $order = is_object($order_or_id) ? $order_or_id : wc_get_order($order_or_id);
        if (!$order || !($order instanceof WC_Order) || $order instanceof WC_Order_Refund) {
            return null;
        }
        if (in_array($order->get_status(), array('auto-draft', 'draft', 'checkout-draft', 'trash'), true)) {
            return null;
        }
        $user_id = (int) $order->get_customer_id();
        if (!$user_id) {
            return null;
        }
        self::$running = true;
        try {
            self::sync_redeem($order, $user_id);
            return self::sync_accrual($order, $user_id);
        } finally {
            self::$running = false;
        }
    }

    /**
     * Saldo gastado en el pedido (cupón «ivbc-cartera»): se descuenta del saldo mientras el pedido siga vivo y se devuelve si se cancela o falla.
     * Funciona aunque la cartera ya no esté visible: el saldo siempre tiene que cuadrar con los pedidos.
     */
    /** Importe con IVA que el carrito quería gastar al hacer el pedido (el cupón puede quedar 1 céntimo por debajo por el redondeo de WooCommerce). */
    public static function store_intended($order, $data = null) {
        if (!function_exists('WC') || !WC()->cart || !WC()->cart->has_discount(Ivbc_Spend::CODE)) {
            return;
        }
        $uid = (int) $order->get_customer_id();
        $amt = $uid ? Ivbc_Spend::amount_for_cart(WC()->cart, $uid) : 0.0;
        if ($amt > 0) {
            $order->update_meta_data('_ivbc_intended', round($amt, 2));
        }
    }

    private static function sync_redeem($order, $user_id) {
        $order_id = (int) $order->get_id();
        $amount   = 0.0;
        foreach ($order->get_items('coupon') as $item) {
            if (strtolower((string) $item->get_code()) === Ivbc_Spend::CODE) {
                $amount += (float) $item->get_discount();
            }
        }
        // El saldo se gasta CON IVA (como se gana): descuento sin IVA + el impuesto del descuento tal como lo registra WooCommerce (el que ve el cliente en el
        // resumen). Así el apunte coincide con la línea «Cartera IVB» del pedido.
        if ($amount > 0) {
            $disc_tax = 0.0;
            foreach ($order->get_items('coupon') as $item) {
                if (strtolower((string) $item->get_code()) === Ivbc_Spend::CODE) {
                    $disc_tax += (float) $item->get_discount_tax();
                }
            }
            if ((string) $order->get_meta('_ivbc_discount_ex') !== (string) round($amount, 2)) {
                $order->update_meta_data('_ivbc_discount_ex', round($amount, 2)); // descuento sin IVA tal como está en el pedido (para recalcular desde el administrador)
                $order->save_meta_data();
            }
            $amount = round($amount + $disc_tax, 2);
            // Redondeo: si el cupón quedó a ≤ 2 céntimos de lo que se quería gastar, se apunta lo previsto: gastar todo el saldo lo deja en 0, sin céntimos sueltos
            $intended = (float) $order->get_meta('_ivbc_intended');
            if ($intended > 0 && abs($intended - $amount) <= 0.02) {
                $amount = round($intended, 2);
            }
        }
        $existing = Ivbc_Ledger::get_redeem($order_id);
        if ($amount > 0.004) {
            if (in_array($order->get_status(), array('cancelled', 'failed', 'refunded', 'trash'), true)) {
                Ivbc_Ledger::void_redeem($order_id);
            } else {
                Ivbc_Ledger::save_redeem($order_id, $user_id, 'confirmed', $amount);
            }
        } elseif ($existing && $existing->status !== 'void') {
            Ivbc_Ledger::void_redeem($order_id);
        }
    }

    /** Apunte de acumulación del pedido (ver las reglas en la cabecera de la clase). */
    private static function sync_accrual($order, $user_id) {
        if (!Ivbc_Rules::enabled_for_user($user_id)) {
            return null;
        }
        $order_id = (int) $order->get_id();
        // con «Ganar saldo» desactivado no se crean acumulaciones nuevas, pero las que ya existen se siguen anulando si el pedido se cancela o se reembolsa
        if (!Ivbc_Rules::earn_on() && !Ivbc_Ledger::get_accrual($order_id)) {
            return null;
        }
        $created = $order->get_date_created();
        $ts      = $created ? $created->getTimestamp() : time();
        $eval    = Ivbc_Rules::evaluate_order($order);

        // Reembolsos parciales: se descuenta lo devuelto de la base
        $refunded = 0.0; // productos devueltos con IVA y recargo de equivalencia (sin envío)
        foreach ($order->get_refunds() as $rf) {
            foreach ($rf->get_items('line_item') as $ri) {
                $refunded += abs((float) $ri->get_total()) + abs(array_sum((array) ($ri->get_taxes()['total'] ?? array())));
            }
        }
        if ($refunded > 0) {
            $base           = max(0.0, $eval['base'] - $refunded);
            $eval['base']   = round($base, 2);
            $eval['amount'] = $eval['eligible'] ? round($base * $eval['pct'] / 100, 2) : 0.0;
        }

        $status = self::target_status($order);
        if ($status === 'wait') {
            // Sin pago confirmado no hay saldo. Si el pedido vuelve atrás desde procesando, se retira.
            Ivbc_Ledger::void_accrual($order_id);
            return array('state' => 'wait', 'eval' => $eval);
        }
        if ($status === 'void' || !Ivbc_Rules::in_window($ts, $user_id) || !$eval['eligible'] || $eval['amount'] <= 0) {
            Ivbc_Ledger::void_accrual($order_id);
            return array('state' => 'void', 'eval' => $eval);
        }
        Ivbc_Ledger::save_accrual($order_id, $user_id, $status, $eval['amount'], $eval['base'], $eval['units'], (string) Ivbc_Settings::get('campaign'));
        return array('state' => $status, 'eval' => $eval);
    }
}
