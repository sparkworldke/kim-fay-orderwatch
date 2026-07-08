<?php
class ZM_Mpesa{
    private $settings = [
        'passkey'=> 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
        'shortcode'=> "174379",
        'base_url'=> 'https=>//sandbox.safaricom.co.ke',
        'consumer_key'=>'sRwV3ScCvF8SuGdaHItG7bEtKVd7PQGv',
        'consumer_secret'=>'yMe0pXkqJGgefDOb'
    ];
    private $home_url = 'https://kimfay.zeromargin.co.ke/';
    private $token = '';

    function generate_password(){
        $now = (new DateTime())->modify('+3 hours');
        $timestamp = $now->format('YmdHis'); //now.strftime("%Y%m%d%H%M%S")
        $password = base64_encode($this->settings['shortcode'].$this->settings['passkey'].$timestamp);
        return ['password'=>$password,'timestamp'=>$timestamp];
    }
    function auth(){
        $url = $this->settings['base_url'].'/oauth/v1/generate?grant_type=client_credentials';
        $headers = [
            'Authorization: Basic '.base64_encode($this->settings['consumer_key'].':'+$this->settings['consumer_secret'])
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $server_output = curl_exec($ch);
        curl_close($ch);
        $this->token = json_decode($server_output,true);
        print_r($this->token);
    }
    function initiate_transaction($order,$customer){
        $url = $this->settings['base_url'].'/mpesa/stkpush/v1/processrequest';
        // todo: verify number
        // $number = phonenumbers.parse(phone_number, "KE")
        // if not phonenumbers.is_valid_number(number):
        //     return False
        // phone_number = phonenumbers.format_number(number, phonenumbers.PhoneNumberFormat.E164).replace('+','')
        // print(phone_number)
        $password_obj = $this->generate_password();
        $phone_number_ = $order->get_customer()->get_billing_phone();

        $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
        try {
            $phone_number_proto = $phoneUtil->parse($phone_number_, "KE");
            if(!$phoneUtil->isValidNumber($phone_number_proto)){
                return false;
            }
            $phone_number = $phoneUtil->format($phone_number_proto, \libphonenumber\PhoneNumberFormat::E164);
            $phone_number = str_replace('+','',$phone_number);
        } catch (\libphonenumber\NumberParseException $e) {
            var_dump($e);
        }

        $account_ref = $order->get_id();
        
        $params = [
            "BusinessShortCode"=> $this->settings['shortcode'],
            "Password"=> $password_obj['password'],
            "Timestamp"=> $password_obj['timestamp'],
            "TransactionType"=> "CustomerPayBillOnline",
            "Amount"=> $order->get_total(),
            "PartyA"=> $phone_number,
            "PartyB"=> $this->settings['shortcode'],
            "PhoneNumber"=> $phone_number,
            "CallBackURL"=> $this->home_url+"wc-api/zm_mpesa",
            "AccountReference"=> $account_ref,
            "TransactionDesc"=> 'Order #'.$account_ref
        ];


        $headers = [
            'Authorization: Bearer '.$this->settings['access_token'],
            'Content-Type:application/json'
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL,$url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $server_output = curl_exec($ch);
        curl_close($ch);
        $res = json_decode($server_output,true);
        

        if(isset($res['ResponseCode'])){
            if($res['ResponseCode']==0){//Sucess
                //todo: update order meta
                // $tx =  array(
                //     member=member,
                //     checkout_request_id=>res['CheckoutRequestID'],
                //     customer_message=>res['CustomerMessage'],
                //     merchant_request_id=>res['MerchantRequestID'],
                //     response_code=>res['ResponseCode'],
                //     'response_description'=>res['ResponseDescription'],
                // );
                update_post_meta($order->get_id(), 'zm_mpesa_req', $res);
                update_post_meta($order->get_id(), 'zm_mpesa_req_id', $res['CheckoutRequestID']);//to find it later
                update_post_meta($order->get_id(), 'zm_mpesa_status', 'pending');
            }else{
                 //todo: return mpesa error
            }
        }else{
            //todo: return mpesa error
        }
        
    }

    function updatePayment($data){
        // get the related order
        $args = array(
            'post_type'=>'order',
            'meta_query' => array(
                array(
                    'key' => 'zm_mpesa_req_id',
                    'value' => $data['CheckoutRequestID'],
                    'compare' => '=',
                )
            )
        );
        $query = new WP_Query($args);
        if(count($query->posts)<=0){
            return false;
        }
        $order = wc_get_order($query->posts[0]->ID );
        
        update_post_meta($order->get_id(), 'zm_mpesa_result', $data);
        
        
        $status = 'Failed';
        if($data['ResultCode']==0){
            $order->update_status( 'completed' );
            update_post_meta($order->get_id(), 'zm_mpesa_status', 'completed');
        }else{
            update_post_meta($order->get_id(), 'zm_mpesa_status', 'failed');
        }
        
    }
}


add_filter( 'woocommerce_payment_gateways', 'zm_add_gateway_class');
add_action( 'plugins_loaded', 'zm_init_gateway_class' );

/*
* This action hook registers our PHP class as a WooCommerce payment gateway
*/

function zm_add_gateway_class( $gateways ) {
    $gateways[] = 'WC_ZM_Gateway'; // your class name is here
    return $gateways;
}
function zm_init_gateway_class() {
    class WC_ZM_Gateway extends WC_Payment_Gateway {

        /**
         * Class constructor, more about it in Step 3
        */
        public function __construct() {
            $this->id = 'zm'; // payment gateway plugin ID
            $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = 'ZM Gateway';
            $this->method_description = 'Description of ZM payment gateway'; // will be displayed on the options page
    
            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = array(
                'products'
            );
    
            // Method with all the options fields
            $this->init_form_fields();
    
            // Load the settings.
            $this->init_settings();
            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );
            $this->enabled = $this->get_option( 'enabled' );
            $this->testmode = 'yes' === $this->get_option( 'testmode' );
            $this->private_key = $this->testmode ? $this->get_option( 'test_private_key' ) : $this->get_option( 'private_key' );
            $this->publishable_key = $this->testmode ? $this->get_option( 'test_publishable_key' ) : $this->get_option( 'publishable_key' );
    
            // This action hook saves the settings
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    
            // We need custom JavaScript to obtain a token
            add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
    
            // the webhook below goes to https://site.com/wc-api/{webhook name}/
            // add_action( 'woocommerce_api_{webhook name}', array( $this, 'webhook' ) );
    
        }
    
        /**
         * Plugin options, we deal with it in Step 3 too
        */
        public function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable ZM Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'MPesa',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Lipa na MPesa.',
                ),
                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'test_publishable_key' => array(
                    'title'       => 'Test Publishable Key',
                    'type'        => 'text'
                ),
                'test_private_key' => array(
                    'title'       => 'Test Private Key',
                    'type'        => 'password',
                ),
                'publishable_key' => array(
                    'title'       => 'Live Publishable Key',
                    'type'        => 'text'
                ),
                'private_key' => array(
                    'title'       => 'Live Private Key',
                    'type'        => 'password'
                )
            );
        }
    
        /**
         * You will need it if you want your custom credit card form, Step 4 is about it
         */
        public function payment_fields() {
    
            // ok, let's display some description before the payment form
            if ( $this->description ) {
                // you can instructions for test mode, I mean test card numbers etc.
                if ( $this->testmode ) {
                    $this->description .= ' TEST MODE ENABLED. In test mode, you can use the card numbers listed in <a href="#">documentation</a>.';
                    $this->description  = trim( $this->description );
                }
                // display the description with <p> tags etc.
                echo wpautop( wp_kses_post( $this->description ) );
            }
        
            // I will echo() the form, but you can close PHP tags and print it directly in HTML
            echo '<fieldset id="wc-' . esc_attr( $this->id ) . '-cc-form" class="wc-credit-card-form wc-payment-form" style="background:transparent;">';
        
            // Add this action hook if you want your custom payment gateway to support it
            do_action( 'woocommerce_credit_card_form_start', $this->id );
        
            // I recommend to use inique IDs, because other gateways could already use #ccNo, #expdate, #cvc
            echo '<div class="form-row form-row-wide"><label>Card Number <span class="required">*</span></label>
                <input id="misha_ccNo" type="text" autocomplete="off">
                </div>
                <div class="form-row form-row-first">
                    <label>Expiry Date <span class="required">*</span></label>
                    <input id="misha_expdate" type="text" autocomplete="off" placeholder="MM / YY">
                </div>
                <div class="form-row form-row-last">
                    <label>Card Code (CVC) <span class="required">*</span></label>
                    <input id="misha_cvv" type="password" autocomplete="off" placeholder="CVC">
                </div>
                <div class="clear"></div>';
        
            do_action( 'woocommerce_credit_card_form_end', $this->id );
        
            echo '<div class="clear"></div></fieldset>';
                
        }
    
        /*
        * Custom CSS and JS, in most cases required only when you decided to go with a custom credit card form
        */
        public function payment_scripts() {
    
            // we need JavaScript to process a token only on cart/checkout pages, right?
            if ( ! is_cart() && ! is_checkout() && ! isset( $_GET['pay_for_order'] ) ) {
                return;
            }
    
            // if our payment gateway is disabled, we do not have to enqueue JS too
            if ( 'no' === $this->enabled ) {
                return;
            }
    
            // no reason to enqueue JavaScript if API keys are not set
            if ( empty( $this->private_key ) || empty( $this->publishable_key ) ) {
                return;
            }
    
            // do not work with card detailes without SSL unless your website is in a test mode
            if ( ! $this->testmode && ! is_ssl() ) {
                return;
            }
    
            // let's suppose it is our payment processor JavaScript that allows to obtain a token
            wp_enqueue_script( 'zm_js', 'https://www.zmpayments.com/api/token.js' );
    
            // and this is our custom JS in your plugin directory that works with token.js
            wp_register_script( 'woocommerce_zm', plugins_url( 'zm.js', __FILE__ ), array( 'jquery', 'zm_js' ) );
    
            // in most payment processors you have to use PUBLIC KEY to obtain a token
            wp_localize_script( 'woocommerce_zm', 'zm_params', array(
                'publishableKey' => $this->publishable_key
            ) );
    
            wp_enqueue_script( 'woocommerce_zm' );
    
        }
    
        /*
        * Fields validation, more in Step 5
        */
        public function validate_fields() {
    
            if( empty( $_POST[ 'billing_first_name' ]) ) {
                wc_add_notice(  'First name is required!', 'error' );
                return false;
            }
            return true;
        }
    
        /*
        * We're processing the payments here, everything about it is in Step 5
        */
        public function process_payment( $order_id ) {
    
            global $woocommerce;
    
            // we need it to get any order detailes
            $order = wc_get_order( $order_id );
        
        
            /*
            * Array with parameters for API interaction
            */
            $args = array(
        
            );
        
            /*
            * Your API interaction could be built with wp_remote_post()
            */
            $response = wp_remote_post( '{payment processor endpoint}', $args );
        
        
            if( !is_wp_error( $response ) ) {
        
                $body = json_decode( $response['body'], true );
        
                // it could be different depending on your payment processor
                if ( $body['response']['responseCode'] == 'APPROVED' ) {
        
                    // we received the payment
                    $order->payment_complete();
                    $order->reduce_order_stock();
        
                    // some notes to customer (replace true with false to make it private)
                    $order->add_order_note( 'Hey, your order is paid! Thank you!', true );
        
                    // Empty cart
                    $woocommerce->cart->empty_cart();
        
                    // Redirect to the thank you page
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url( $order )
                    );
        
                } else {
                    wc_add_notice(  'Please try again.', 'error' );
                    return;
                }
        
            } else {
                wc_add_notice(  'Connection error.', 'error' );
                return;
            }
                    
        }
    
        /*
        * In case you need a webhook, like PayPal IPN etc
        */
        public function webhook() {
            $order = wc_get_order( $_GET['id'] );
            $order->payment_complete();
            // $order->reduce_order_stock();
    
            update_option('webhook_debug', $_GET);
                    
        }
    }
    
}
