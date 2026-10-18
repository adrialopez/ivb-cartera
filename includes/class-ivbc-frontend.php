<?php
/**
 * Parte visible para el cliente: aviso en carrito y checkout, tarjeta en la página de gracias,
 * línea en el correo del pedido y sección "Mi cartera" en Mi cuenta.
 * Solo se muestra a los usuarios para los que la cartera está activa (ver Ivbc_Rules::enabled_for_user).
 */
if (!defined('ABSPATH')) {
    exit;
}

class Ivbc_Frontend {

    const ENDPOINT = 'mi-cartera';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_endpoint'));
        add_filter('query_vars', array(__CLASS__, 'query_vars'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));

        // Carrito: orden de avisos 10 acelerador, 11 tu escala, 12 cartera (este), 13 seguir comprando. Checkout: arriba del formulario (prioridad 20).
        // Nunca entre las columnas del checkout, porque rompe la maquetación del tema.
        add_action('woocommerce_before_cart', array(__CLASS__, 'echo_cart_notice'), 12);
        add_action('woocommerce_before_checkout_form', array(__CLASS__, 'echo_checkout_notice'), 20);
        add_filter('woocommerce_update_order_review_fragments', array(__CLASS__, 'checkout_fragment'));
        add_filter('woocommerce_add_to_cart_fragments', array(__CLASS__, 'checkout_fragment'));

        // Pedido realizado y correos
        add_action('woocommerce_thankyou', array(__CLASS__, 'thankyou_card'), 15);
        add_action('woocommerce_email_after_order_table', array(__CLASS__, 'email_line'), 20, 4);

        // Mi cuenta
        add_filter('woocommerce_account_menu_items', array(__CLASS__, 'menu_item'), 40);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', array(__CLASS__, 'render_account'));
        add_filter('woocommerce_endpoint_' . self::ENDPOINT . '_title', function () {
            return 'Mi cartera';
        });
        add_action('woocommerce_account_dashboard', array(__CLASS__, 'dashboard_card'), 5);
    }

    public static function register_endpoint() {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public static function query_vars($vars) {
        $vars[] = self::ENDPOINT;
        return $vars;
    }

    /* ---------- utilidades ---------- */

    private static function user_ok() {
        return is_user_logged_in() && Ivbc_Rules::enabled_for_user(get_current_user_id());
    }

    private static function campaign_open_now() {
        return self::user_ok() && Ivbc_Rules::earn_on() && Ivbc_Rules::in_window(time(), get_current_user_id());
    }

    /** Frase de «para qué sirve el saldo»: con «Gastar saldo» activo explica cómo se usa; si no, mantiene el texto de la campaña. */
    public static function use_text($expiry) {
        if (Ivbc_Rules::spend_on()) {
            return 'Podrás usarlo como descuento en tus pedidos' . self::window_text($expiry) . '.';
        }
        return 'Podrás usarlo como descuento en la campaña de Black Friday' . ($expiry ? ', hasta el ' . esc_html($expiry) : '') . '.';
    }

    /** «desde el X hasta el Y» (periodo en que se puede gastar); cada extremo solo si está definido. */
    public static function window_text($expiry = null) {
        $expiry = $expiry === null ? self::date_es(Ivbc_Settings::get('expiry')) : $expiry;
        $start  = (string) Ivbc_Settings::get('redeem_start');
        $from   = $start !== '' ? esc_html(self::date_es($start)) : '';
        return ($from ? ' desde el ' . $from : '') . ($expiry ? ($from ? ' hasta el ' : ' hasta el ') . esc_html($expiry) : '');
    }

    public static function date_es($ymd) {
        $ts = Ivbc_Settings::ts((string) $ymd);
        return $ts ? wp_date('j \d\e F \d\e Y', $ts) : '';
    }

    public static function icon($name) {
        $svg = array(
            'wallet' => '<path d="M3 7a2 2 0 0 1 2-2h12v3"/><path d="M3 7v10a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1v-9a1 1 0 0 0-1-1H5a2 2 0 0 1-2-2"/><circle cx="16.5" cy="13.5" r="1.2" fill="currentColor" stroke="none"/>',
            'bag'    => '<path d="M6 8h12l1 12H5L6 8z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/>',
            'plus'   => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
            'tag'    => '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="8" cy="9" r="1.3" fill="currentColor" stroke="none"/>',
            'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        );
        $p = isset($svg[$name]) ? $svg[$name] : '';
        return '<svg class="ivbc-svg" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
    }

    public static function enqueue() {
        if (!self::user_ok()) {
            return;
        }
        if (is_cart() || is_checkout() || is_account_page()) {
            wp_enqueue_style('ivbc-frontend', IVBC_URL . 'assets/css/frontend.css', array(), IVBC_VERSION);
        }
    }

    /* ---------- aviso de carrito y checkout ---------- */

    public static function notice_html($eval) {
        if (!$eval) {
            return '<div id="ivbc-notice"></div>';
        }
        $uid     = get_current_user_id();
        $balance = Ivbc_Ledger::balance($uid);
        $expiry  = self::date_es(Ivbc_Settings::get('expiry'));
        $pct     = rtrim(rtrim(number_format((float) $eval['pct'], 2, ',', ''), '0'), ',');

        if ($eval['eligible']) {
            $title = 'Este pedido acumula ' . wc_price($eval['amount']) . ' en tu cartera IVB';
            $text  = 'Es el ' . esc_html($pct) . ' % del importe de tus productos con IVA (sin material promocional). '
                . 'Se suma a tu cartera cuando confirmemos el pago del pedido. ' . self::use_text($expiry);
            $class = 'ivbc-notice ivbc-notice--ok';
            $bar   = '';
        } else {
            $title = 'Acumula el ' . esc_html($pct) . ' % de este pedido en tu cartera IVB';
            $text  = 'Te ' . ($eval['missing'] === 1 ? 'falta 1 unidad' : 'faltan ' . (int) $eval['missing'] . ' unidades')
                . ' para llegar a ' . (int) $eval['min'] . ' (el material promocional no cuenta).';
            $class = 'ivbc-notice';
            $w     = max(0, min(100, round($eval['units'] / max(1, $eval['min']) * 100)));
            $bar   = '<span class="ivbc-bar" role="img" aria-label="' . (int) $eval['units'] . ' de ' . (int) $eval['min'] . ' unidades"><span style="width:' . $w . '%"></span></span>'
                . '<span class="ivbc-bar-label">' . (int) $eval['units'] . ' de ' . (int) $eval['min'] . ' unidades</span>';
        }

        $bal = '';
        if ($balance['confirmed'] > 0) {
            $bal = '<span class="ivbc-notice__bal">Tu saldo acumulado: <strong>' . wc_price($balance['confirmed']) . '</strong>'
                . ' &middot; <a href="' . esc_url(wc_get_account_endpoint_url(self::ENDPOINT)) . '">Ver mi cartera</a></span>';
        }

        return '<div id="ivbc-notice" class="' . esc_attr($class) . '">'
            . '<span class="ivbc-notice__ico">' . self::icon('wallet') . '</span>'
            . '<div class="ivbc-notice__body"><strong class="ivbc-notice__title">' . $title . '</strong>'
            . '<span class="ivbc-notice__text">' . $text . '</span>' . $bar . $bal . '</div></div>';
    }

    private static function current_cart_notice() {
        if (!self::campaign_open_now() || !WC()->cart || WC()->cart->is_empty()) {
            return '<div id="ivbc-notice"></div>';
        }
        return self::notice_html(Ivbc_Rules::evaluate_cart(WC()->cart));
    }

    public static function echo_cart_notice() {
        if (self::campaign_open_now()) {
            echo self::current_cart_notice(); // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    public static function echo_checkout_notice() {
        if (self::campaign_open_now()) {
            echo self::current_cart_notice(); // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }

    public static function checkout_fragment($fragments) {
        if (self::campaign_open_now()) {
            $fragments['#ivbc-notice'] = self::current_cart_notice();
        }
        return $fragments;
    }

    /* ---------- pedido realizado y correo ---------- */

    /**
     * Qué le pasa a este pedido con la cartera. Devuelve array(estado, importe) o null.
     * estado: confirmed (ya sumado) | waiting (acumulará cuando se confirme el pago)
     */
    private static function order_wallet($order) {
        $uid = (int) $order->get_customer_id();
        if (!$uid || !Ivbc_Rules::enabled_for_user($uid)) {
            return null;
        }
        $row = Ivbc_Ledger::get_accrual($order->get_id());
        if ($row && $row->status === 'confirmed' && (float) $row->amount > 0) {
            return array('confirmed', (float) $row->amount);
        }
        if (Ivbc_Rules::earn_on() && in_array($order->get_status(), array('pending', 'on-hold'), true)) {
            $created = $order->get_date_created();
            $eval    = Ivbc_Rules::evaluate_order($order);
            if ($eval['eligible'] && $eval['amount'] > 0 && Ivbc_Rules::in_window($created ? $created->getTimestamp() : time(), $uid)) {
                return array('waiting', (float) $eval['amount']);
            }
        }
        return null;
    }

    public static function thankyou_card($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || !self::user_ok() || (int) $order->get_customer_id() !== get_current_user_id()) {
            return;
        }
        $info = self::order_wallet($order);
        if (!$info) {
            return;
        }
        list($state, $amount) = $info;
        $expiry = self::date_es(Ivbc_Settings::get('expiry'));
        wp_enqueue_style('ivbc-frontend', IVBC_URL . 'assets/css/frontend.css', array(), IVBC_VERSION);
        $title = $state === 'confirmed' ? 'Has acumulado ' . wc_price($amount) . ' en tu cartera IVB' : 'Este pedido acumulará ' . wc_price($amount) . ' en tu cartera IVB';
        $text  = $state === 'confirmed' ? 'Ya está en tu cartera. ' : 'Se sumará cuando confirmemos el pago del pedido. ';
        echo '<div class="ivbc-notice ivbc-notice--ok ivbc-notice--thanks"><span class="ivbc-notice__ico">' . self::icon('wallet') . '</span>'
            . '<div class="ivbc-notice__body"><strong class="ivbc-notice__title">' . $title . '</strong>'
            . '<span class="ivbc-notice__text">' . $text . self::use_text($expiry) . '</span>'
            . '<span class="ivbc-notice__bal"><a href="' . esc_url(wc_get_account_endpoint_url(self::ENDPOINT)) . '">Ver mi cartera</a></span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public static function email_line($order, $sent_to_admin, $plain_text, $email = null) {
        if ($sent_to_admin || !$order) {
            return;
        }
        $info = self::order_wallet($order);
        if (!$info) {
            return;
        }
        list($state, $amount) = $info;
        $expiry = self::date_es(Ivbc_Settings::get('expiry'));
        $verb   = $state === 'confirmed' ? 'te ha acumulado' : 'te acumulará, cuando confirmemos el pago,';
        if ($plain_text) {
            echo "\n" . sprintf('Este pedido %s %s en tu cartera IVB. %s', $verb, wp_strip_all_tags(wc_price($amount)), wp_strip_all_tags(self::use_text($expiry))) . "\n";
            return;
        }
        echo '<p style="margin:16px 0;padding:12px 14px;background:#eef3f6;border-left:4px solid #4d738a;color:#1f3340;">'
            . '<strong>Este pedido ' . esc_html($verb) . ' ' . wp_kses_post(wc_price($amount)) . ' en tu cartera IVB</strong><br>'
            . self::use_text($expiry) . '</p>';
    }

    /* ---------- Mi cuenta ---------- */

    public static function menu_item($items) {
        if (!self::user_ok()) {
            return $items;
        }
        $new = array();
        foreach ($items as $key => $label) {
            $new[$key] = $label;
            if ($key === 'orders') {
                $new[self::ENDPOINT] = 'Mi cartera';
            }
        }
        if (!isset($new[self::ENDPOINT])) {
            $new[self::ENDPOINT] = 'Mi cartera';
        }
        return $new;
    }

    /** "Del 16 al 31 de octubre, " o "Del 28 de octubre al 4 de noviembre, ". */
    private static function period_text() {
        $a = Ivbc_Settings::ts((string) Ivbc_Settings::get('start'));
        $b = Ivbc_Settings::ts((string) Ivbc_Settings::get('end'));
        if (!$a || !$b) {
            return '';
        }
        if (wp_date('Ym', $a) === wp_date('Ym', $b)) {
            return 'Del ' . wp_date('j', $a) . ' al ' . wp_date('j \d\e F', $b) . ', ';
        }
        return 'Del ' . wp_date('j \d\e F', $a) . ' al ' . wp_date('j \d\e F', $b) . ', ';
    }

    private static function chips($balance) {
        $expiry = self::date_es(Ivbc_Settings::get('expiry'));
        $limit  = rtrim(rtrim(number_format((float) Ivbc_Settings::get('redeem_limit'), 2, ',', ''), '0'), ',');
        $out    = '';
        if ($expiry) {
            $out .= '<li>' . self::icon('tag') . '<span>Utilizable<strong>' . self::window_text($expiry) . '</strong></span></li>';
        }
        if (Ivbc_Rules::spend_on()) {
            $out .= '<li>' . self::icon('bag') . '<span>Límite de uso: <strong>' . esc_html($limit) . ' %</strong> del importe de los productos de cada pedido</span></li>';
        }
        return '<ul class="ivbc-chips">' . $out . '</ul>';
    }

    /** Bloque «Usar mi cartera» de la página Mi cartera. */
    private static function spend_card($balance) {
        if (!Ivbc_Rules::spend_on()) {
            return '';
        }
        $limit  = rtrim(rtrim(number_format(Ivbc_Spend::limit_pct(), 2, ',', ''), '0'), ',');
        $expiry = self::date_es(Ivbc_Settings::get('expiry'));
        $start  = (string) Ivbc_Settings::get('redeem_start');
        $future = $start !== '' && time() < Ivbc_Settings::ts($start);
        $open   = Ivbc_Rules::spend_open(get_current_user_id());
        $avail  = Ivbc_Spend::available(get_current_user_id());
        $body   = 'Tu saldo se descuenta solo en el carrito: se resta lo máximo que se pueda, hasta el <strong>' . esc_html($limit) . ' %</strong> del importe de los productos de cada pedido (con IVA, sin material promocional). Si prefieres no usarlo en un pedido, puedes quitarlo desde el carrito.';
        if ($future) {
            $state = 'Podrás usar tu cartera a partir del ' . esc_html(self::date_es($start)) . '.';
            $cta   = '';
        } elseif (!$open) {
            $state = 'El plazo para usar la cartera ha terminado' . ($expiry ? ' (' . esc_html($expiry) . ')' : '') . '.';
            $cta   = '';
        } elseif ($avail <= 0) {
            $state = 'Ahora mismo no tienes saldo disponible.';
            $cta   = '';
        } else {
            $state = 'Tienes <strong>' . wc_price($avail) . '</strong> disponibles' . ($expiry ? ' hasta el ' . esc_html($expiry) : '') . '.';
            $cta   = '<p><a class="button" href="' . esc_url(wc_get_cart_url()) . '">Ir al carrito</a></p>';
        }
        return '<h3 class="ivbc-h">Usar mi cartera</h3><div class="ivbc-spend"><p>' . $state . '</p><p>' . $body . '</p>' . $cta . '</div>';
    }

    public static function render_account() {
        if (!self::user_ok()) {
            echo '<p>La cartera no está disponible todavía.</p>';
            return;
        }
        wp_enqueue_style('ivbc-frontend', IVBC_URL . 'assets/css/frontend.css', array(), IVBC_VERSION);
        $uid     = get_current_user_id();
        $balance = Ivbc_Ledger::balance($uid);
        $rows    = Ivbc_Ledger::movements($uid, 100);
        $pct     = rtrim(rtrim(number_format((float) Ivbc_Settings::get('percent'), 2, ',', ''), '0'), ',');
        $min     = (int) Ivbc_Settings::get('min_units');

        echo '<div class="ivbc-wallet">';
        echo '<div class="ivbc-hero"><div><span class="ivbc-hero__label">Saldo disponible</span><span class="ivbc-hero__amount">' . wc_price($balance['confirmed']) . '</span>'
            . '<span class="ivbc-hero__sub">Cartera IVB</span></div>'
            . '<span class="ivbc-hero__ico">' . self::icon('wallet') . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo self::chips($balance); // phpcs:ignore WordPress.Security.EscapeOutput

        echo self::spend_card($balance); // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<h3 class="ivbc-h">Cómo funciona</h3><ol class="ivbc-steps">'
            . '<li>' . self::icon('bag') . '<strong>Compra</strong><span>' . self::period_text() . 'pedidos de al menos ' . $min . ' unidades.</span></li>'
            . '<li>' . self::icon('plus') . '<strong>Acumula</strong><span>El ' . esc_html($pct) . ' % del importe de cada pedido válido va a tu cartera.</span></li>'
            . '<li>' . self::icon('tag') . '<strong>Aprovecha</strong><span>' . (Ivbc_Rules::spend_on() ? 'Se descuenta solo en el carrito, hasta el ' . esc_html(self::date_es(Ivbc_Settings::get('expiry'))) . '.' : 'Úsalo como descuento en la campaña de Black Friday.') . '</span></li>'
            . '</ol>';

        echo '<h3 class="ivbc-h">Movimientos</h3>';
        if (!$rows) {
            echo '<p class="ivbc-empty">Todavía no tienes movimientos. Cuando hagas un pedido que cumpla las condiciones, aparecerá aquí.</p>';
        } else {
            $labels = array('confirmed' => 'Confirmado', 'void' => 'Anulado');
            $url    = function ($oid) {
                return esc_url(wc_get_endpoint_url('view-order', $oid, wc_get_page_permalink('myaccount')));
            };
            echo '<ul class="ivbc-moves">';
            foreach ($rows as $r) {
                $ts   = strtotime($r->created_gmt . ' UTC');
                if ($r->type === 'accrual') {
                    $conc = 'Acumulado en el pedido <a href="' . $url($r->order_id) . '">#' . (int) $r->order_id . '</a>';
                } elseif ($r->type === 'redeem') {
                    $conc = ($r->status === 'void' ? 'Devuelto: pedido ' : 'Usado en el pedido ') . '<a href="' . $url($r->order_id) . '">#' . (int) $r->order_id . '</a>';
                } else {
                    $conc = esc_html($r->note !== '' ? $r->note : 'Ajuste');
                }
                $meta = esc_html(wp_date('d/m/Y', $ts)) . ($r->type === 'accrual' ? ' &middot; ' . (int) $r->units . ' unidades' : '');
                echo '<li class="ivbc-move ivbc-' . esc_attr($r->status) . '"><div class="ivbc-move__main"><span class="ivbc-move__title">' . $conc . '</span>'
                    . '<span class="ivbc-move__meta">' . $meta . '</span></div>'
                    . '<div class="ivbc-move__side"><span class="ivbc-move__amount">' . wc_price((float) $r->amount) . '</span>'
                    . '<span class="ivbc-badge ivbc-badge--' . esc_attr($r->status) . '">' . esc_html($labels[$r->status] ?? $r->status) . '</span></div></li>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    public static function dashboard_card() {
        if (!self::user_ok()) {
            return;
        }
        wp_enqueue_style('ivbc-frontend', IVBC_URL . 'assets/css/frontend.css', array(), IVBC_VERSION);
        $balance = Ivbc_Ledger::balance(get_current_user_id());
        echo '<a class="ivbc-mini" href="' . esc_url(wc_get_account_endpoint_url(self::ENDPOINT)) . '"><span class="ivbc-mini__ico">' . self::icon('wallet') . '</span>'
            . '<span class="ivbc-mini__txt"><span>Mi cartera IVB</span><strong>' . wc_price($balance['confirmed']) . '</strong></span>'
            . '<span class="ivbc-mini__go">Ver</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
}
