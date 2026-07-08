<?php

require_once __DIR__ . '/class-helper.php';
require_once __DIR__ . '/class-accumatica-db-ops.php';

class Accumatica
{
    /**
     * Sandbox base url
     * @var string client_key 
     */
    const SANDBOX_BASE_URL = 'https://acumatica.kimfay.com/AcumaticaERP2023';
    const SANDBOX_BASE_ENDPOINT = '/entity/IpayV2/22.200.001/PosOrder/';

    /**
     * Prodcution base url
     * @var string client_key 
     */
    const PRODUCTION_BASE_URL = 'https://erp.kimfay.com/AcumaticaERP';
    const PRODUCTION_BASE_ENDPOINT = '/entity/IpayV2/22.200.001/PosOrder/';


    /**
     * @var string client_key 
     */
    private string $client_key;

    /**
     * @var string client_secret 
     */
    private string $client_secret;

    /**
     * @var string client_username 
     */
    private string $client_username;

    /**
     * @var string client_password 
     */
    private string $client_password;

    /**
     * @var string inventory_id 
     */
    private string $inventory_id;

    /**
     * @var string customer 
     */
    private string $customer;

    /**
     * @var string customer 
     */
    private string $payment_method;

    /**
     * @var string auth_url 
     */
    private string $auth_url;

    /**
     * @var string order_url 
     */
    private string $order_url;

    /**
     * @var string access_token 
     */
    private string $access_token;

    /**
     * @var TableOperations db_ops 
     */
    private $db_ops;

    public function __construct(string $mode, string $client_key, string $client_secret, string $client_username, string $client_password, string $inventory_id, string $customer, string $payment_method = 'IPAY', string $liveAuthUrl = null, string $liveOrderUrl = null)
    {
        // check if live auth url are provided
        [$this->auth_url, $this->order_url] = match ($mode == 'yes') {
            true => match (!empty($liveAuthUrl) && !empty($liveOrderUrl)) {
                true => [$liveAuthUrl, $liveOrderUrl],
                default => [self::PRODUCTION_BASE_URL . '/identity/connect/token', self::PRODUCTION_BASE_URL . self::PRODUCTION_BASE_ENDPOINT]
            },
            default => [self::SANDBOX_BASE_URL . '/identity/connect/token', self::SANDBOX_BASE_URL . self::SANDBOX_BASE_ENDPOINT]
        };

        $this->client_key = $client_key;
        $this->client_secret = $client_secret;
        $this->client_username = $client_username;
        $this->client_password = $client_password;
        $this->inventory_id = $inventory_id;
        $this->customer = $customer;
        $this->payment_method = $payment_method;

        $this->db_ops = new TableOperations();
    }

    private function getAccessToken()
    {
        // get the current active access token
        $accessToken = $this->db_ops->fetchTokenById();

        // if token is valid use it else make request to generate a new one
        if (!is_null($accessToken) && !empty($accessToken->access_token) && $accessToken->expire_in > Helper::currentDateTime()) {
            $token = $accessToken->access_token;
        } else {
            $token = '';

            # object is not found in the db
            if (is_null($accessToken)) {
                $token = $this->authenticate();
                $this->db_ops->saveToken($token);
            }

            # fresh plugin installation 
            if (empty($accessToken->access_token)) {
                $token = $this->authenticate();
                $this->db_ops->updateToken($token);
            }

            # token is expired
            if ($accessToken->expire_in < Helper::currentDateTime()) {
                $token = $this->authenticate();
                $this->db_ops->updateToken($token);
            }
        }

        // return the token
        return $token;
    }

    private function authenticate()
    {
        // request to get access token
        $response = Helper::curlRequest(
            url: $this->auth_url,
            method: 'POST',
            payload: http_build_query([
                'grant_type' => 'password',
                'username' => $this->client_username,
                'password' => $this->client_password,
                'scope' => 'api',
                'client_id' => $this->client_key,
                'client_secret' => $this->client_secret,
            ]),
            headers: ['Content-Type: application/x-www-form-urlencoded']
        );

        // log accumatica response
        Helper::logger(["AuthResponse::" => $response]);

        // check if respose is successful
        return isset($response['access_token']) && !empty($response['access_token']) ? $response['access_token'] : '';
    }

    public function createOrder(array $orderItems, $order_id)
    {
        $payload = [];

        $payload['Customer'] = ['value' => $this->customer];

        foreach ($orderItems as $item) {

            $product = wc_get_product($item->get_product_id());

            $payload['DocumentDetails'][] = [
                'InventoryID' => ['value' => $product->get_sku()],
                'Quantity' => ['value' => $item->get_quantity()],
                //'UnitPrice' => ['value' => $item->get_total()],
            ];
        }
        // log execution stage
        Helper::logger($payload, "stage1::CreateOrderReq::iPayRequest::url->{$this->order_url}::");

        // request to create a order
        $stage_1_response = Helper::curlRequest(
            url: $this->order_url . '?',
            method: 'PUT',
            payload: json_encode($payload),
            headers: [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->getAccessToken()
            ]
        );

        // log accumatica response
        Helper::logger($stage_1_response, 'stage1::CreateOrderRes::AccumaticaRespose');

        // check if request is successful
        if (isset($stage_1_response['error']) || !isset($stage_1_response['id']) || !isset($stage_1_response['OrderNbr']['value'])) {
            return false;
        }

        // extract the required details from api respone
        $order_number = $stage_1_response['OrderNbr']['value'];

        // get order details
        $order_details = $this->getOrder($order_number);


        // extract payment id from api response
        $payment_id = isset($order_details[0]['PaymentDetails'][0]['id']) && !empty($order_details[0]['PaymentDetails'][0]['id'])
            ? $order_details[0]['PaymentDetails'][0]['id']
            : null;

        // extract customer id from api response
        $customer_id = isset($order_details[0]['CustomerDetails'][0]['id']) && !empty($order_details[0]['CustomerDetails'][0]['id'])
            ? $order_details[0]['CustomerDetails'][0]['id']
            : null;

        // extract total amount from api response

        $total = $order_details[0]['OrderTotal']['value'] ?? 0.0;

        // Ensure $order_id is available and valid
        if (!$order_id) {
            // Log error or handle the case where order_id is not set
            error_log('Order ID is not set');
            return false;
        }

        try {
            // Retrieve WooCommerce order
            $wc_order = wc_get_order($order_id);

            if ($wc_order) {
                // Get shipping total
                $shipping_total = $wc_order->get_shipping_total();

                // Add shipping cost to the total
                $total += $shipping_total;
            } else {
                // Log that WooCommerce order could not be retrieved
                error_log('Could not retrieve WooCommerce order for ID: ' . $order_id);
            }
        } catch (Exception $e) {
            // Log any exceptions
            error_log('Error retrieving shipping cost: ' . $e->getMessage());
        }

        // store order id, payment id and customer id in the db
        $local_store = $this->db_ops->createNewTransaction(
            accOrderId: $stage_1_response['id'],
            accPaymentId: $payment_id,
            accCustomerId: $customer_id,
            wcOrderId: $order_id
        );

        // return the computed total plus vat from accumatica
        return [$total, $local_store];
    }

    public function getOrder(string $order_number)
    {
        // create query params for required data from api
        $query_params = [
            '$expand' => 'PaymentDetails,DocumentDetails,CustomerDetails',
            '$filter' => 'OrderNbr eq ' . "'" . $order_number . "'",
        ];

        $url = $this->order_url . '?' . http_build_query($query_params, '', '&');

        // log action
        Helper::logger($url, "stage2::getOrderReq::iPayRequest::url->{$url}::");

        // request to get order details
        $stage_2_response = Helper::curlRequest(
            url: $url,
            method: 'GET',
            headers: [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->getAccessToken()
            ]
        );

        // log execution stage
        Helper::logger($stage_2_response, 'Stage2::getOrderRes::AccumaticaResponse');

        // return order details
        return $stage_2_response;
    }

    public function confirmPayment(
        string $accumatica_order_id,
        string $accumatica_payment_id,
        string $accumatica_customer_id,
        string $wc_order_id,
        string $billingEmail,
        string $billingAddress1,
        string $billingAddress2,
        string $billingPhoneNumber,
        string $billingName = '',
        string $billingCountry = '',
        string $billingCity = '',
        string $billingState = '',
        string $billingPostalCode = '',
        string $shippingName = '',
        string $shippingAddress1 = '',
        string $shippingAddress2 = '',
        string $shippingCity = '',
        string $shippingState = '',
        string $shippingPostalCode = '',
        string $shippingPostalCountry = '',
        string $shippingTotal = '',
        string $shippingTax = '',
    ) {
        // create query params for the request data from the api
        $query_params = [
            '$expand' => 'PaymentDetails,CustomerDetails'
        ];

        // build the payload body
        $details = [
            'id' => $accumatica_order_id,
            'PaymentDetails' => [
                [
                    'id' => $accumatica_payment_id,
                    'PaymentMethod' => ['value' => $this->payment_method],
                    'PaymentRef' => ["value" => $wc_order_id]
                ]
            ],
            'CustomerDetails' => [
                [
                    'id' => $accumatica_customer_id,
                    'AccountEmail' => ['value' => $billingEmail],
                    'AccountName' => ['value' => $billingName],
                    'AddressLine1' => ['value' => $billingAddress1],
                    'AddressLine2' => ['value' => $shippingAddress1],
                    'Phone' => ['value' => $billingPhoneNumber],
                    // 'custom' => [
                    //     'billingName' => $billingName,
                    //     'billingCity' => $billingCity,
                    //     'billingState' => $billingState,
                    //     'billingEmail' => $billingEmail,
                    //     'billingCountry' => $billingCountry,
                    //     'billingPostalCode' => $billingPostalCode,
                    //     'shippingName' => $shippingName,
                    //     'shippingAddress1' => $shippingAddress1,
                    //     'shippingAddress2' => $shippingAddress2,
                    //     'shippingCity' => $shippingCity,
                    //     'shippingState' => $shippingState,
                    //     'shippingPostalCode' => $shippingPostalCode,
                    //     'shippingPostalCountry' => $shippingPostalCountry,
                    //     'shippingTotal' => $shippingTotal,
                    //     'shippingTax' => $shippingTax,
                    // ]
                ]
            ],
        ];

        $url = $this->order_url . '?' . http_build_query($query_params);

        // log execution stage
        Helper::logger($details, "stage3::confirmPaymentReq::iPayRequest::url->{$url}::");

        // make request to confirm payment
        $stage_3_response = Helper::curlRequest(
            url: $url,
            method: 'PUT',
            payload: json_encode($details),
            headers: [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->getAccessToken()
            ]
        );

        // log accumatica response
        Helper::logger($stage_3_response, 'stage3::confirmPaymentRes::AccumaticaResponse');

        //stage 4 send payment ref
        $paymentref_details = [
            'id' => $accumatica_order_id,
            'PaymentDetails' => [
                [
                    'id' => $accumatica_payment_id,
                    'PaymentRef' => ["value" => $wc_order_id]
                ]
            ],

        ];
        // log execution stage
        Helper::logger($paymentref_details, "stage4::confirmPaymentReq::iPayRequest::url->{$url}::");

        // make request to confirm payment
        $stage_4_response = Helper::curlRequest(
            url: $url,
            method: 'PUT',
            payload: json_encode($paymentref_details),
            headers: [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->getAccessToken()
            ]
        );

        // log accumatica response
        Helper::logger($stage_4_response, 'stage4::confirmPaymentRes::AccumaticaResponse');

        // return the response
        return $stage_4_response;
    }

    public function debugFn()
    {
        Helper::logger('TEST', 'Debuging');

        return [2, 'test'];
    }
}
