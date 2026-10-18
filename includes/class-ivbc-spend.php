<?php
/**
 * Gastar saldo de la cartera: un cupón virtual de importe fijo («ivbc-cartera») que el carrito aplica solo.
 *
 * - Importe = lo menor entre el saldo disponible y el porcentaje máximo por pedido (por defecto 20 %) del importe de los productos
 *   (con IVA, antes de cupones, sin material promocional). Es un descuento sobre la base imponible, repartido por líneas por WooCommerce
 *   (así el IVA de cada tipo baja en proporción).
 * - El cliente puede no usarlo en un pedido («No usar mi cartera en este pedido»); se recuerda en la sesión de compra. Quitar el cupón con la «x» o por URL NO cuenta como no usarlo: se vuelve a aplicar.
 * - Al hacer el pedido, el importe se descuenta del saldo (apunte «redeem»); si el pedido se cancela o falla, se devuelve (ver Ivbc_Orders).
 * - Solo funciona con el interruptor «Gastar saldo» activo, dentro de las fechas de uso y para quien ve la cartera.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Ivbc_Spend {

    const CODE        = 'ivbc-cartera';
    const SESSION_OFF = 'ivbc_wallet_off';
    const NONCE       = 'ivbc_wallet';

    private static $busy = false;

    public static function init() {
        add_filter('woocommerce_get_shop_coupon_data', array(__CLASS__, 'coupon_data'), 10, 3);
        add_filter('woocommerce_coupon_is_valid', array(__CLASS__, 'coupon_valid'), 10, 2);
        add_filter('woocommerce_cart_totals_coupon_label', array(__CLASS__, 'coupon_label'), 10, 2);
        add_filter('woocommerce_coupon_message', array(__CLASS__, 'coupon_message'), 10, 3);
        add_filter('woocommerce_cart_totals_coupon_html', array(__CLASS__, 'hide_remove_link'), 10, 2); // el descuento de la cartera no se quita con «Eliminar»: solo con «No usar mi cartera en este pedido»
        add_action('woocommerce_cart_emptied', array(__CLASS__, 'reset_choice')); // al terminar un pedido (o vaciar el carrito) vuelve a aplicarse solo

        // aplicar o retirar solo cuando cambia el carrito
        add_action('woocommerce_cart_loaded_from_session', array(__CLASS__, 'sync'), 30);
        add_action('woocommerce_add_to_cart', array(__CLASS__, 'sync'), 99);
        add_action('woocommerce_cart_item_removed', array(__CLASS__, 'sync'), 99);
        add_action('woocommerce_after_cart_item_quantity_update', array(__CLASS__, 'sync'), 99);
        add_action('woocommerce_before_cart', array(__CLASS__, 'sync'), 5);
        add_action('woocommerce_before_checkout_form', array(__CLASS__, 'sync'), 5);
        add_action('woocommerce_checkout_update_order_review', array(__CLASS__, 'sync'), 99);

        add_action('template_redirect', array(__CLASS__, 'handle_toggle'), 5);
        add_action('woocommerce_before_cart', array(__CLASS__, 'echo_notice'), 12);
        add_action('woocommerce_before_checkout_form', array(__CLASS__, 'echo_notice'), 21);
        add_filter('woocommerce_update_order_review_fragments', array(__CLASS__, 'fragment'));
        add_filter('woocommerce_add_to_cart_fragments', array(__CLASS__, 'fragment'));
    }

    /* ---------- cálculo ---------- */

    private static function user_id() {
        return is_user_logged_in() ? get_current_user_id() : 0;
    }

    /** Saldo disponible para gastar (nunca negativo). */
    public static function available($user_id) {
        $b = Ivbc_Ledger::balance((int) $user_id);
        return max(0.0, (float) $b['confirmed']);
    }

    /** Porcentaje máximo del importe de los productos que se puede pagar con saldo. */
    public static function limit_pct() {
        return max(0.0, min(100.0, (float) Ivbc_Settings::get('redeem_limit')));
    }

    /** Importe que se puede descontar ahora en el carrito (0 si no procede). */
    public static function amount_for_cart($cart, $user_id) {
        if (!$cart || !$user_id || !Ivbc_Rules::spend_open($user_id)) {
            return 0.0;
        }
        $avail = self::available($user_id);
        if ($avail <= 0) {
            return 0.0;
        }
        $base = Ivbc_Rules::cart_spend_base($cart);
        $max  = round($base * self::limit_pct() / 100, 2);
        return round(max(0.0, min($avail, $max)), 2);
    }

    /** Descuento en %, sobre las líneas elegibles: así WooCommerce lo reparte por importe (el IVA de cada tipo baja en proporción). */
    public static function percent_for_cart($cart, $user_id) {
        $amount = self::amount_for_cart($cart, $user_id);
        $base   = Ivbc_Rules::cart_spend_base($cart);
        return ($amount > 0 && $base > 0) ? round(min(100.0, $amount / $base * 100), 4) : 0.0;
    }

    public static function opted_out() {
        return function_exists('WC') && WC()->session && (int) WC()->session->get(self::SESSION_OFF, 0) === 1;
    }

    /* ---------- cupón virtual ---------- */

    public static function coupon_data($data, $code, $coupon = null) {
        if (strtolower((string) $code) !== self::CODE) {
            return $data;
        }
        $pct = 0.0;
        $ids = array();
        if (is_admin() && !empty($_POST['order_id'])) { // phpcs:ignore WordPress.Security.NonceVerification
            // recálculo de un pedido en el administrador: se mantiene lo que se gastó en ese pedido (fijo, sobre todo el pedido)
            $row = Ivbc_Ledger::get_redeem((int) $_POST['order_id']); // phpcs:ignore WordPress.Security.NonceVerification
            $ord = wc_get_order((int) $_POST['order_id']); // phpcs:ignore WordPress.Security.NonceVerification
            $ex  = $ord ? (float) $ord->get_meta('_ivbc_discount_ex') : 0.0; // el apunte está CON IVA; el cupón fijo se aplica sin IVA
            return array(
                'discount_type' => 'fixed_cart', 'amount' => ($row && $row->status === 'confirmed') ? ($ex > 0 ? $ex : abs((float) $row->amount)) : 0.0,
                'individual_use' => false, 'usage_limit' => 0, 'free_shipping' => false, 'exclude_sale_items' => false, 'description' => 'Saldo de la cartera IVB',
            );
        }
        if (function_exists('WC') && WC()->cart) {
            $pct = self::percent_for_cart(WC()->cart, self::user_id());
            $ids = Ivbc_Rules::cart_spend_lines(WC()->cart)['ids'];
        }
        return array(
            'discount_type'      => 'percent',
            'amount'             => $pct,
            'product_ids'        => $ids,
            'individual_use'     => false,
            'usage_limit'        => 0,
            'free_shipping'      => false,
            'exclude_sale_items' => false,
            'description'        => 'Saldo de la cartera IVB',
        );
    }

    public static function coupon_valid($valid, $coupon) {
        if ($valid && is_object($coupon) && strtolower((string) $coupon->get_code()) === self::CODE) {
            return (float) $coupon->get_amount() > 0;
        }
        return $valid;
    }

    public static function coupon_label($label, $coupon) {
        return (is_object($coupon) && strtolower((string) $coupon->get_code()) === self::CODE) ? 'Cartera IVB' : $label;
    }

    public static function coupon_message($msg, $code, $coupon) {
        return (is_object($coupon) && strtolower((string) $coupon->get_code()) === self::CODE) ? '' : $msg;
    }

    /** Sin enlace «Eliminar» en el descuento de la cartera: si se quitara, volvería a aplicarse solo; la forma de no usarla es el enlace del aviso. */
    public static function hide_remove_link($html, $coupon) {
        if (is_object($coupon) && strtolower((string) $coupon->get_code()) === self::CODE) {
            return preg_replace('/\s*<a [^>]*woocommerce-remove-coupon[^>]*>.*?<\/a>/is', '', (string) $html);
        }
        return $html;
    }

    /** Olvida la decisión de «no usar la cartera»: vale para un pedido, no para todos. */
    public static function reset_choice() {
        if (function_exists('WC') && WC()->session && WC()->session->get(self::SESSION_OFF, null) !== null) {
            WC()->session->set(self::SESSION_OFF, null);
        }
    }

    /** Aplica o retira el descuento según corresponda. */
    public static function sync() {
        if (self::$busy || !function_exists('WC') || !WC()->cart) {
            return;
        }
        if (WC()->cart->is_empty()) {
            self::reset_choice();
        }
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        $cart   = WC()->cart;
        $uid    = self::user_id();
        $has    = $cart->has_discount(self::CODE);
        $should = $uid && !self::opted_out() && self::amount_for_cart($cart, $uid) > 0;
        if ($should === $has) {
            return;
        }
        self::$busy = true;
        try {
            if ($should) {
                $cart->apply_coupon(self::CODE);
            } else {
                $cart->remove_coupon(self::CODE);
            }
            $cart->calculate_totals();
        } finally {
            self::$busy = false;
        }
    }

    /** Enlace «Usar mi cartera» / «No usar en este pedido». */
    public static function handle_toggle() {
        if (!isset($_GET['ivbc_wallet']) || !is_user_logged_in() || !function_exists('WC') || !WC()->session) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::NONCE)) {
            return;
        }
        $off = sanitize_key(wp_unslash($_GET['ivbc_wallet'])) === 'off';
        WC()->session->set(self::SESSION_OFF, $off ? 1 : 0);
        self::sync();
        wp_safe_redirect(remove_query_arg(array('ivbc_wallet', '_wpnonce')));
        exit;
    }

    /* ---------- aviso de carrito y checkout ---------- */

    /**
     * Página del carrito o del checkout a la que vuelve el enlace «Usar / No usar mi cartera». El aviso también se pinta dentro de peticiones AJAX
     * (fragmentos del carrito: ?wc-ajax=…): ahí la URL actual NO es una página, así que se usa la de donde viene la petición.
     */
    private static function page_url() {
        $cur = (function_exists('wp_doing_ajax') && wp_doing_ajax()) || isset($_GET['wc-ajax']); // phpcs:ignore WordPress.Security.NonceVerification
        if ($cur) {
            $ref = wp_get_referer();
            $host = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($ref && wp_parse_url($ref, PHP_URL_HOST) === $host) {
                return remove_query_arg(array('ivbc_wallet', '_wpnonce', 'wc-ajax'), $ref);
            }
            return function_exists('is_checkout') && is_checkout() ? wc_get_checkout_url() : wc_get_cart_url();
        }
        return remove_query_arg(array('ivbc_wallet', '_wpnonce', 'wc-ajax'));
    }

    public static function notice_html() {
        $uid = self::user_id();
        $empty = '<div id="ivbc-spend-notice"></div>';
        if (!$uid || !function_exists('WC') || !WC()->cart || WC()->cart->is_empty() || !Ivbc_Rules::spend_open($uid)) {
            return $empty;
        }
        $avail = self::available($uid);
        if ($avail <= 0) {
            return $empty;
        }
        $cart    = WC()->cart;
        $pct     = rtrim(rtrim(number_format(self::limit_pct(), 2, ',', ''), '0'), ',');
        $expiry  = Ivbc_Frontend::date_es(Ivbc_Settings::get('expiry'));
        $applied = $cart->has_discount(self::CODE);
        $link    = function ($state, $label) {
            return '<a href="' . esc_url(wp_nonce_url(add_query_arg('ivbc_wallet', $state, self::page_url()), self::NONCE)) . '">' . esc_html($label) . '</a>';
        };
        if ($applied) {
            $used  = self::amount_for_cart($cart, $uid); // importe con IVA que se gasta de tu saldo
            $title = 'Se han descontado ' . wc_price($used) . ' de tu cartera IVB en este pedido';
            $text  = 'Es el máximo que se puede usar: el ' . esc_html($pct) . ' % del importe de tus productos (con IVA, sin material promocional)'
                . ($avail - $used > 0.004 ? ', y te quedan ' . wc_price(max(0.0, $avail - $used)) . ' para otros pedidos' : '') . '.'
                . (Ivbc_Frontend::window_text($expiry) !== '' ? ' Disponible' . Ivbc_Frontend::window_text($expiry) . '.' : '');
            $act   = '<span class="ivbc-notice__bal">' . $link('off', 'No usar mi cartera en este pedido') . '</span>';
        } elseif (self::opted_out()) {
            $title = 'Tienes ' . wc_price($avail) . ' en tu cartera IVB';
            $text  = 'No se está usando en este pedido.' . (Ivbc_Frontend::window_text($expiry) !== '' ? ' Disponible' . Ivbc_Frontend::window_text($expiry) . '.' : '');
            $act   = '<span class="ivbc-notice__bal">' . $link('on', 'Usar mi cartera en este pedido') . '</span>';
        } else {
            return $empty;
        }
        return '<div id="ivbc-spend-notice" class="ivbc-notice ivbc-notice--ok"><span class="ivbc-notice__ico">' . Ivbc_Frontend::icon('wallet') . '</span>'
            . '<div class="ivbc-notice__body"><strong class="ivbc-notice__title">' . $title . '</strong><span class="ivbc-notice__text">' . $text . '</span>' . $act . '</div></div>';
    }

    public static function echo_notice() {
        if (self::user_id() && Ivbc_Rules::spend_open(self::user_id())) {
            wp_enqueue_style('ivbc-frontend', IVBC_URL . 'assets/css/frontend.css', array(), IVBC_VERSION);
            echo self::notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    public static function fragment($fragments) {
        if (self::user_id() && Ivbc_Rules::spend_open(self::user_id())) {
            $fragments['#ivbc-spend-notice'] = self::notice_html();
        }
        return $fragments;
    }
}
