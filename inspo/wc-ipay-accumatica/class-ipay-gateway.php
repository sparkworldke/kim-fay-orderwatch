<?php

require_once __DIR__ . '/class-accumatica-db-ops.php';
require_once __DIR__ . '/class-accumatica.php';
require_once __DIR__ . '/class-helper.php';

class WC_Ipay_Gateway extends WC_Payment_Gateway
{
    /**
     * Gateway instructions that will be added to the thank you page and emails.
     *
     * @var string
     */
    public $instructions;

    /**
     * Merchant Name
     * 
     * @var string
     */
    public $mer;

    /**
     * Vendor Id
     * 
     * @var string
     */
    public $vid;

    /**
     * Country in which Merchnat is operating in
     * 
     * @var string
     */
    public $merchant_country;

    /**
     * Hash Key / Security Key 
     * 
     * @var string
     */
    public $hsh;

    /**
     * Mode (Test or Live) 
     * 
     * @var string
     */
    public $mode;

    /**
     * Autopay setting for silent callbacks
     * 
     * @var string
     */
    public $autopay;

    /**
     * Callback Url
     * 
     * @var string
     */
    public $callback_url;

    /**
     * Checkout options (Redirect or IFRAME)
     * 
     * @var string
     */
    public $checkout_option;

    /**
     * Accumatica Client Username
     * 
     * @var string
     */
    public $client_username;

    /**
     * Accumatica Client Password
     * 
     * @var string
     */
    public $client_password;

    /**
     * Accumatica Client Key
     * 
     * @var string
     */
    public $client_key;

    /**
     * Accumatica Client Secret
     * 
     * @var string
     */
    public $client_secret;

    /**
     * Accumatica Inventory Id
     * 
     * @var string
     */
    public $inventory_id;

    /**
     * Accumatica Woocommerce Customer
     * 
     * @var string
     */
    public $customer;

    /**
     * Accumatica Live Auth Url
     * 
     * @var string
     */
    public $auth_url;

    /**
     * Accumatica Live Order Url
     * 
     * @var string
     */
    public $order_url;

    /**
     * Accumatica class
     * 
     * @var TableOperations
     */
    public $accumatica_db_ops;

    public function __construct()
    {
        $this->id = 'ipay';
        $this->icon = apply_filters('woocommerce_ipay_icon', plugins_url('channels.png', __FILE__));
        $this->has_fields = false;
        $this->method_title = __('iPay', 'ipay');
        $this->method_description = __('Allow customers to conveniently pay with iPay/eLipa payment gateway.', 'ipay');
        //$this->callback_url = $this->ipay_callback();

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Admin configs
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions', $this->description);
        $this->mer = $this->get_option('mer');
        $this->vid = $this->get_option('vid');
        $this->merchant_country = $this->get_option('merchant_country');
        $this->hsh = $this->get_option('hsh');
        $this->mode = $this->get_option('mode');
        $this->autopay = $this->get_option('autopay');
        $this->client_key = $this->get_option('client_key');
        $this->client_secret = $this->get_option('client_secret');
        $this->client_username = $this->get_option('client_username');
        $this->client_password = $this->get_option('client_password');
        $this->inventory_id = $this->get_option('inventory_id');
        $this->auth_url = $this->get_option('auth_url');
        $this->order_url = $this->get_option('order_url');
        $this->customer = $this->get_option('customer');
        $this->autopay = $this->get_option('autopay');
        $this->checkout_option = $this->get_option('checkout_option');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_' . $this->id . '_callback', array($this, 'callback_handler'));
        add_action('woocommerce_receipt_ipay', array($this, 'receipt_page'));

        // Acumatica table ops
        $this->accumatica_db_ops = new TableOperations();
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'ipay'),
                'type' => 'checkbox',
                'label' => __('Enable iPay Payments Gateway', 'ipay'),
                'default' => 'yes',
            ),
            'title' => array(
                'title' => __('Title', 'ipay'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'ipay'),
                'default' => __('iPay', 'ipay'),
                'desc_tip' => true,
            ),
            'merchant_country' => array(
                'title' => __('Merchant Country', 'ipay'),
                'type' => 'select',
                'description' => __('The location of eLipa or iPay you assigned up to as a merchant.', 'ipay'),
                'default' => __('Select Country', 'ipay'),
                'options' => array(
                    'ke' => 'Kenya',
                    'tz' => 'Tanzania',
                    'tg' => 'Togo',
                    'ug' => 'Uganda',
                ),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'ipay'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'ipay'),
                'default' => __('Pay with Mobile Money or Card Online.', 'ipay'),
                'desc_tip' => true,
            ),
            'instructions' => array(
                'title' => __('Instructions', 'ipay'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails.', 'ipay'),
                'default' => __('Pay with Mobile Money or Card Online.', 'ipay'),
                'desc_tip' => true,
            ),
            'mer' => array(
                'title' => __('Merchant Name', 'ipay'),
                'description' => __('Company name', 'ipay'),
                'type' => 'text',
                'default' => __('Company Name', 'ipay'),
                'desc_tip' => false,
            ),
            'vid' => array(
                'title' => __('Vendor ID', 'ipay'),
                'type' => 'text',
                'description' => __('Vendor ID as assigned by iPay. SET IN LOWER CASE.', 'ipay'),
                'default' => __('demo', 'ipay'),
                'desc_tip' => false,
            ),
            'hsh' => array(
                'title' => __('Security Key', 'ipay'),
                'type' => 'password',
                'description' => __('Security key assigned by iPay', 'ipay'),
                'default' => __('demoCHANGED', 'ipay'),
                'desc_tip' => false,
            ),
            'client_username' => array(
                'title' => __('Accumatica Client Username', 'ipay'),
                'type' => 'text',
                'description' => __('Client Username as assigned by Accumantica.', 'ipay'),
                'default' => __('', 'ipay'),
                'desc_tip' => false,
            ),
            'client_password' => array(
                'title' => __('Accumatica Client Password', 'ipay'),
                'type' => 'password',
                'description' => __('Client Password as assigned by Accumantica.', 'ipay'),
                'default' => __('', 'ipay'),
                'desc_tip' => false,
            ),
            'client_key' => array(
                'title' => __('Accumatica Client ID', 'ipay'),
                'type' => 'text',
                'description' => __('Client Key as assigned by Accumantica.', 'ipay'),
                'default' => __('', 'ipay'),
                'desc_tip' => false,
            ),
            'client_secret' => array(
                'title' => __('Accumatica Client Secret', 'ipay'),
                'type' => 'password',
                'description' => __('Client Secret as assigned by Accumantica.', 'ipay'),
                'default' => __('', 'ipay'),
                'desc_tip' => false,
            ),
            'inventory_id' => array(
                'title' => __('Accumatica Client Online Inventory Id', 'ipay'),
                'type' => 'text',
                'description' => __('Inventory Id as assigned by Accumantica.', 'ipay'),
                'default' => __('FAYBA0003', 'ipay'),
                'desc_tip' => false,
            ),
            'customer' => array(
                'title' => __('Online Customer', 'ipay'),
                'type' => 'text',
                'description' => __('Customer as identified by Accumantica system.', 'ipay'),
                'default' => __('CUST101379', 'ipay'),
                'desc_tip' => false,
            ),
            'auth_url' => array(
                'title' => __('Accumatica Live Auth Url', 'ipay'),
                'type' => 'text',
                'description' => __('Accumatica Auth Url.', 'ipay'),
                'default' => __('https://erp.kimfay.com/AcumaticaERP/identity/connect/token', 'ipay'),
                'desc_tip' => false,
            ),
            'order_url' => array(
                'title' => __('Accumatica Live Order Url', 'ipay'),
                'type' => 'text',
                'description' => __('Order Url.', 'ipay'),
                'default' => __('https://erp.kimfay.com/AcumaticaERP/entity/IpayV2/22.200.001/PosOrder/', 'ipay'),
                'desc_tip' => false,
            ),
            'mode' => array(
                'title' => __('Live/Demo', 'ipay'),
                'type' => 'checkbox',
                'label' => __('Make iPay live', 'ipay'),
                'default' => 'no',
            ),
            'autopay' => array(
                'title' => __('Autopay', 'ipay'),
                'type' => 'checkbox',
                'label' => __('The users will not be redirected to the callback. Not a preferred option', 'ipay'),
                'default' => 'no',
            ),
            'checkout_option' => array(
                'title' => __('Checkout Option', 'ipay'),
                'type' => 'select',
                'description' => __('Choose either an iFrame or a redirect. A redirect is recommended though', 'ipay'),
                'default' => __('Select Checkout Option', 'ipay'),
                'options' => array(
                    'redirect' => 'Redirect',
                    'iframe' => 'iFrame',
                ),
                'desc_tip' => true,
            ),
        );
    }

    public function admin_options()
    {

        echo '<h3>' . 'iPay Payments Gateway' . '</h3>';

        echo '<p>' . 'Allow customers to conveniently pay with iPay payment gateway.' . '</p>';

        echo '<table class="form-table">';

        $this->generate_settings_html();

        echo '</table>';
    }

    public function receipt_page($order_id)
    {

        echo $this->redirect_ipay($order_id);
        exit();
    }

    public function redirect_ipay($order_id)
    {
        $order = wc_get_order($order_id);
        $params = [];

        $params['live'] = $this->mode == 'yes' ? 1 : 0;
        $params['oid'] = $order->get_id();
        $params['inv'] = $order->get_id();


        $supported_currencies = ["KES", "TZS", "UGX", "XOF", "USD"];

        $curr = "";

        if (in_array(get_woocommerce_currency(), $supported_currencies)) {

            $curr = get_woocommerce_currency();
        } else {

            echo "Unsupported currency";
            exit();
        }

        # call accumaticat to create an order
        [$finalPayable, $saveRes] = (new Accumatica(
            mode: $this->mode,
            client_key: $this->client_key,
            client_secret: $this->client_secret,
            client_username: $this->client_username,
            client_password: $this->client_password,
            inventory_id: $this->inventory_id,
            customer: $this->customer,
            liveAuthUrl: !empty($this->auth_url) ? $this->auth_url : null,
            liveOrderUrl: !empty($this->order_url) ? $this->order_url : null
        ))->createOrder($order->get_items(), $order_id);


        if (!($saveRes instanceof stdClass) || $finalPayable == 0) {
            header('Location:' . home_url('checkout/'));
            exit();
        }

        //$ttl = $order->get_total();
        $ttl = round($finalPayable);

        if (in_array($curr, $supported_currencies) && $curr != "USD") {

            $ttl = round($ttl);
        }

        $params['ttl'] = $ttl;
        $params['tel'] = str_replace(array(' ', '<', '>', '&', '{', '}', '*', "+", '!', '@', '#', "$", '%', '^', '&'), "", str_replace("-", "", $order->get_billing_phone()));
        $params['eml'] = $order->get_billing_email();
        $params['vid'] = $this->vid;
        $params['curr'] = $curr;
        $params['p1'] = '';
        $params['p2'] = '';
        $params['p3'] = '';
        $params['p4'] = '';

        # callback url
        $callback_url = get_bloginfo('url') . '/wc-api/' . $this->id . '_callback';
        $params['cbk'] = $callback_url;

        $params['cst'] = '0';
        $params['crl'] = '1';

        $data_string =  join('', array_values($params));
        $params['hsh'] = hash_hmac('sha1', $data_string, $this->hsh);

        # check if autopay is enabled
        // if ($this->autopay == 'yes') {
        //     $params['autopay'] = 1;
        //  //   $params['lbk'] = $callback_url . '?vsc=' . $order->get_id();
        // }
        $params['autopay'] = 1;
 
        if ($params['autopay'] == 1) {
            $params['lbk'] = get_home_url();
        }
        $params['bonga'] = '1';
        $params['vooma'] = '1';

        $ipayUrl = "";
        switch ($this->merchant_country) {

            case "tz":
                $ipayUrl = "https://payments.elipa.co.tz/v3/tz";
                break;

            case "ke":
                $ipayUrl = "https://payments.ipayafrica.com/v3/ke";
                break;

            case "ug":
                $ipayUrl = "https://payments.elipa.co.ug/v3/ug";
                break;

            case "tg":
                $ipayUrl = "https://payments.elipa.tg/v1/v3/index.php/togo";
                break;

            default:
                echo "Unsupported merchant country";
                exit();
                break;
        }

        // print_r($params);
        // die;

        $url = $ipayUrl . '?' . http_build_query($params);



        switch ($this->checkout_option) {
            case 'redirect':
                header("location: $url");
                break;

            case 'iframe':
                echo '<style>
                .iframe-container {
                    display: flex;
                    justify-content: center;
                    width: 100%;
                }
                .responsive-iframe {
                    border: 0;
                    width: 50%; /* Adjust width as needed */
                    height: 1200px;
                }
              </style>
              <div class="iframe-container">
                <iframe src="' . $url . '" class="responsive-iframe"></iframe>
              </div>';
                //  echo '<iframe src="' . $url . '" width="100%" height="700" style="border:0" onload="onLoad()"></iframe>';
                break;
        }
    }

    /**
     * Returns link to the callback class
     * Refer to WC-API for more information on using classes as callbacks
     */
    public function ipay_callback()
    {

        return WC()->api_request_url('WC_Gateway_Ipay');
    }

    /**
     * This function gets the callback values posted by iPay to the callback url
     * It updates order status and order notes
     */
    public function callback_handler()
    {
        $ipn_base = "";


        // these values below are picked from the incoming URL and assigned to variables that we
        // will use in our security check URL


        switch ($this->merchant_country) {
            case 'ke':
                $ipn_base = "https://www.ipayafrica.com/ipn";
                break;

            case 'tz':
                $ipn_base = "https://payments.elipa.co.tz/v3/tz/ipn";
                break;

            case 'ug':
                $ipn_base = "https://payments.elipa.co.ug/v3/ug/ipn";
                break;

            case 'tg':
                $ipn_base = "https://payments.elipa.tg/v1/tg/index.php/ipn/check";
                break;

            default:
                echo "Unknown country of operation";
                exit();
                break;
        }

        # $val = $this->vid;
        $oid = $status = '';

        if (isset($_GET['vsc']) && !empty($_GET['vsc'])) {

            # get order id
            $oid = sanitize_text_field($_GET['vsc'] ?? '');

            $data_string = $oid . $this->vid;
            $hash = hash_hmac('sha256', $data_string, $this->hsh);
            $payload = ['oid' => $oid, 'vid' => $this->vid, 'hash' => $hash];

            # search transaction
            $response = Helper::curlRequest(
                'https://apis.ipayafrica.com/payments/v2/transaction/search',
                'POST',
                json_encode($payload),
                ['Content-Type: application/json']
            );

            # check if payment was successful
            if ((isset($response['header_status']) && $response['header_status'] == 200) && (isset($response['status']) && $response['status'] == 1)) {
                $status = 'aei7p7yrx4ae34';
            } else {
                $status = 'fe2707etr5s4wq';
            }
        } else {

            $oid = sanitize_text_field($_GET['id'] ?? '');
            $val2 = sanitize_text_field($_GET['ivm'] ?? '');
            $val3 = sanitize_text_field($_GET['qwh'] ?? '');
            $val4 = sanitize_text_field($_GET['afd'] ?? '');
            $val5 = sanitize_text_field($_GET['poi'] ?? '');
            $val6 = sanitize_text_field($_GET['uyt'] ?? '');
            $val7 = sanitize_text_field($_GET['ifd'] ?? '');

            $ipnurl = $ipn_base . "?vendor=" . $this->vid . "&id=" . $oid . "&ivm=" . $val2 . "&qwh=" . $val3 . "&afd=" . $val4 . "&poi=" . $val5 . "&uyt=" . $val6 . "&ifd=" . $val7;

            $status = Helper::curlRequest($ipnurl, 'POST');
        }

        # check if is test mode
        if ($this->mode == 'no') {
            $status = 'aei7p7yrx4ae34';
        }

        # if payment is successful notify accumatica
        if ($status == 'aei7p7yrx4ae34') {

            $accoumatica_order = $this->accumatica_db_ops->fetchTransactionByWcOrderId($oid);

            $this->accumatica_db_ops->updateTransaction($oid);

            $order =  wc_get_order($oid);

            (new Accumatica(
                mode: $this->mode,
                client_key: $this->client_key,
                client_secret: $this->client_secret,
                client_username: $this->client_username,
                client_password: $this->client_password,
                inventory_id: $this->inventory_id,
                customer: $this->customer,
                liveAuthUrl: !empty($this->auth_url) ? $this->auth_url : null,
                liveOrderUrl: !empty($this->order_url) ? $this->order_url : null
            ))->confirmPayment(
                accumatica_order_id: $accoumatica_order->accumatica_order_id,
                accumatica_payment_id: $accoumatica_order->accumatica_payment_id,
                accumatica_customer_id: $accoumatica_order->accumatica_payment_id,
                wc_order_id: $oid,
                billingEmail: $order->get_billing_email(),
                billingAddress1: $order->get_billing_address_1(),
                billingAddress2: $order->get_billing_address_2(),
                billingPhoneNumber: $order->get_billing_phone(),
                billingName: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                billingCountry: $order->get_billing_country(),
                billingCity: $order->get_billing_city(),
                billingState: $order->get_billing_state(),
                billingPostalCode: $order->get_billing_postcode(),
                shippingName: $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                shippingAddress1: $order->get_shipping_postcode(),
                shippingAddress2: $order->get_shipping_postcode(),
                shippingCity: $order->get_shipping_postcode(),
                shippingState: $order->get_shipping_postcode(),
                shippingPostalCode: $order->get_shipping_postcode(),
                shippingPostalCountry: $order->get_shipping_postcode(),
                shippingTotal: $order->get_shipping_total(),
                shippingTax: $order->get_shipping_tax(),
            );
        }

        $this->notifications($status, $oid);
    }

    public function notifications($status, $order_id)
    {

        $checkout_option = $this->checkout_option;

        $order = wc_get_order($order_id);

        //Failed
        if ($status == "fe2707etr5s4wq") {
            $order->update_status('failed', 'The attempted payment FAILED - iPay.<br>', 'woocommerce');
            wp_die("iPay payment failed. Check out the email sent to you from iPay for the reason of failure of order $order_id.");
        }

        // Successful
        else if ($status == "aei7p7yrx4ae34") {
            $order->update_status('completed', 'The order was SUCCESSFULLY processed by iPay.<br>', 'woocommerce');
            $order->reduce_order_stock();
            $success_page = $this->get_return_url($order);

            switch ($checkout_option) {
                case 'redirect':
                    wp_redirect($success_page);
                    break;

                case 'iframe':
?>
                    <script type="text/javascript">
                        window.top.location.href = "<?php echo $success_page; ?>";
                    </script>
<?php
                    break;
            }
        }

        // Pending
        else if ($status == "bdi6p2yy76etrs") {
            $order->update_status('pending', 'The transaction is PENDING. Tell customer to try again -iPAY', 'woocommerce');
            wp_die("The iPay payment is pending. Please try again in 5 minutes or contact the the owner of the site for assistance.");
        }

        // Used code
        else if ($status == "cr5i3pgy9867e1") {
            $order->update_status('payment-used', __('The input payment code has already been USED. Please contact customer - iPay.<br>', 'woocommerce'));
            wp_die("The iPay payment has already been used. Contact the owner of the site for further assistance.");
        }

        // Less
        else if ($status == "dtfi4p7yty45wq") {
            $order->update_status('on-hold', __('Amount paid was LESS than the required - iPay.<br>', 'woocommerce'));
            wp_die("The iPay payment received is less than the transaction amount expected. Contact the Merchant for assistance.");
        }

        // More
        else if ($status == "eq3i7p5yt7645e") {
            $order->update_status('overpaid', 'The order was overpaid but SUCCESSFULLY processed by iPay.<br>', 'woocommerce');
            $order->reduce_order_stock();
            wp_redirect($this->get_return_url($order));
        }
        die;
    }


    // Process the payment
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        // Implement your payment processing logic here

        return array(
            'result' => 'success',
            'redirect' => add_query_arg(
                'order',
                $order->id,
                add_query_arg('key', $order->order_key, $order->get_checkout_payment_url(true))
            ),
        );
    }
}
