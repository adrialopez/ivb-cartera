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
        if (!$user_id || !Ivbc_Rules::enabled_for_user($user_id)) {
            return null;
        }

        self::$running = true;
        try {
            $order_id = (int) $order->get_id();
            $created  = $order->get_date_created();
            $ts       = $created ? $created->getTimestamp() : time();
            $eval     = Ivbc_Rules::evaluate_order($order);

            // Reembolsos parciales: se descuenta lo devuelto de la base
            $refunded = max(0.0, (float) $order->get_total_refunded() - (float) $order->get_total_tax_refunded() - (float) $order->get_total_shipping_refunded());
            if ($refunded > 0) {
                $base           = max(0.0, $eval['base'] - $refunded);
                $eval['base']   = round($base, 2);
                $eval['amount'] = $eval['eligible'] ? round($base * $eval['pct'] / 100, 2) : 0.0;
            }

            $status = self::target_status($order);
            if ($status === 'wait') {
                // Sin pago confirmado no hay saldo. Si el pedido vuelve atrás desde procesando, se retira.
                Ivbc_Ledger::void_accrual($order_id);
                $result = array('state' => 'wait', 'eval' => $eval);
            } elseif ($status === 'void' || !Ivbc_Rules::in_window($ts, $user_id) || !$eval['eligible'] || $eval['amount'] <= 0) {
                Ivbc_Ledger::void_accrual($order_id);
                $result = array('state' => 'void', 'eval' => $eval);
            } else {
                Ivbc_Ledger::save_accrual($order_id, $user_id, $status, $eval['amount'], $eval['base'], $eval['units'], (string) Ivbc_Settings::get('campaign'));
                $result = array('state' => $status, 'eval' => $eval);
            }
        } finally {
            self::$running = false;
        }
        return $result;
    }
}
