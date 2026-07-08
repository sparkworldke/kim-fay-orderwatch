<?php

class ZMSendy{
    private $api_password = 'A77t003asy';
    private $api_token = '3682f52a716d6c3430c13879adabbfcbxOg2ykAvpp1KWLzhj6gaLesp0gMnKuhD0IysT4xVvKWefOQf1TaTqNVkT7izKCMv';
    private $endpoint_url = '';
    private $is_sandbox = TRUE;
    function __construct(){
        if($this->is_sandbox){
            $this->endpoint_url = 'https://apitest.sendyit.com/v2/';
        }else{
            $this->endpoint_url = 'https://api.sendyit.com/v2/';
        }
        
    }
    function contact_sendy($endpoint,$method,$data){
        $ch = curl_init();
        $url = $this->endpoint_url.$endpoint;
        
        curl_setopt($ch, CURLOPT_URL,$url);
        if($method=='POST'){
            curl_setopt($ch, CURLOPT_POST, 1);
            //curl_setopt($ch, CURLOPT_POSTFIELDS,json_encode($data));
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }elseif($method=='PUT'){
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS,$data);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type:application/json',
            'Authorization: Bearer '.$this->api_token,
        ]);
        // curl_setopt($ch, CURLOPT_STDERR, $verbose = fopen('php://temp', 'rw+'),);

        $server_output = curl_exec($ch);
        // echo "Verbose information:\n", !rewind($verbose), stream_get_contents($verbose), "\n";
        // var_dump($server_output);
        curl_close($ch);
        return json_decode($server_output,true);
    }
    function get_price_quote($cart_ref,$delivery_location,$size_type='small'){
        $pickup_location = [

        ];
        $payload = array(
            "ecommerce_order"=> $cart_ref,
            "recipient"=> array(
              "name"=> "Kiragu",
              "email"=> "generalmanyara@gmail.com",
              "phone"=> "254722275262"
            ),
            "order_type_tag"=> "dedicated_order",
            "locations"=> array(
              array(
                "type"=> "PICKUP",
                "lat"=> -1.3315932,
                "long"=> 36.8637092,
                "name"=> "Kimfay Head Office, Libra House, Mombasa Rd."
              ),
              $delivery_location
            )
        );
        // print_r($payload);
        $quote = $this->contact_sendy('price-request','POST',$payload);
        
        return $quote;
    }

    function initiate_delivery($package,$coords){
        
        $delivery_location = array(
            "type"=> "DELIVERY",
            "lat"=> $coords[0],
            "long"=> $coords[1],
            "name"=> $package['shipping']['address_1']
        );
        $delivery_rates = $this->get_price_quote(time().'',$delivery_location);
        $new_cost = $delivery_rates['data']['economy_price_tiers'][0]['price_tiers'][0];

        $params = array(
            "pricing_uuid"=> $new_cost['id'],
            "payment_option"=> 2, //from settings endpoint
            "carrier_type"=> 2,//from settings endpoint
        );
        $result = $this->contact_sendy('orders','POST',$params);
        return $result;
    }

}