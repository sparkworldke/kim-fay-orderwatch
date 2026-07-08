<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Ipay_Gateway_Blocks extends AbstractPaymentMethodType
{
    private $gateway;

    protected $name = 'ipay';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_ipay_settings', []);

        $this->gateway = new WC_Ipay_Gateway();
    }

    public function is_active()
    {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {
        wp_register_script(
            'ipay-blocks-integration',
            plugin_dir_url(__FILE__) . 'ipay-checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('ipay-blocks-integration');
        }

        return ['ipay-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        return [
            'id' => $this->gateway->id,
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
            'icon' => $this->gateway->icon,
        ];
    }
}
