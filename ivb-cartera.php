<?php
/**
 * Plugin Name: IVB Cartera
 * Description: Cartera de saldo para clientes B2B. Acumula un porcentaje del importe de los pedidos que cumplan las condiciones de la campaña (PREBLACK), permite gastar el saldo como descuento en el carrito, y lo muestra en carrito, checkout, Mi cuenta y administración. Ganar y gastar se activan por separado.
 * Version: 1.4.12
 * Author: Adrià López
 * Author URI: https://adria-lopez.com
 * Text Domain: ivb-cartera
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('IVBC_VERSION', '1.4.12');
define('IVBC_FILE', __FILE__);
define('IVBC_DIR', plugin_dir_path(__FILE__));
define('IVBC_URL', plugin_dir_url(__FILE__));

require_once IVBC_DIR . 'includes/class-ivbc-settings.php';
require_once IVBC_DIR . 'includes/class-ivbc-ledger.php';
require_once IVBC_DIR . 'includes/class-ivbc-rules.php';
require_once IVBC_DIR . 'includes/class-ivbc-spend.php';
require_once IVBC_DIR . 'includes/class-ivbc-orders.php';
require_once IVBC_DIR . 'includes/class-ivbc-frontend.php';
require_once IVBC_DIR . 'includes/class-ivbc-admin.php';

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', IVBC_FILE, true);
    }
});

register_activation_hook(__FILE__, function () {
    Ivbc_Ledger::install();
    add_rewrite_endpoint('mi-cartera', EP_ROOT | EP_PAGES);
    flush_rewrite_rules();
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>IVB Cartera</strong> requiere WooCommerce para funcionar.</p></div>';
        });
        return;
    }
    Ivbc_Ledger::maybe_upgrade();
    Ivbc_Orders::init();
    Ivbc_Spend::init();
    Ivbc_Frontend::init();
    if (is_admin()) {
        Ivbc_Admin::init();
    }
}, 20);
