<?php

/**
 * @package Zero Margin Acumatica Connector
 * @version 0.1.0
 */
/*
Plugin Name: Acumatica Connector
Description: Custom Acumatica ERP connector for KimFay
Author: Zero Margin
Version: 0.1.0
Author URI: https://zeromargin.co.ke
*/

// include("../../../wp-includes/media.php");

include_once(__DIR__ . "/../../../wp-admin/includes/media.php");
include_once(__DIR__ . "/../../../wp-admin/includes/file.php");
include_once(__DIR__ . "/../../../wp-admin/includes/image.php");
require_once(__DIR__ . "/sendy.php");
require_once(__DIR__ . "/vendor/autoload.php");

use Geocoder\Query\GeocodeQuery;
use JsonMachine\Items;

class AcumaticaConnector
{
    private $erp_url;
    private $last_erp_response_meta = array();
    const DEFAULT_BASE_URL = 'https://erp.kimfay.com/AcumaticaERP';
    const INVENTORY_SYNC_EVENT_6AM = 'zm_ac_inventory_sync_6am';
    const INVENTORY_SYNC_EVENT_1PM = 'zm_ac_inventory_sync_1pm';
    const INVENTORY_SYNC_EVENT_6PM = 'zm_ac_inventory_sync_6pm';
    const INVENTORY_SYNC_BATCH_EVENT = 'zm_ac_inventory_sync_batch';
    const INVENTORY_SYNC_BATCH_SIZE = 100;

    function __construct()
    {
        add_action('init', array($this, 'init'), 100);
        add_action('init', array($this, 'add_taxonomies'), 0);
        add_action('admin_menu', array($this, 'admin_menu'), 0);
        add_action('age_group_add_form_fields', array($this, 'add_term_fields'));
        add_action('age_group_edit_form_fields', array($this, 'edit_term_fields'), 10, 2);
        add_action('created_age_group', array($this, 'save_term_fields'));
        add_action('edited_age_group', array($this, 'save_term_fields'));

        add_filter('woocommerce_shipping_fields', array($this, 'update_shipping_city_state_values'));
        add_action('woocommerce_thankyou', array($this, 'custom_woocommerce_auto_complete_order'));
        add_filter('woocommerce_product_query_tax_query', array($this, 'filter_woocommerce_product_query_tax_query'), 10, 2);

        add_action('admin_init', array($this, 'register_plugin_settings'));
        add_action('admin_post_zm_acumatica_manual_inventory_sync', array($this, 'manual_inventory_sync'));



        add_filter('cron_schedules', array($this, 'zm_cron'));
        add_action('zm_cron', array($this, 'zm_5min_cron'));
        add_action('zm_cron_hour', array($this, 'zm_1h_cron'));
        add_action(self::INVENTORY_SYNC_EVENT_6AM, array($this, 'run_inventory_sync_6am'));
        add_action(self::INVENTORY_SYNC_EVENT_1PM, array($this, 'run_inventory_sync_1pm'));
        add_action(self::INVENTORY_SYNC_EVENT_6PM, array($this, 'run_inventory_sync_6pm'));
        add_action(self::INVENTORY_SYNC_BATCH_EVENT, array($this, 'process_inventory_sync_batch'));
        if (! wp_next_scheduled('zm_cron')) {
            wp_schedule_event(time(), 'every_five_minutes', 'zm_cron');
        }
        if (! wp_next_scheduled('zm_cron_hour')) {
            wp_schedule_event(time(), 'every_hour', 'zm_cron_hour');
        }
        $this->schedule_inventory_sync_events();

        $this->erp_url = get_option('zm_ac_base_url', false);
    }


    function admin_menu()
    {
        add_options_page(
            'Acumatica Settings',
            'Acumatica Settings',
            'manage_options',
            'zm_acumatica',
            array($this, 'options_page')
        );
    }
    function acumatica_settings_url($status = '')
    {
        $url = 'https://fayshop.co.ke/wp-admin/options-general.php?page=zm_acumatica';

        if ($status !== '') {
            $url = add_query_arg('acumatica_connection', $status, $url);
        }

        return $url;
    }
    function is_acumatica_connected()
    {
        $token = get_option('zm_acumatica_token', false);

        return !empty($token) && !empty($token['access_token']);
    }
    function init()
    {
        if (isset($_GET['import_data'])) {
            $this->do_import();
            exit();
        }
        if (isset($_GET['import_ac_images'])) {
            $this->update_photos();
            exit();
        }
        if (isset($_GET['clear_products'])) {
            echo "<pre> Clearing data\n";
            $this->clear_all_products();

            exit();
        }
        if (isset($_GET['connect_erp'])) {
            $this->login();
            exit();
        }
        if (isset($_GET['erp_code'])) {
            $this->get_token();
        }
        if (isset($_GET['erp_test'])) {
            $this->erp_token();
            exit();
        }
        if (isset($_GET['show_ac_token'])) {
            echo ">>>>";
            print_r($this->erp_token());
            exit();
        }
        if (isset($_GET['get_pricing'])) {
            echo ">>>>";
            print_r($this->fetch_prices());
            exit();
        }
        if (isset($_GET['regenerate_thumbs'])) {
            echo ">>>>";
            print_r($this->regenerateThumbnails());
            exit();
        }
    }
    function acumatica_base_url()
    {
        $base_url = get_option('zm_ac_base_url', false);

        if (!$base_url) {
            $base_url = self::DEFAULT_BASE_URL;
        }

        return rtrim($base_url, '/');
    }
    function schedule_inventory_sync_events()
    {
        $events = array(
            self::INVENTORY_SYNC_EVENT_6AM => array(6, 0),
            self::INVENTORY_SYNC_EVENT_1PM => array(13, 0),
            self::INVENTORY_SYNC_EVENT_6PM => array(18, 0),
        );

        foreach ($events as $hook => $time_parts) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(
                    $this->next_eat_timestamp($time_parts[0], $time_parts[1]),
                    'daily',
                    $hook
                );
            }
        }
    }
    function next_eat_timestamp($hour, $minute = 0)
    {
        $timezone = new DateTimeZone('Africa/Nairobi');
        $now = new DateTime('now', $timezone);
        $next_run = new DateTime('now', $timezone);
        $next_run->setTime($hour, $minute, 0);

        if ($next_run <= $now) {
            $next_run->modify('+1 day');
        }

        return $next_run->getTimestamp();
    }
    function log_directory()
    {
        return __DIR__ . '/log';
    }
    function ensure_log_directory()
    {
        $log_dir = $this->log_directory();

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        return $log_dir;
    }
    function write_error_log($message, $context = 'general')
    {
        $log_dir = $this->ensure_log_directory();
        if (!is_dir($log_dir) || !is_writable($log_dir)) {
            return;
        }

        $timezone = new DateTimeZone('Africa/Nairobi');
        $now = new DateTime('now', $timezone);
        $filename = $log_dir . '/' . $now->format('d-m-Y') . '.txt';
        $line = sprintf(
            "[%s] [%s] %s%s",
            $now->format('Y-m-d H:i:s T'),
            $context,
            str_replace(array("\r", "\n"), ' ', $message),
            PHP_EOL
        );

        error_log($line, 3, $filename);
    }
    function write_sync_event_log($status, $message, $updated = 0, $trigger = 'manual', $extra = array())
    {
        $log_dir = $this->ensure_log_directory();
        if (!is_dir($log_dir) || !is_writable($log_dir)) {
            return;
        }

        $timezone = new DateTimeZone('Africa/Nairobi');
        $now = new DateTime('now', $timezone);
        $filename = $log_dir . '/sync-events-' . $now->format('d-m-Y') . '.txt';
        $parts = array(
            'Status: ' . $status,
            'Trigger: ' . $trigger,
            'Updated: ' . $updated,
            'Message: ' . str_replace(array("\r", "\n"), ' ', $message),
        );

        foreach ($extra as $key => $value) {
            $parts[] = $key . ': ' . str_replace(array("\r", "\n"), ' ', (string) $value);
        }

        $line = sprintf(
            "[%s] %s%s",
            $now->format('Y-m-d H:i:s T'),
            implode(' | ', $parts),
            PHP_EOL
        );

        error_log($line, 3, $filename);
    }
    function write_inventory_update_log($product_id, $product_name, $sku, $previous_stock_qty, $stock_qty, $stock_status, $trigger = 'manual')
    {
        $log_dir = $this->ensure_log_directory();
        if (!is_dir($log_dir) || !is_writable($log_dir)) {
            return;
        }

        $timezone = new DateTimeZone('Africa/Nairobi');
        $now = new DateTime('now', $timezone);
        $filename = $log_dir . '/items-updated-' . $now->format('d-m-Y') . '.txt';
        $line = sprintf(
            "[%s] [inventory-sync:%s] Product ID: %s | Product: %s | SKU: %s | Previous Qty: %s | New Qty: %s | Status: %s%s",
            $now->format('Y-m-d H:i:s T'),
            $trigger,
            $product_id,
            str_replace(array("\r", "\n"), ' ', $product_name),
            $sku,
            $previous_stock_qty === null ? 'null' : $previous_stock_qty,
            $stock_qty,
            $stock_status,
            PHP_EOL
        );

        error_log($line, 3, $filename);
    }
    function update_product_sync_meta($product_id, $sku, $previous_stock_qty, $stock_qty, $stock_status, $trigger = 'manual')
    {
        update_post_meta($product_id, 'zm_ac_last_synced_sku', $sku);
        update_post_meta($product_id, 'zm_ac_last_synced_previous_qty', $previous_stock_qty);
        update_post_meta($product_id, 'zm_ac_last_synced_qty', $stock_qty);
        update_post_meta($product_id, 'zm_ac_last_synced_status', $stock_status);
        update_post_meta($product_id, 'zm_ac_last_synced_trigger', $trigger);
        update_post_meta($product_id, 'zm_ac_last_synced_at', current_time('timestamp'));
    }
    function get_last_synced_products($limit = 100)
    {
        return wc_get_products(array(
            'limit' => $limit,
            'status' => array('publish', 'private', 'draft'),
            'meta_key' => 'zm_ac_last_synced_at',
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
            'return' => 'objects',
            'meta_query' => array(
                array(
                    'key' => 'zm_ac_last_synced_at',
                    'compare' => 'EXISTS',
                ),
            ),
        ));
    }
    function set_inventory_sync_status($status, $message, $updated = 0, $trigger = 'manual')
    {
        update_option('zm_ac_inventory_last_sync', array(
            'status' => $status,
            'message' => $message,
            'updated' => (int) $updated,
            'trigger' => $trigger,
            'timestamp' => current_time('timestamp'),
        ));

        $this->write_sync_event_log($status, $message, $updated, $trigger);

        if ($status === 'error') {
            $this->write_error_log($message, 'inventory-sync:' . $trigger);
        }
    }
    function get_inventory_sync_queue()
    {
        $queue = get_option('zm_ac_inventory_sync_queue', false);

        return is_array($queue) ? $queue : false;
    }
    function update_inventory_sync_queue($queue)
    {
        update_option('zm_ac_inventory_sync_queue', $queue, false);
    }
    function clear_inventory_sync_queue()
    {
        delete_option('zm_ac_inventory_sync_queue');
    }
    function queue_inventory_sync($trigger = 'manual')
    {
        $existing_queue = $this->get_inventory_sync_queue();
        if ($existing_queue && !empty($existing_queue['status']) && $existing_queue['status'] === 'running') {
            $message = 'Inventory sync is already running in the background.';
            $this->set_inventory_sync_status('queued', $message, (int) ($existing_queue['updated'] ?? 0), $trigger);
            return array('success' => true, 'queued' => true, 'message' => $message);
        }

        $endpoint_base = get_option('zm_ac_endpoint', false);
        if (!$endpoint_base) {
            $message = 'Acumatica endpoint is not configured.';
            $this->set_inventory_sync_status('error', $message, 0, $trigger);
            return array('success' => false, 'queued' => false, 'message' => $message);
        }

        $queue = array(
            'status' => 'running',
            'trigger' => $trigger,
            'offset' => 0,
            'limit' => self::INVENTORY_SYNC_BATCH_SIZE,
            'updated' => 0,
            'started_at' => current_time('timestamp'),
        );

        $this->update_inventory_sync_queue($queue);
        $this->set_inventory_sync_status('queued', 'Inventory sync has been queued and will run in the background.', 0, $trigger);
        $this->write_sync_event_log('queued', 'Inventory sync queue created.', 0, $trigger, array(
            'Offset' => 0,
            'Batch Size' => self::INVENTORY_SYNC_BATCH_SIZE,
        ));

        if (!wp_next_scheduled(self::INVENTORY_SYNC_BATCH_EVENT)) {
            wp_schedule_single_event(time() + 5, self::INVENTORY_SYNC_BATCH_EVENT);
        }

        return array('success' => true, 'queued' => true, 'message' => 'Inventory sync has been queued and will run in the background.');
    }
    function process_inventory_sync_batch()
    {
        $queue = $this->get_inventory_sync_queue();
        if (!$queue || empty($queue['status']) || $queue['status'] !== 'running') {
            return;
        }

        $products = $this->prefetch_products();
        if (empty($products)) {
            $this->clear_inventory_sync_queue();
            $this->set_inventory_sync_status('success', 'No WooCommerce products found to sync.', 0, $queue['trigger']);
            return;
        }

        $endpoint_base = get_option('zm_ac_endpoint', false);
        if (!$endpoint_base) {
            $this->clear_inventory_sync_queue();
            $this->set_inventory_sync_status('error', 'Acumatica endpoint is not configured.', 0, $queue['trigger']);
            return;
        }

        $offset = (int) ($queue['offset'] ?? 0);
        $limit = (int) ($queue['limit'] ?? self::INVENTORY_SYNC_BATCH_SIZE);
        $trigger = $queue['trigger'] ?? 'manual';
        $endpoint = '/' . trim($endpoint_base, '/') . '/stockItem?$expand=WarehouseDetails&$filter=DefaultWarehouseID+eq+\'FGS\'&$top=' . $limit . '&$skip=' . $offset;
        $json = $this->contact_erp($endpoint, 'GET', null);

        if (!is_string($json) || trim($json) === '') {
            $message = $this->last_erp_error_message();
            $this->clear_inventory_sync_queue();
            $this->set_inventory_sync_status('error', $message, (int) ($queue['updated'] ?? 0), $trigger);
            return;
        }

        $batch_items = json_decode($json, true);
        if (!is_array($batch_items)) {
            $message = 'Acumatica returned invalid inventory JSON.';
            $this->clear_inventory_sync_queue();
            $this->set_inventory_sync_status('error', $message, (int) ($queue['updated'] ?? 0), $trigger);
            return;
        }

        if (isset($batch_items['error']) && !is_array($batch_items['error'])) {
            $message = isset($batch_items['error_description']) ? $batch_items['error_description'] : 'Acumatica returned an authentication error.';
            $this->clear_inventory_sync_queue();
            $this->set_inventory_sync_status('error', $message, (int) ($queue['updated'] ?? 0), $trigger);
            return;
        }

        if (isset($batch_items['message']) && is_string($batch_items['message'])) {
            $this->clear_inventory_sync_queue();
            $this->set_inventory_sync_status('error', $batch_items['message'], (int) ($queue['updated'] ?? 0), $trigger);
            return;
        }

        $updated_in_batch = 0;
        foreach ($batch_items as $product_) {
            if (
                !is_array($product_) ||
                empty($product_['InventoryID']['value']) ||
                !isset($products[$product_['InventoryID']['value']])
            ) {
                continue;
            }

            $stock_amt = 0;
            if (!empty($product_['WarehouseDetails']) && is_array($product_['WarehouseDetails'])) {
                foreach ($product_['WarehouseDetails'] as $warehouse_detail) {
                    if (
                        !empty($warehouse_detail['WarehouseID']['value']) &&
                        $warehouse_detail['WarehouseID']['value'] === 'FGS' &&
                        isset($warehouse_detail['QtyOnHand']['value'])
                    ) {
                        $stock_amt += (float) $warehouse_detail['QtyOnHand']['value'];
                    }
                }
            }

            $wc_product = $products[$product_['InventoryID']['value']];
            $previous_stock_qty = $wc_product->get_stock_quantity();
            $wc_product->set_manage_stock(true);
            $wc_product->set_stock_quantity($stock_amt);
            $wc_product->set_stock_status($stock_amt > 0 ? 'instock' : 'outofstock');
            $wc_product->save();
            $this->update_product_sync_meta(
                $wc_product->get_id(),
                $product_['InventoryID']['value'],
                $previous_stock_qty,
                $stock_amt,
                $stock_amt > 0 ? 'instock' : 'outofstock',
                $trigger
            );
            $this->write_inventory_update_log(
                $wc_product->get_id(),
                $wc_product->get_name(),
                $product_['InventoryID']['value'],
                $previous_stock_qty,
                $stock_amt,
                $stock_amt > 0 ? 'instock' : 'outofstock',
                $trigger
            );
            $updated_in_batch++;
        }

        $queue['offset'] = $offset + $limit;
        $queue['updated'] = (int) ($queue['updated'] ?? 0) + $updated_in_batch;

        if (count($batch_items) < $limit) {
            $message = sprintf('Inventory sync completed in background. %d product(s) updated.', $queue['updated']);
            $this->clear_inventory_sync_queue();
            $this->set_inventory_sync_status('success', $message, $queue['updated'], $trigger);
            return;
        }

        $this->update_inventory_sync_queue($queue);
        $this->set_inventory_sync_status('running', sprintf('Inventory sync is running in background. %d product(s) updated so far.', $queue['updated']), $queue['updated'], $trigger);
        $this->write_sync_event_log('running', 'Inventory sync batch processed.', $queue['updated'], $trigger, array(
            'Batch Offset Start' => $offset,
            'Batch Size' => $limit,
            'Batch Updated' => $updated_in_batch,
            'Next Offset' => $queue['offset'],
        ));
        wp_schedule_single_event(time() + 5, self::INVENTORY_SYNC_BATCH_EVENT);
    }
    function last_erp_error_message($default_message = 'Empty response from Acumatica inventory endpoint.')
    {
        $meta = $this->last_erp_response_meta;

        if (!empty($meta['curl_error'])) {
            return 'Acumatica request failed: ' . $meta['curl_error'];
        }

        if (!empty($meta['http_code']) && (int) $meta['http_code'] >= 400) {
            return 'Acumatica returned HTTP ' . $meta['http_code'] . '.';
        }

        return $default_message;
    }
    function sync_inventory($trigger = 'manual')
    {
        return $this->queue_inventory_sync($trigger);
    }
    function run_inventory_sync_6am()
    {
        $this->sync_inventory('auto-6am-eat');
    }
    function run_inventory_sync_1pm()
    {
        $this->sync_inventory('auto-1pm-eat');
    }
    function run_inventory_sync_6pm()
    {
        $this->sync_inventory('auto-6pm-eat');
    }
    function manual_inventory_sync()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to run this sync.', 'acumatica_connector'));
        }

        check_admin_referer('zm_acumatica_manual_inventory_sync');

        $result = $this->sync_inventory('manual');
        $redirect_url = add_query_arg(
            array(
                'page' => 'zm_acumatica',
                'inventory_sync' => $result['success'] ? 'success' : 'error',
            ),
            admin_url('options-general.php')
        );

        wp_safe_redirect($redirect_url);
        exit();
    }
    function acumatica_shop()
    {
        return '<h1>OK!</h1>';
    }
    function geocode($location)
    {
        $httpClient = new GuzzleHttp\Client();
        $provider = new \Geocoder\Provider\GoogleMaps\GoogleMaps($httpClient, null, 'AIzaSyB_aOU8y_Rd-jT1HgixtHh006Ru8LQowaM');
        $geocoder = new \Geocoder\StatefulGeocoder($provider, 'en');

        $result = $geocoder->geocodeQuery(GeocodeQuery::create($location));
        //$result = $geocoder->reverseQuery(ReverseQuery::fromCoordinates(...));
        $coord = $result->first()->getCoordinates();
        $coordinates = [$coord->getLatitude(), $coord->getLongitude()];

        return $coordinates;
    }
    function login()
    {
        $url = $this->erp_url . '/identity/connect/authorize?response_type=code'
            . '&client_id=' . $this->client_id
            . '&redirect_uri=' . urlencode('https://fayshop.co.ke/shop/?erp_code=1')
            . '&scope=api%20offline_access';

        header('location: ' . $url);
    }
    function login_url()
    {
        $base_url = $this->acumatica_base_url();

        if ($base_url) {
            return $base_url . '/identity/connect/authorize?response_type=code'
                . '&client_id=' . get_option('zm_ac_client_id', false)
                . '&redirect_uri=' . urlencode('https://fayshop.co.ke/shop/?erp_code=1')
                . '&scope=api%20offline_access';
        }
        return '#';
    }
    function product_exists($sku)
    {
        // todo: find WC product by SKU
        $p = new WC_Product(wc_get_product_id_by_sku($sku));

        if ($p != null) {
            return $p;
        }
        return false;
    }
    function add_update_product($product_data)
    {

        $product = $this->product_exists($product_data['sku']);
        $is_new = false;
        if ($product == false) {
            $product = new WC_Product_Simple();
            $product->set_sku($product_data['sku']);
            $is_new = true;
        }
        $product->set_sku($product_data['sku']);
        if (isset($product_data['pageTitle']) and $product_data['pageTitle'] != null && $product_data['pageTitle'] != '') {
            $product->set_name($product_data['pageTitle']);
        } else {
            $product->set_name($product_data['name']);
        }


        $product->set_stock_status($product_data['stock_status']); // 'instock', 'outofstock' or 'onbackorder'
        $product->set_manage_stock(true);
        $product->set_stock_quantity($product_data['stock_amt']);

        $product->set_tax_class($product_data['tax_class']);
        update_post_meta($product->get_id(), 'SalesUOM', $product_data['uom']);
        if ($is_new) {
            $product->set_short_description(str_replace('â€™', "'", $product_data['metaDescription']));
            $product->set_description(str_replace('â€™', "'", $product_data['metaDescription']));
            $cats = explode('  ', $product_data['categories']);
            $cat_ids = array();
            foreach ($cats as $cat) {
                $cat = ucwords(strtolower(trim($cat)));
                if ($cat != '') {
                    $c_ = term_exists($cat, 'product_cat');
                    if ($c_ == null) {
                        $c_ = wp_insert_term(
                            $cat,
                            'product_cat',
                        );
                    }

                    $cat_ids[] = $c_['term_id'];
                }
            }
        }


        if ($is_new) {
            $product->set_category_ids($cat_ids);
            update_post_meta($product->get_id(), 'update_images', true);
            update_post_meta($product->get_id(), 'acumatica_files', $product_data['files']);
        }


        update_option('acumatica_next_price_update', 0);

        $product->save();

        if ($product_data['brand'] != null && $is_new) {
            wp_set_post_terms($product->get_id(), $product_data['brand'], 'brand');
        }
        if ($is_new) {
            update_post_meta($product->get_id(), '_yoast_wpseo_metadesc', str_replace('â€™', "'", $product_data['metaDescription']));
            update_post_meta($product->get_id(), '_yoast_wpseo_title', str_replace('â€™', "'", $product_data['pageTitle']));
        }
        return $product;
    }
    function clear_attachments($product_id)
    {
        $attachments = get_posts(array(
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_parent' => $product_id
        ));

        if ($attachments) {
            foreach ($attachments as $attachment) {
                try {
                    wp_delete_attachment($attachment->ID, true);
                } catch (Exception $e) {
                    echo 'Delete error: ' . $e->getMessage();
                    // print_r($json);
                }
            }
        }
    }
    function my_upload_image($file, $filename, $description)
    {
        echo $file . "\n";
        echo $filename . "\n";

        $file_array  = ['name' => wp_basename($file), 'tmp_name' => $this->download_url_with_headers($file, ['Authorization' => 'Bearer ' . $this->erp_token()])];
        print_r($file_array);
        // If error storing temporarily, return the error.
        if (is_wp_error($file_array['tmp_name'])) {

            return $file_array['tmp_name'];
        }
        print_r($file_array);
        // $file_array['type'] = (strpos($filename,'.png')>0)?'image/png':'image/jpeg';
        $file_array['name'] = $filename;
        print_r($file_array);

        // Do the validation and storage stuff.
        $id = media_handle_sideload($file_array, 0, $description);

        // If error storing permanently, unlink.
        if (is_wp_error($id)) {

            @unlink($file_array['tmp_name']);
            print_r($id);
            return false;
        }

        return $id;
    }
    function download_url_with_headers($url, $headers = [])
    {
        // WARNING: The file is not automatically deleted, the script must unlink() the file.
        if (! $url) {
            return new WP_Error('http_no_url', __('Invalid URL Provided.'));
        }

        $url_filename = basename(parse_url($url, PHP_URL_PATH));

        $tmpfname = wp_tempnam($url_filename);
        if (! $tmpfname) {
            return new WP_Error('http_no_file', __('Could not create Temporary file.'));
        }


        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'  => 600,
                'stream'   => true,
                'filename' => $tmpfname,
                'headers'  => $headers
            )
        );

        if (is_wp_error($response)) {
            unlink($tmpfname);
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if (200 != $response_code) {
            $data = array(
                'code' => $response_code,
            );

            $tmpf = fopen($tmpfname, 'rb');
            if ($tmpf) {
                $response_size = apply_filters('download_url_error_max_body_size', KB_IN_BYTES);
                $data['body']  = fread($tmpf, $response_size);
                fclose($tmpf);
            }

            unlink($tmpfname);
            return new WP_Error('http_404', trim(wp_remote_retrieve_response_message($response)), $data);
        }

        return $tmpfname;
    }
    function do_import()
    {
        set_time_limit(0);
        ini_set('max_execution_time', 0);
        $endpoint_base = get_option('zm_ac_endpoint', false);
        $endpoint = '/' . trim($endpoint_base, '/') . '/stockItem?$expand=files,Attributes,WarehouseDetails&$filter=DefaultWarehouseID+eq+\'FGS\'';
        $json = $this->contact_erp($endpoint, 'GET', null);
        // print_r($json);
        $temp = tmpfile();
        fwrite($temp, $json);
        $json = null;

        $fp = stream_get_meta_data($temp)['uri'];
        // $json_data = json_decode($json,true);
        $json_data = Items::fromFile($fp); //,['pointer'=>'/SalesPriceDetails']);

        // print_r($json_data);
        // exit();
        $import_count = 0;
        $indexed_products = array();
        foreach ($json_data as $product_) {
            $import_count++;
            if ($product_->ItemStatus == 'Inactive' || $product_->DefaultPrice->value == 0) {
                continue;
            }
            // temporary hold on any items with no images
            if (count($product_->files) <= 0) {
                continue;
            }
            $brand = null;
            foreach ($product_->Attributes as $a) {
                if ($a->AttributeID->value == 'BRAND') {
                    $brand = $a->ValueDescription->value;
                }
            }
            $stock_amt = 0;
            if (count($product_->WarehouseDetails) > 0) {
                foreach ($product_->WarehouseDetails as $w) {
                    if ($w->WarehouseID->value == 'FGS' && property_exists($w->QtyOnHand, 'value')) {
                        $stock_amt += $w->QtyOnHand->value;
                    }
                }
            }
            $product = [
                'name' => $product_->Description->value,
                'price' => $product_->DefaultPrice->value,
                'description' => $product_->Description->value,
                'stock_status' => (($stock_amt > 0) ? 'instock' : 'outofstock'), // 'instock', 'outofstock' or 'onbackorder'
                'stock_amt' => $stock_amt,
                'sku' => $product_->InventoryID->value,
                'categories' => $product_->PostingClass->value,
                'files' => $product_->files,
                'brand' => $brand,
                'metaDescription' => ((!isset($product_->MetaDescription->value)) ? '' : $product_->MetaDescription->value),
                'pageTitle' => ((!isset($product_->PageTitle->value)) ? '' : $product_->PageTitle->value),
                'tax_class' => $product_->TaxCategory->value,
                'uom' => $product_->SalesUOM->value,
                // 'weight'=>0,

            ];

            $indexed_products[$product['sku']] = $this->add_update_product($product);
        }
        if (count($indexed_products) > 0) {
            // $this->fetch_prices($indexed_products);
        }
        echo $import_count . ' records imported';
    }
    function contact_erp($endpoint, $method, $data, $is_refresh = false, $is_action = false)
    {
        $ch = curl_init();
        $base_url = $this->acumatica_base_url();
        $url = $base_url . $endpoint;
        $this->last_erp_response_meta = array(
            'url' => $url,
            'method' => $method,
            'curl_errno' => 0,
            'curl_error' => '',
            'http_code' => 0,
        );
        $headers = [];
        if (!$is_refresh) {
            $headers[] = 'Authorization: Bearer ' . $this->erp_token();
        }
        // print_r($headers);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);

            if ($is_action) {
                $headers[] =  'Content-Type:application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                if (is_string($data)) {
                    $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                }
            }
        } elseif ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] =  'Content-Type:application/json';
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $server_output = curl_exec($ch);
        $this->last_erp_response_meta['curl_errno'] = curl_errno($ch);
        $this->last_erp_response_meta['curl_error'] = curl_error($ch);
        $this->last_erp_response_meta['http_code'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($this->last_erp_response_meta['curl_errno']) {
            $this->write_error_log(
                'cURL error on ' . $url . ': ' . $this->last_erp_response_meta['curl_error'],
                'erp-request'
            );
        } elseif ((int) $this->last_erp_response_meta['http_code'] >= 400) {
            $this->write_error_log(
                'HTTP ' . $this->last_erp_response_meta['http_code'] . ' returned from ' . $url,
                'erp-request'
            );
        }
        // var_dump($server_output);
        curl_close($ch);
        return $server_output;
    }
    function clear_all_products()
    {
        $args = array(
            'status'            => array('draft', 'pending', 'private', 'publish'),
            'type'              => array_merge(array_keys(wc_get_product_types())),
            'parent'            => null,
            'sku'               => '',
            'category'          => array(),
            'tag'               => array(),
            'limit'             => -1,  // -1 for unlimited
            'offset'            => null,
            'page'              => 1,
            'include'           => array(),
            'exclude'           => array(),
            'orderby'           => 'date',
            'order'             => 'DESC',
            'return'            => 'objects',
            'paginate'          => false,
            'shipping_class'    => array(),
        );

        // Array of product objects
        $products = wc_get_products($args);

        // Loop through list of products
        foreach ($products as $product) {

            // Collect product variables
            $product_id   = $product->get_id();
            $this->wh_deleteProduct($product_id, TRUE);
        }
    }
    function wh_deleteProduct($id, $force = FALSE)
    {
        $product = wc_get_product($id);

        if (empty($product))
            return new WP_Error(999, sprintf(__('No %s is associated with #%d', 'woocommerce'), 'product', $id));

        // If we're forcing, then delete permanently.
        if ($force) {
            if ($product->is_type('variable')) {
                foreach ($product->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    $child->delete(true);
                }
            } elseif ($product->is_type('grouped')) {
                foreach ($product->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    $child->set_parent_id(0);
                    $child->save();
                }
            }

            $product->delete(true);
            $result = $product->get_id() > 0 ? false : true;
        } else {
            $product->delete();
            $result = 'trash' === $product->get_status();
        }

        if (!$result) {
            return new WP_Error(999, sprintf(__('This %s cannot be deleted', 'woocommerce'), 'product'));
        }

        // Delete parent product transients.
        if ($parent_id = wp_get_post_parent_id($id)) {
            wc_delete_product_transients($parent_id);
        }
        return true;
    }

    function custom_shipping_costs($rates, $package)
    {
        // New shipping cost (can be calculated)

        $tax_rate = 0.2;

        $coords = $this->geocode($package['destination']['address'] . ' Kenya');
        $delivery_location = array(
            "type" => "DELIVERY",
            "lat" => $coords[0],
            "long" => $coords[1],
            "name" => $package['destination']['address']
        );

        $delivery_rates = $this->sendy->get_price_quote(time() . '', $delivery_location);
        $new_cost = $delivery_rates['data']['economy_price_tiers'][0]['price_tiers'][0]['cost'];

        foreach ($rates as $rate_key => $rate) {
            // Excluding free shipping methods
            if ($rate->method_id != 'free_shipping' && $rate->method_id != 'local_pickup') {

                // Set rate cost
                $rates[$rate_key]->cost = $new_cost;

                // // Set taxes rate cost (if enabled)
                // $taxes = array();
                // foreach ($rates[$rate_key]->taxes as $key => $tax){
                //     if( $rates[$rate_key]->taxes[$key] > 0 )
                //         $taxes[$key] = $new_cost * $tax_rate;
                // }
                // $rates[$rate_key]->taxes = $taxes;

            }
        }
        return $rates;
    }

    function update_shipping_city_state_values($fields)
    {
        // Targeting My account section


        return $fields;
    }
    function wc_after_order_complete($order_id)
    {

        $order = new WC_Order($order_id);
        $coords = $this->geocode($order->get_shipping_address_1() . ' Kenya');
        $package =  $order->get_data();
        // update ERP with sale data
        $customer = $this->get_customer($package);
        // $res = $this->create_sale($package,$customer);
        //$this->create_sales_order($order,$customer);

        // initiate delivery
        $delivery_data = $this->sendy->initiate_delivery($package, $coords);

        update_post_meta($order_id, 'delivery_data', $delivery_data);
        return;
    }
    function get_customer($package)
    {
        $endpoint_base = get_option('zm_ac_endpoint', false);
        $endpoint = '/' . trim($endpoint_base, '/') . "/customer?\$filter=MainContact/Email%20eq%20%27" . urlencode($package['billing']['email']) . "%27";
        $res = $this->contact_erp($endpoint, 'GET', null);
        $data = json_decode($res, true);

        if (count($data) <= 0) {
            return $this->create_customer($package);
        }
        return $data[0];
    }
    function create_customer($package)
    {

        $data = [
            // "CustomerID" => ["value" => str_replace('.','',str_replace('@','',$package['billing']['email'])) ] ,
            "CustomerName" => ["value" => $package['billing']['first_name'] . ' ' . $package['billing']['last_name']],
            "StatementCycleId" => [
                "value" => "DEFAULT"
            ],
            "CustomerClass" => [
                "value" => "BASIC"
            ],
            "ARAccount" => [
                "value" => "CAR2"
            ],
            "CashDiscountAccount" => ["value" => "DEFAULTAR"],
            "DiscTakenAcctID" => [
                "value" => "DEFAULTAR"
            ],
            "SalesAccount" => [
                "value" => "DEFAULTAR"
            ],
            "Counntry" => [
                "value" => "KE"
            ],
            "AccountRef" => [
                "value" => "CAR2"
            ],
            "EnableWriteOffs" => [
                "value" => false
            ],
            "MainContact" =>
            [
                "Email" => ["value" => $package['billing']['email']],
                "Address" =>
                [
                    "AddressLine1" => ["value" => $package['billing']['address_1']],
                    "AddressLine2" => ["value" => $package['billing']['address_2']],
                    "City" => ["value" => $package['billing']['city']],
                    "State" => ["value" => $package['billing']['state']],
                    "PostalCode" => ["value" => $package['billing']['postcode']],
                    "Country" => ["value" => $package['billing']['country']],
                ]
            ]
        ];
        $endpoint_base = get_option('zm_ac_endpoint', false);
        $endpoint = '/' . trim($endpoint_base, '/') . '/customer';
        $res = $this->contact_erp($endpoint, 'PUT', $data);
        return (json_decode($res, true));
    }
    function create_sale($package, $customer)
    {
        $data = [
            "Amount" => ["value" => $package['total']],
            "CashAccount" => ["value" => 'DEFAULT'],
            "CustomerId" => ["value" => $customer['CustomerID']['value']],
            "Description" => ["value" => ''],
            "PaymentMethod" => ["value" => 'MPESA'],
            "Type" => ["value" => 'Cash Sale'],
            'PaymentRef' => ["value" => 'Order #' . $package['id']],
            'ARAccount' => ["value" => 'CAR2'],
            'Terms' => ["value" => 'DEFAULT']
        ];
        $endpoint_base = get_option('zm_ac_endpoint', false);
        $endpoint = '/' . trim($endpoint_base, '/') . '/CashSale';
        $res = $this->contact_erp($endpoint, 'PUT', $data);
        return (json_decode($res, true));
    }
    function create_sales_order($order, $customer)
    {

        $date = new DateTime();
        $data = [
            "OrderNbr" => ["value" => "<NEW>"],
            "Amount" => ["value" => $order->get_total()],
            "CashAccount" => ["value" => 'DEFAULT'],
            "CustomerId" => ["value" => $customer['CustomerID']['value']],
            "Description" => ["value" => ''],
            "PaymentMethod" => ["value" => 'MPESA'],
            "OrderType" => ["value" => 'CS'],
            'PaymentRef' => ["value" => 'Order #' . $order->get_id()],
            'ARAccount' => ["value" => 'CAR2'],

            'Details' => [],
            'DueDate' => ["value" => $date->format('m/d/Y')],
            'DiscDate' => ["value" => $date->format('m/d/Y')],
            'FinancialSettings' => [
                'Terms' => ["value" => 'DEFAULT'],
            ],
        ];
        foreach ($order->get_items() as $item) {

            $data['Details'][] = [
                'Operation' => ["value" => 'Issue'],
                'InventoryId' => ["value" => $item->get_product()->get_sku()],
                'UOM' => ["value" => 'PIECE'],
                'OrderQty' => ["value" => $item->get_quantity()],
                "TaxCategory" => [
                    "value" => "VAT"
                ],
                "UnitPrice" => [
                    "value" => $item->get_product()->get_price()
                ],
                "ManualDIscount" => [
                    "value" => false
                ],
                "DiscountAmount" => [
                    "value" => 0
                ],
            ];
        }
        $endpoint_base = get_option('zm_ac_endpoint', false);
        $endpoint = '/' . trim($endpoint_base, '/') . '/SalesOrder';
        $res = json_decode($this->contact_erp($endpoint, 'PUT', $data), true);

        if (isset($res['OrderNbr'])) {
            $this->process_sales_order($res['OrderNbr']['value']);
        }
        return $res;
    }
    function process_sales_order($order_number)
    {
        $endpoint_base = get_option('zm_ac_endpoint', false);
        $endpoint = '/' . trim($endpoint_base, '/') . 'SalesOrder/PrepareSalesInvoice';
        $data = [
            "entity" =>
            [
                "OrderType" => ["value" => "CS"],
                "OrderNbr" => ["value" => $order_number]
            ],
            "parameters" => new stdClass()
        ];

        $this->contact_erp($endpoint, 'POST', $data, false, true);
    }
    function finish_sale($order_id)
    {
        $order = new WC_Order($order_id);
        $package =  $order->get_data();
        $customer = $this->get_customer($package);
        // $res = $this->create_sale($package,$customer);
    }
    function get_token()
    {
        $url = '/identity/connect/token';
        $data = [
            'grant_type' => 'authorization_code',
            'client_id' => get_option('zm_ac_client_id', false),
            'code' => $_GET['code'],
            'client_secret' => get_option('zm_ac_client_secret', false),
            'redirect_uri' => 'https://fayshop.co.ke/shop/?erp_code=1'
        ];

        $res = json_decode($this->contact_erp($url, 'POST', $data, true), true);


        if (isset($res['expires_in'])) {
            $res['expires_on'] = time() + $res['expires_in'];
            update_option('zm_acumatica_token', $res);
            update_option('zm_acumatica_connection_status', array(
                'status' => 'success',
                'message' => 'Acumatica connected successfully.',
                'timestamp' => current_time('timestamp'),
            ));
            wp_safe_redirect($this->acumatica_settings_url('success'));
            exit();
        }

        update_option('zm_acumatica_connection_status', array(
            'status' => 'error',
            'message' => isset($res['error_description']) ? $res['error_description'] : 'Could not connect to Acumatica.',
            'timestamp' => current_time('timestamp'),
        ));
        $this->write_error_log(
            isset($res['error_description']) ? $res['error_description'] : 'Could not connect to Acumatica.',
            'acumatica-connect'
        );
        wp_safe_redirect($this->acumatica_settings_url('error'));
        exit();
    }
    function authenticate_with_password_grant()
    {
        $username = get_option('zm_ac_username', false);
        $password = get_option('zm_ac_password', false);
        $client_id = get_option('zm_ac_client_id', false);
        $client_secret = get_option('zm_ac_client_secret', false);

        if (!$username || !$password || !$client_id || !$client_secret) {
            return false;
        }

        $url = '/identity/connect/token';
        $data = http_build_query([
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
            'scope' => 'api',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ]);

        $res = json_decode($this->contact_erp($url, 'POST', $data, true), true);

        if (isset($res['access_token']) && !empty($res['access_token'])) {
            $expires_in = isset($res['expires_in']) ? (int) $res['expires_in'] : 3600;
            $res['expires_on'] = time() + $expires_in;
            update_option('zm_acumatica_token', $res);
            update_option('zm_acumatica_connection_status', array(
                'status' => 'success',
                'message' => 'Acumatica connected successfully using direct credentials.',
                'timestamp' => current_time('timestamp'),
            ));
            return $res['access_token'];
        }

        if (isset($res['error_description'])) {
            update_option('zm_acumatica_connection_status', array(
                'status' => 'error',
                'message' => $res['error_description'],
                'timestamp' => current_time('timestamp'),
            ));
            $this->write_error_log($res['error_description'], 'password-grant-auth');
        }

        return false;
    }
    function erp_token()
    {
        $password_grant_token = $this->authenticate_with_password_grant();
        if ($password_grant_token) {
            return $password_grant_token;
        }

        $res = get_option('zm_acumatica_token', false);
        if (!$res || !is_array($res) || empty($res['refresh_token'])) {
            return '';
        }

        if (!isset($res['expires_on']) || $res['expires_on'] < time()) {
            $url = '/identity/connect/token';
            $data = [
                'grant_type' => 'refresh_token',
                'client_id' => get_option('zm_ac_client_id', false),
                'client_secret' => get_option('zm_ac_client_secret', false),
                'refresh_token' => $res['refresh_token']
            ];

            $res = json_decode($this->contact_erp($url, 'POST', $data, true), true);
            if (isset($res['expires_in'])) {
                $res['expires_on'] = time() + $res['expires_in'];
                update_option('zm_acumatica_token', $res);
            }
            // print_r($res);
        }
        // var_dump($res);
        // exit();
        return isset($res['access_token']) ? $res['access_token'] : '';
    }
    /**
     * Auto Complete all WooCommerce orders.
     */

    function custom_woocommerce_auto_complete_order($order_id)
    {
        if (! $order_id) {
            return;
        }

        $order = wc_get_order($order_id);
        $order->update_status('completed');
    }
    //todo: update stock amount periodically
    function get_stock_amt($product)
    {
        $endpoint_base = get_option('zm_ac_endpoint', false);
        $endpoint = '/' . trim($endpoint_base, '/') . '/InventorySummaryInquiry';
        $data = ["InventoryId" => ["value" > $product->get_sku()]];
        $res = json_decode($this->contact_erp($endpoint, 'PUT', $data), true);
        $product->set_manage_stock(true);
        $product->set_stock_quantity($res['AvailableforIssue']['value']);
    }

    function add_taxonomies()
    {
        register_taxonomy('age_group', 'product', array(
            'hierarchical' => false,
            'labels' => array(
                'name' => _x('Age Groups', 'taxonomy general name'),
                'singular_name' => _x('Age Group', 'taxonomy singular name'),
                'search_items' =>  __('Search'),
                'all_items' => __('All Age Groups'),
                'edit_item' => __('Edit Age Group'),
                'update_item' => __('Update Age Group'),
                'add_new_item' => __('Add New Age Group'),
                'new_item_name' => __('New Age Group'),
                'menu_name' => __('Age Groups'),
            ),
            'rewrite' => array(
                'slug' => 'age_groups',
                'with_front' => false,
                'hierarchical' => true
            ),
        ));


        register_taxonomy('brand', 'product', array(
            'hierarchical' => false,
            'labels' => array(
                'name' => _x('Brands', 'taxonomy general name'),
                'singular_name' => _x('Brand', 'taxonomy singular name'),
                'search_items' =>  __('Search Brands'),
                'all_items' => __('All Brand'),
                'edit_item' => __('Edit Brand'),
                'update_item' => __('Update Brand'),
                'add_new_item' => __('Add New Brand'),
                'new_item_name' => __('New Brand'),
                'menu_name' => __('Brands'),
            ),
            'rewrite' => array(
                'slug' => 'brand',
                'with_front' => false,
                'hierarchical' => true
            ),
        ));
    }
    function add_term_fields($taxonomy)
    {
        echo '
        <div class="form-field">
          <label for="age_group_from">From Age</label>
          <input type="number" name="age_group_from" id="age_group_from" value="0" />
          <p>Minimum age for this group.</p>
        </div>';
        echo '
        <div class="form-field">
          <label for="age_group_to">To Age</label>
          <input type="number" name="age_group_to" id="age_group_to" value="0" />
          <p>Maximum age for this group.</p>
        </div>';
    }
    function edit_term_fields($term, $taxonomy)
    {
        $value = get_term_meta($term->term_id, 'age_group_from', true);
        $value2 = get_term_meta($term->term_id, 'age_group_to', true);
        echo '<tr class="form-field">
          <th>
            <label for="age_group_from">From Age</label>
          </th>
          <td>
            <input name="age_group_from" id="age_group_from" type="number" value="' . esc_attr($value) . '" />
            <p class="description">Minimum age for this group.</p>
          </td>
        </tr>';
        echo '<tr class="form-field">
          <th>
            <label for="age_group_to">To Age</label>
          </th>
          <td>
            <input name="age_group_to" id="age_group_to" type="number" value="' . esc_attr($value2) . '" />
            <p class="description">Maximum age for this group.</p>
          </td>
        </tr>';
    }
    function save_term_fields($term_id)
    {
        update_term_meta(
            $term_id,
            'age_group_to',
            sanitize_text_field($_POST['age_group_to'])
        );
        update_term_meta(
            $term_id,
            'age_group_from',
            sanitize_text_field($_POST['age_group_from'])
        );
    }
    function filter_woocommerce_product_query_tax_query($tax_query, $that): array
    {
        if (is_shop() && isset($_GET['age_groups']) && $_GET['age_groups'] != '') {
            $tax_query[] = [
                'taxonomy'     => 'age_group',
                'field'     => 'term_id',
                'terms'   => explode(',', $_GET['age_groups']),
            ];
        }
        if (is_shop() && isset($_GET['brands']) && $_GET['brands'] != '') {
            $tax_query[] = [
                'taxonomy'     => 'brand',
                'field'     => 'term_id',
                'terms'   => explode(',', $_GET['brands']),
            ];
        }
        if (is_shop() && isset($_GET['categories']) && $_GET['categories'] != '') {
            $tax_query[] = [
                'taxonomy'     => 'product_cat',
                'field'     => 'term_id',
                'terms'   => explode(',', $_GET['categories']),
            ];
        }
        // print_r($tax_query);
        return $tax_query;
    }
    function options_page()
    {
        require_once(__DIR__ . '/templates/settings.php');
    }
    function register_plugin_settings()
    {
        //register our settings
        register_setting('zm-ac-plugin-settings-group', 'zm_ac_client_id');
        register_setting('zm-ac-plugin-settings-group', 'zm_ac_client_secret');
        register_setting('zm-ac-plugin-settings-group', 'zm_ac_base_url');
        register_setting('zm-ac-plugin-settings-group', 'zm_ac_endpoint');
        register_setting('zm-ac-plugin-settings-group', 'zm_ac_customer');
        register_setting('zm-ac-plugin-settings-group', 'zm_ac_username');
        register_setting('zm-ac-plugin-settings-group', 'zm_ac_password');
    }
    function prefetch_products()
    {
        $indexed = array();

        $products = wc_get_products(
            array(
                'posts_per_page' => -1,
            )
        );
        foreach ($products as $p) {
            $indexed[$p->get_sku()] = $p;
        }
        return $indexed;
    }
    function fetch_prices($products = null)
    {
        $next_run = get_option('acumatica_next_price_update', 0);
        $now = new DateTime();
        $diff =  -$next_run;
        print($diff);
        if ($diff < 0) {
            // not yet time to update
            // return;
        }
        $now->modify('+30 minutes');
        update_option('acumatica_next_price_update', $now->format('U'));
        set_time_limit(0);
        if ($products == null) {
            $products = $this->prefetch_products();
        }
        // print_r($products);
        $available_skus = array_keys($products);
        // print_r($available_skus);
        $endpoint_base = get_option('zm_ac_endpoint', false);
        $customerID = get_option('zm_ac_customer', false);
        // $endpoint = '/'.trim($endpoint_base,'/')."/SalesPricesInquiry?\%24expand=SalesPriceDetails&\%24filter=PriceCode%20eq%20%27$customerID%27";
        $endpoint = '/' . trim($endpoint_base, '/') . '/SalesPricesInquiry?$expand=SalesPriceDetails';
        print_r($endpoint);
        // $params = [
        //     "PriceType"=> [
        //         "value"=> "Customer"
        //     ],
        //     "PriceCode"=> [
        //         "value"=> $customerID
        //     ]
        // ];
        $params = new stdClass();
        $json = $this->contact_erp($endpoint, 'PUT', $params);
        // print($json);

        // $endpoint = '/'.trim($endpoint_base,'/').'/stockItem?$expand=files,Attributes';
        // $json = $this->contact_erp($endpoint,'GET',null);

        $temp = tmpfile();
        fwrite($temp, $json);
        $json = null;
        // $json_data = json_decode($json,true);
        try {
            $fp = stream_get_meta_data($temp)['uri'];
            $json_data = Items::fromFile($fp, ['pointer' => '/SalesPriceDetails']);



            print('<pre>');
            foreach ($json_data as $product_) {
                // print_r($product_->InventoryID->value);
                if (in_array($product_->InventoryID->value, $available_skus)) {
                    // print_r($product_->InventoryID->value.' found. '.$product_->PriceCode->value." vs DTCACCOUNT \n");
                    if ($product_->PriceCode->value == 'DTCACCOUNT') {
                        echo $product_->InventoryID->value . " found. Price:" . $product_->Price->value . "\n";
                        $uom = get_post_meta($products[$product_->InventoryID->value]->get_id(), 'SalesUOM', true);
                        echo $uom . ' vs ' . $product_->UOM->value;
                        if ($uom == $product_->UOM->value) {
                            $products[$product_->InventoryID->value]->set_regular_price($product_->Price->value);
                            $products[$product_->InventoryID->value]->save();
                        }
                    }
                }
            }
        } catch (Exception $e) {
            echo 'Message: ' . $e->getMessage();
            // print_r($json);
        }

        fclose($temp);
        print('Done!');
    }
    /*
    Cron tasks
    */
    function zm_cron($schedules)
    {
        $schedules['every_five_minutes'] = array(
            'interval'  => 60 * 5,
            'display'   => __('Acumatica tasks', 'acumatica_connector')
        );
        $schedules['every_hour'] = array(
            'interval'  => 60 * 60,
            'display'   => __('Acumatica tasks (1h)', 'acumatica_connector')
        );
        return $schedules;
    }
    function zm_5min_cron()
    {
        // $this->get_token();
    }
    function zm_1h_cron()
    {
        // $this->do_import();
    }
    /*
    /Cron tasks
    */
    function regenerateThumbnails()
    {
        global $wpdb;
        $images = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'");

        foreach ($images as $image) {
            $id = $image->ID;
            $fullsizepath = get_attached_file($id);

            if (false === $fullsizepath || !file_exists($fullsizepath))
                return;

            if (wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $fullsizepath)))
                return true;
            else
                return false;
        }
    }
    function update_photos()
    {
        $forced = (isset($_GET['force'])) ? true : false;
        echo '<pre>\n';
        $limit = 5;
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $limit,
            'meta_query' => array(
                array(
                    'key' => 'update_images',
                    'value' => true,
                    'compare' => '=',
                )
            )
        );
        $query = new WP_Query($args);
        print_r($query->post_count . 'photos to upload');
        // exit();
        foreach ($query->posts as $product_post) {
            $product = wc_get_product($product_post->ID);
            $ids = array();
            $files = get_post_meta($product_post->ID, 'acumatica_files', true);
            $attached_files = get_post_meta($product_post->ID, 'attached_acumatica_files', true);

            $gallery_images = $product->get_gallery_image_ids();
            echo $product->get_name() . "\n";
            if ($files == $attached_files && !$forced) {
                echo "Files unchanged\n";
                update_post_meta($product->get_id(), 'update_images', false);
                return;
            }

            // print_r($files);
            // exit();
            // first delete old images
            foreach ($gallery_images as $att_id) {
                wp_delete_attachment($att_id, true);
            }
            foreach ($files as $f) {
                $parts = explode('/', $f->href);
                array_shift($parts);
                array_shift($parts);
                $url = implode('/', $parts);
                $imgid = $this->my_upload_image($this->erp_url . '/' . $url, $f->filename, $product->get_name());
                if ($imgid) {
                    print_r('saved!');
                    $ids[] = $imgid;
                }
            }
            print_r($ids);
            if (count($ids) > 0) {
                $product->set_image_id($ids[0]);
                $product->set_gallery_image_ids($ids);
            } else {
                $product->set_image_id(null);
                $product->set_gallery_image_ids([]);
            }
            $product->save();
            update_post_meta($product->get_id(), 'update_images', false);
            update_post_meta($product->get_id(), 'attached_acumatica_files', $files);
        }
    }
}
new AcumaticaConnector();
