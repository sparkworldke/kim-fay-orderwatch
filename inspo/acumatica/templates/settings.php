<?php
$token = get_option('zm_acumatica_token',false );
$last_inventory_sync = get_option('zm_ac_inventory_last_sync', false);
$connection_status = get_option('zm_acumatica_connection_status', false);
$is_connected = $this->is_acumatica_connected();
$inventory_queue = get_option('zm_ac_inventory_sync_queue', false);
$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
$settings_url = admin_url('options-general.php?page=zm_acumatica');
$synced_products = $active_tab === 'synced-products' ? $this->get_last_synced_products(100) : array();
?>
<style>
.acumatica_btn {
    background-color: #027acc;
    color: #fff !important;
    padding: 10px 25px;
    text-decoration: none !important;
    border-radius: 5px;
}
.acumatica_btn_secondary {
    background-color: #135e96;
    border: 0;
    cursor: pointer;
}
.acumatica_notice {
    margin-top: 15px;
    padding: 12px 15px;
    background: #fff;
    border-left: 4px solid #027acc;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
}
.acumatica_notice_success {
    border-left-color: #1e8e3e;
}
.acumatica_notice_error {
    border-left-color: #d63638;
}
.acumatica_status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}
.acumatica_status_dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
    background: #d63638;
}
.acumatica_status_dot.connected {
    background: #1e8e3e;
}
.acumatica_table td,
.acumatica_table th {
    vertical-align: top;
}
</style>
<script>

</script>
<div class="wrap">
    <h1>
        <?php esc_html_e( 'Acumatica Settings', 'zm-acumatica' );?>
    </h1>

    <?php if (isset($_GET['acumatica_connection']) && $connection_status) : ?>
        <div class="acumatica_notice <?php echo $connection_status['status'] === 'success' ? 'acumatica_notice_success' : 'acumatica_notice_error'; ?>">
            <?php echo esc_html($connection_status['message']); ?>
        </div>
    <?php endif; ?>

    <h2 class="nav-tab-wrapper">
        <a href="<?php echo esc_url($settings_url); ?>" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
        <a href="<?php echo esc_url(add_query_arg('tab', 'synced-products', $settings_url)); ?>" class="nav-tab <?php echo $active_tab === 'synced-products' ? 'nav-tab-active' : ''; ?>">Synced Products</a>
    </h2>

    <?php if ($active_tab === 'synced-products') : ?>
        <table class="widefat striped acumatica_table" style="margin-top:15px;">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Previous Qty</th>
                    <th>New Synced Qty</th>
                    <th>Status</th>
                    <th>Trigger</th>
                    <th>Last Synced</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($synced_products)) : ?>
                    <?php foreach ($synced_products as $product) : ?>
                        <?php
                        $last_synced_at = get_post_meta($product->get_id(), 'zm_ac_last_synced_at', true);
                        $last_synced_previous_qty = get_post_meta($product->get_id(), 'zm_ac_last_synced_previous_qty', true);
                        $last_synced_qty = get_post_meta($product->get_id(), 'zm_ac_last_synced_qty', true);
                        $last_synced_status = get_post_meta($product->get_id(), 'zm_ac_last_synced_status', true);
                        $last_synced_trigger = get_post_meta($product->get_id(), 'zm_ac_last_synced_trigger', true);
                        $last_synced_sku = get_post_meta($product->get_id(), 'zm_ac_last_synced_sku', true);
                        $last_synced_date = '';
                        if (!empty($last_synced_at)) {
                            $timezone = new DateTimeZone('Africa/Nairobi');
                            $last_synced_dt = new DateTime('@' . $last_synced_at);
                            $last_synced_dt->setTimezone($timezone);
                            $last_synced_date = $last_synced_dt->format('Y-m-d H:i:s T');
                        }
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($product->get_id())); ?>">
                                    <?php echo esc_html($product->get_name()); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($last_synced_sku ?: $product->get_sku()); ?></td>
                            <td><?php echo esc_html($last_synced_previous_qty === '' ? '-' : $last_synced_previous_qty); ?></td>
                            <td><?php echo esc_html($last_synced_qty); ?></td>
                            <td><?php echo esc_html($last_synced_status); ?></td>
                            <td><?php echo esc_html($last_synced_trigger); ?></td>
                            <td><?php echo esc_html($last_synced_date); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="7">No synced products found yet.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php else : ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'zm-ac-plugin-settings-group' ); ?>
            <?php do_settings_sections( 'zm-ac-plugin-settings-group' ); ?>
            <table class="form-table">
                <tbody>
                    <tr>
                        <th>Connection Status</th>
                        <td>
                            <span class="acumatica_status">
                                <span class="acumatica_status_dot <?php echo $is_connected ? 'connected' : ''; ?>"></span>
                                <?php echo $is_connected ? 'Connected' : 'Not Connected'; ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Client ID</th>
                        <td><input class="widefat" type="text" name="zm_ac_client_id" value="<?php echo esc_attr( get_option('zm_ac_client_id') ); ?>" ></td>
                    </tr>
                    <tr>
                        <th>Client Secret</th>
                        <td><input class="widefat" type="text" name="zm_ac_client_secret" value="<?php echo esc_attr( get_option('zm_ac_client_secret') ); ?>" ></td>
                    </tr>
                    <tr>
                        <th>Acumatica Base URL</th>
                        <td>
                            <input class="widefat" type="text" name="zm_ac_base_url" value="<?php echo esc_attr( get_option('zm_ac_base_url', AcumaticaConnector::DEFAULT_BASE_URL) ); ?>" >
                            <p class="description">Using the same production base URL pattern as the working iPay Acumatica integration.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Acumatica Username</th>
                        <td>
                            <input class="widefat" type="text" name="zm_ac_username" value="<?php echo esc_attr( get_option('zm_ac_username') ); ?>" >
                            <p class="description">Optional. If provided together with password, the plugin will use direct credentials auth like <code>wc-ipay-accumatica/class-accumatica.php</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Acumatica Password</th>
                        <td><input class="widefat" type="password" name="zm_ac_password" value="<?php echo esc_attr( get_option('zm_ac_password') ); ?>" ></td>
                    </tr>
                    <tr>
                        <th>Acumatica Base Endpoint ID <br><span style="font-size: x-small;">(e.g. entity/woocommerce/20.200.001 )</span></th>
                        <td>
                            <input class="widefat" type="text" name="zm_ac_endpoint" value="<?php echo esc_attr( get_option('zm_ac_endpoint') ); ?>" >
                            <p class="description">Keep this as the inventory API endpoint base for <code>stockItem</code>. Do not use the <code>/PosOrder/</code> endpoint from the iPay plugin here because that endpoint is for sales orders, not stock sync.</p>
                        </td>
                    </tr>
                    <tr>
                        <th>Customer ID for prices <br><span style="font-size: x-small;">(e.g. CUST000001 )</span></th>
                        <td><input class="widefat" type="text" name="zm_ac_customer" value="<?php echo esc_attr( get_option('zm_ac_customer') ); ?>" ></td>
                    </tr>
                    
                    <tr>
                        <th>&nbsp;</th>
                        <td>
                            <a href="<?php echo $this->login_url();?>" class="acumatica_btn">Connect to Acumatica</a>
                            <a href="#" id="schedule_price_update" class="acumatica_btn" style="display:inline-block; margin-left:8px;">Update prices</a>
                            <a href="#" id="schedule_product_update" class="acumatica_btn" style="display:inline-block; margin-left:8px;">Update products</a>
                            <input type="hidden" name="action" value="zm_acumatica_manual_inventory_sync">
                            <?php wp_nonce_field('zm_acumatica_manual_inventory_sync'); ?>
                            <button type="submit" class="acumatica_btn acumatica_btn_secondary" formaction="<?php echo esc_url(admin_url('admin-post.php')); ?>" formmethod="post" style="margin-left:8px;">Sync Inventory Now</button>
                        </td>
                    </tr>
                    <tr>
                        <th>Inventory Sync Schedule</th>
                        <td>
                            Automatic sync runs daily at <strong>6:00 AM</strong>, <strong>1:00 PM</strong>, and <strong>6:00 PM</strong> EAT.
                            Manual sync is queued and processed in background in small batches to avoid request timeouts.
                        </td>
                    </tr>
                    <tr>
                        <th>Background Queue</th>
                        <td>
                            <?php if ($inventory_queue && !empty($inventory_queue['status'])) : ?>
                                <strong>Status:</strong> <?php echo esc_html($inventory_queue['status']); ?><br>
                                <strong>Trigger:</strong> <?php echo esc_html($inventory_queue['trigger']); ?><br>
                                <strong>Current offset:</strong> <?php echo esc_html($inventory_queue['offset']); ?><br>
                                <strong>Updated so far:</strong> <?php echo esc_html($inventory_queue['updated']); ?>
                            <?php else : ?>
                                No background inventory sync is currently queued.
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Last Inventory Sync</th>
                        <td>
                            <?php if ($last_inventory_sync && !empty($last_inventory_sync['timestamp'])) : ?>
                                <?php
                                $timezone = new DateTimeZone('Africa/Nairobi');
                                $last_run = new DateTime('@' . $last_inventory_sync['timestamp']);
                                $last_run->setTimezone($timezone);
                                ?>
                                <strong>Status:</strong> <?php echo esc_html($last_inventory_sync['status']); ?><br>
                                <strong>Trigger:</strong> <?php echo esc_html($last_inventory_sync['trigger']); ?><br>
                                <strong>Updated products:</strong> <?php echo esc_html($last_inventory_sync['updated']); ?><br>
                                <strong>When:</strong> <?php echo esc_html($last_run->format('Y-m-d H:i:s T')); ?><br>
                                <strong>Message:</strong> <?php echo esc_html($last_inventory_sync['message']); ?>
                            <?php else : ?>
                                No inventory sync has run yet.
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                </tbody>
            </table>
            <?php submit_button(); ?>
        </form>
    <?php endif; ?>

    <?php if (isset($_GET['inventory_sync'])) : ?>
        <div class="acumatica_notice">
            <?php echo isset($last_inventory_sync['message']) ? esc_html($last_inventory_sync['message']) : esc_html__('Inventory sync finished.', 'zm-acumatica'); ?>
        </div>
    <?php endif; ?>
</div>
