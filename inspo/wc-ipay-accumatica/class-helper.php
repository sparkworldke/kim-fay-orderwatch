<?php

class Helper
{
    public static function curlRequest(string $url, string $method, string $payload = '', array $headers = [])
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        $response_array = json_decode($response, true);

        return !is_null($response_array) ? $response_array : $response;
    }

    public static function logger($log_msg, $type = 'callback')
    {
        
        $data = is_array($log_msg) ? json_encode($log_msg) : $log_msg;
        $plg_path = plugin_dir_path(__FILE__);
        $log_filename = $plg_path . 'log';
    
        if (!file_exists($log_filename)) {
            // create directory/folder if it doesn't exist.
            mkdir($log_filename, 0777, true);
        }
    
        // Create a file name with the current date
        $current_date = date('Y-m-d');
        $log_file_data = $log_filename . '/app-' . $current_date . '.log';
    
        $content = '[' . date("Y-m-d H:i:s") . ']' . '::' . $type . '::' . $data;
    
        // Append the log message to the file
        file_put_contents($log_file_data, $content . "\n", FILE_APPEND);
    }

    public static function timerIncrement()
    {
        date_default_timezone_set('Africa/Nairobi');
        date_default_timezone_get();

        $date = new \DateTime(); //now
        $date->add(new \DateInterval('PT1H'));

        return $date->format('Y-m-d H:i:s');
    }

    public static function currentDateTime()
    {
        date_default_timezone_set('Africa/Nairobi');
        date_default_timezone_get();

        $date = new \DateTime(); //now
        return $date->format('Y-m-d H:i:s');
    }

    public static function dd()
    {
        array_map(function ($content) {
            echo "<pre>";
            var_dump($content);
            echo "</pre>";
            echo "<hr>";
        }, func_get_args());
    }
}
