<?php

/**
 * Plugin Name:       WC iPay-Accumatica
 * Plugin URI:        https://dev.ipayafrica.com/
 * Description:       iPay/eLipa are payment gateway for WooCommerce allowing you to receive payments via Mobile/Card/PDQ and more
 * Version:           4.0.0
 * Author:            iPay/eLipa
 * Author URI:        https://ipayafrica.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ipay
 * Domain Path:       /public/lang
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-accumatica-tables.php';

register_activation_hook(__FILE__, 'configure_accumatica_defaults');
function configure_accumatica_defaults()
{
    $tables = new AccumaticaDBTables();
    $tables->createAuthenticationAccessTable();
    $tables->createTransactionTable();
}

add_filter("plugin_action_links_" . plugin_basename(__FILE__), 'ipay_settings');
function ipay_settings($links)
{
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=ipay') . '">Settings</a>';
    $ipay_docs = '<a href="https://dev.ipayafrica.com/">Docs</a>';
    array_push($links, $settings_link);
    array_push($links, $ipay_docs);
    return $links;
}


add_action('plugins_loaded', 'woocommerce_ipay', 0);
function woocommerce_ipay()
{
    if (!class_exists('WC_Payment_Gateway')) return;

    include(plugin_dir_path(__FILE__) . 'class-ipay-gateway.php');
}


add_filter('woocommerce_payment_gateways', 'add_ipay_gateway');
function add_ipay_gateway($gateways)
{
    $gateways[] = 'WC_Ipay_Gateway';

    return $gateways;
}

add_action('before_woocommerce_init', 'ipay_declare_cart_checkout_blocks_and_hpos_compatibility');
function ipay_declare_cart_checkout_blocks_and_hpos_compatibility()
{
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
}

add_action('woocommerce_blocks_loaded', 'ipay_register_order_approval_payment_method_type');
function ipay_register_order_approval_payment_method_type()
{
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once plugin_dir_path(__FILE__) . 'class-ipay-block.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new WC_Ipay_Gateway_Blocks);
        }
    );
}
