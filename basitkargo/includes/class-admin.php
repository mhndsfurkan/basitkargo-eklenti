<?php
/**
 * Basit Kargo Admin Class
 * Handles admin panel functionality
 */

namespace BasitKargo;

if (!defined('ABSPATH')) {
    exit;
}

class Admin {
    /**
     * Resolve shipped/delivered status keys in a Hazerfen-compatible way.
     * - For update_status() and get_status() comparisons, Woo uses non-prefixed keys
     * - If Hazerfen's status exists, use it; otherwise fallback to our own
     */
    public static function getShippedStatusKey($for_update = true) {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
        if (isset($statuses['wc-hezarfen-shipped'])) {
            return 'hezarfen-shipped'; // non-prefixed key for update_status/get_status
        }
        return 'shipped';
    }

    public static function getDeliveredStatusKey($for_update = true) {
        $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
        if (isset($statuses['wc-hezarfen-delivered'])) {
            return 'hezarfen-delivered';
        }
        return 'delivered';
    }
    
    public function __construct() {
        $this->initHooks();
    }

    /**
     * Persist inline shipment code on order update
     */
    public function saveInlineTrackingOnOrderUpdate($order_id) {
        error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - Function called for order_id: $order_id");
        
        if (!current_user_can('edit_shop_orders')) { 
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - No permission, returning");
            return; 
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - Order not found, returning");
            return;
        }
        
        error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - Order found, processing...");
        
        // Debug log
        error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - Raw POST['manual_enabled']: " . (isset($_POST['manual_enabled']) ? $_POST['manual_enabled'] : 'NOT SET'));
        error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - Raw POST['manual_enabled_inline']: " . (isset($_POST['manual_enabled_inline']) ? $_POST['manual_enabled_inline'] : 'NOT SET'));
        
        // Inline fields (shown when no barcode)
        if (isset($_POST['bk_inline_shipment_code'])) {
            $code = sanitize_text_field(wp_unslash($_POST['bk_inline_shipment_code']));
            if ($code !== '') { $order->update_meta_data('basit_kargo_handler_shipment_code', $code); }
        }
        if (isset($_POST['bk_inline_handler'])) {
            $handler = sanitize_text_field(wp_unslash($_POST['bk_inline_handler']));
            if ($handler !== '') { $order->update_meta_data('basit_kargo_handler_name', $handler); }
        }
        
        // Manual metabox fields (persist even if "Manuel Kaydet" butonuna basılmadan standart güncelleme yapılırsa)
        if (isset($_POST['handler'])) {
            $manual_handler = sanitize_text_field(wp_unslash($_POST['handler']));
            if ($manual_handler !== '') { $order->update_meta_data('basit_kargo_handler_name', $manual_handler); }
        }
        if (isset($_POST['tracking'])) {
            $manual_tracking = sanitize_text_field(wp_unslash($_POST['tracking']));
            if ($manual_tracking !== '') { $order->update_meta_data('basit_kargo_handler_shipment_code', $manual_tracking); }
        }
        
        // Persist manual_enabled based on actual value (handles '0' correctly)
        // Check both metabox and inline checkboxes
        $metabox_checked = isset($_POST['manual_enabled']) && $_POST['manual_enabled'] === '1';
        $inline_checked = isset($_POST['manual_enabled_inline']) && $_POST['manual_enabled_inline'] === '1';
        
        // Determine the desired state: 'yes' if either checkbox is checked, 'no' otherwise
        $manual_enabled_state = ($metabox_checked || $inline_checked) ? 'yes' : 'no';
        
        // Log the determined state
        error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - Metabox checked: " . ($metabox_checked ? 'yes' : 'no'));
        error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - Inline checked: " . ($inline_checked ? 'yes' : 'no'));
        error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - Determined state: $manual_enabled_state");
        
        // Update meta with determined state
        $order->update_meta_data('basit_kargo_manual_enabled', $manual_enabled_state);
        error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - Updated meta to: $manual_enabled_state");
        
        // Add order note
        $order->add_order_note("Manuel kargo durumu güncellendi: " . ($manual_enabled_state === 'yes' ? 'Aktif' : 'Pasif'));
        error_log("Basit Kargo Admin: saveInlineTrackingOnOrderUpdate - Added order note");
    }

    /**
     * Persist inline shipment code on order save (legacy)
     */
    public function saveInlineTrackingOnOrderSave($post_id, $post) {
        error_log("Basit Kargo Admin: saveInlineTrackingOnOrderSave - Function called for post_id: $post_id");
        if ($post->post_type !== 'shop_order' && $post->post_type !== 'woocommerce_order') { 
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderSave - Not a shop_order or woocommerce_order, returning. Post type: " . $post->post_type);
            return; 
        }
        if (!current_user_can('edit_post', $post_id)) { 
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderSave - No permission, returning");
            return; 
        }
        $order = wc_get_order($post_id);
        if ($order) {
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderSave - Order found, processing...");
            // Debug log
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderSave - Raw POST['manual_enabled']: " . (isset($_POST['manual_enabled']) ? $_POST['manual_enabled'] : 'NOT SET'));
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderSave - Raw POST['manual_enabled_inline']: " . (isset($_POST['manual_enabled_inline']) ? $_POST['manual_enabled_inline'] : 'NOT SET'));
            
            // Inline fields (shown when no barcode)
            if (isset($_POST['bk_inline_shipment_code'])) {
                $code = sanitize_text_field(wp_unslash($_POST['bk_inline_shipment_code']));
                if ($code !== '') { $order->update_meta_data('basit_kargo_handler_shipment_code', $code); }
            }
            if (isset($_POST['bk_inline_handler'])) {
                $handler = sanitize_text_field(wp_unslash($_POST['bk_inline_handler']));
                if ($handler !== '') { $order->update_meta_data('basit_kargo_handler_name', $handler); }
            }
            
            // Manual metabox fields (persist even if "Manuel Kaydet" butonuna basılmadan standart güncelleme yapılırsa)
            if (isset($_POST['handler'])) {
                $manual_handler = sanitize_text_field(wp_unslash($_POST['handler']));
                if ($manual_handler !== '') { $order->update_meta_data('basit_kargo_handler_name', $manual_handler); }
            }
            if (isset($_POST['tracking'])) {
                $manual_tracking = sanitize_text_field(wp_unslash($_POST['tracking']));
                if ($manual_tracking !== '') { $order->update_meta_data('basit_kargo_handler_shipment_code', $manual_tracking); }
            }
            
            // Persist manual_enabled based on actual value (handles '0' correctly)
            // Check both metabox and inline checkboxes
            $metabox_checked = isset($_POST['manual_enabled']) && $_POST['manual_enabled'] === '1';
            $inline_checked = isset($_POST['manual_enabled_inline']) && $_POST['manual_enabled_inline'] === '1';
            
            // Determine the desired state: 'yes' if either checkbox is checked, 'no' otherwise
            $manual_enabled_state = ($metabox_checked || $inline_checked) ? 'yes' : 'no';
            
            // Log the determined state
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderSave - Metabox checked: " . ($metabox_checked ? 'yes' : 'no'));
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderSave - Inline checked: " . ($inline_checked ? 'yes' : 'no'));
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderSave - Determined state: $manual_enabled_state");
            
            // Update meta with determined state
            $order->update_meta_data('basit_kargo_manual_enabled', $manual_enabled_state);
            error_log("Basit Kargo Admin: saveInlineTrackingOnOrderSave - Updated meta to: $manual_enabled_state");
            
        }
    }

    /**
     * Persist manual_enabled even if other hooks miss it (extra guard) - DISABLED
     */
    /*
    public function persistManualEnabledOnSave($post_id, $post, $update) {
        if ($post->post_type !== 'shop_order') { return; }
        if (!current_user_can('edit_post', $post_id)) { return; }
        // Only run on explicit saves with POST available
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return; }
        $order = wc_get_order($post_id);
        if (!$order) { return; }
        
        // Debug log
        error_log("Basit Kargo Admin: persistManualEnabledOnSave - Raw POST['manual_enabled']: " . (isset($_POST['manual_enabled']) ? $_POST['manual_enabled'] : 'NOT SET'));
        error_log("Basit Kargo Admin: persistManualEnabledOnSave - Raw POST['manual_enabled_inline']: " . (isset($_POST['manual_enabled_inline']) ? $_POST['manual_enabled_inline'] : 'NOT SET'));
        
        // Check both metabox and inline checkboxes
        $metabox_checked = isset($_POST['manual_enabled']) && $_POST['manual_enabled'] === '1';
        $inline_checked = isset($_POST['manual_enabled_inline']) && $_POST['manual_enabled_inline'] === '1';
        
        // Determine the desired state: 'yes' if either checkbox is checked, 'no' otherwise
        $manual_enabled_state = ($metabox_checked || $inline_checked) ? 'yes' : 'no';
        
        // Log the determined state
        error_log("Basit Kargo Admin: persistManualEnabledOnSave - Metabox checked: " . ($metabox_checked ? 'yes' : 'no'));
        error_log("Basit Kargo Admin: persistManualEnabledOnSave - Inline checked: " . ($inline_checked ? 'yes' : 'no'));
        error_log("Basit Kargo Admin: persistManualEnabledOnSave - Determined state: $manual_enabled_state");
        
        // Only update if the state has changed to avoid unnecessary database writes and potential hooks
        if ($order->get_meta('basit_kargo_manual_enabled') !== $manual_enabled_state) {
            $order->update_meta_data('basit_kargo_manual_enabled', $manual_enabled_state);
            error_log("Basit Kargo Admin: persistManualEnabledOnSave - Updated meta to: $manual_enabled_state");
        }
    }
    */

    /**
     * Save manual carrier + tracking via AJAX (independent of Basit Kargo)
     */
    public function handleSaveInlineManual() {
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'basit_kargo_nonce')) {
            wp_send_json_error(__('Geçersiz istek (nonce)', 'basit-kargo'));
        }
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) { wp_send_json_error(__('Geçersiz sipariş ID', 'basit-kargo')); }
        if (!current_user_can('edit_shop_orders')) { wp_send_json_error(__('Yetkiniz yok', 'basit-kargo')); }
        $order = wc_get_order($order_id);
        if (!$order) { wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo')); }
        $tracking = isset($_POST['tracking']) ? sanitize_text_field(wp_unslash($_POST['tracking'])) : '';
        $handler  = isset($_POST['handler']) ? sanitize_text_field(wp_unslash($_POST['handler'])) : '';
        $manual_enabled = isset($_POST['manual_enabled']) ? (int) $_POST['manual_enabled'] : null;
        if ($handler !== '') { $order->update_meta_data('basit_kargo_handler_name', $handler); }
        if ($manual_enabled !== null) { $order->update_meta_data('basit_kargo_manual_enabled', $manual_enabled ? 'yes' : 'no'); }
        if ($tracking !== '') { $order->update_meta_data('basit_kargo_handler_shipment_code', $tracking); }
        $order->save();
        $order->add_order_note(__('Manuel takip bilgisi kaydedildi.', 'basit-kargo'));

        // If manual enabled and a tracking code is provided, mark as shipped and trigger email
        $is_manual_enabled = $order->get_meta('basit_kargo_manual_enabled') === 'yes';
        if ($is_manual_enabled && !empty($order->get_meta('basit_kargo_handler_shipment_code'))) {
            if ($order->get_status() !== self::getShippedStatusKey()) {
                $order->update_status(self::getShippedStatusKey(), __('Manuel takip kodu girildi - Kargoya verildi', 'basit-kargo'));
            }
            $already_sent = $order->get_meta('basit_kargo_shipped_mail_sent');
            if (!$already_sent) {
                try {
                    $email = new \BasitKargo\Email();
                    $sent = $email->sendTrackingEmail($order);
                    if (is_array($sent) ? ($sent['success'] ?? false) : (bool) $sent) {
                        $order->update_meta_data('basit_kargo_shipped_mail_sent', current_time('mysql'));
                        $order->save();
                    }
                } catch (\Throwable $e) {}
            }
        }
        wp_send_json_success(__('Kaydedildi', 'basit-kargo'));
    }
    
    private function initHooks() {
        // Admin menu
        add_action('admin_menu', array($this, 'addAdminMenu'));
        add_action('admin_init', array($this, 'initSettings'));
        // Register custom order statuses (e.g., shipped)
        add_action('init', array($this, 'registerOrderStatuses'));
        add_filter('wc_order_statuses', array($this, 'injectOrderStatuses'));
        // Legacy "Basit Kargo Eşleştirme" metabox devre dışı bırakıldı (manuel giriş UI tarafından yönetilir)
        
        // Order status hooks - moved to Email class to avoid conflicts
        // add_action('woocommerce_order_status_changed', array($this, 'handleOrderStatusChange'), 10, 3);
        // Auto-create on new order and on payment complete
        add_action('woocommerce_new_order', array($this, 'autoCreateOnNewOrder'), 20, 1);
        add_action('woocommerce_payment_complete', array($this, 'autoCreateOnPaymentComplete'), 20, 1);
        
        // Auto sync cron job
        add_action('init', array($this, 'scheduleAutoSync'));
        add_action('basit_kargo_auto_sync', array($this, 'runAutoSync'));
        add_filter('cron_schedules', array($this, 'addCustomCronInterval'));
        
        // Clean up cron job on deactivation
        register_deactivation_hook(BASIT_KARGO_PLUGIN_FILE, array($this, 'cleanupCronJobs'));
        
        // HPOS compatibility is now handled in main plugin file
        
        // AJAX handlers
        add_action('wp_ajax_basit_kargo_sync_cancelled_failed_orders', array($this, 'syncCancelledFailedOrders'));
        add_action('wp_ajax_basit_kargo_generate_barcode', array($this, 'handleGenerateBarcode'));
        // PDF printing is handled directly by PDF class via admin-ajax
        add_action('wp_ajax_basit_kargo_print_barcode', array($this, 'handlePrintBarcode'));
        add_action('wp_ajax_basit_kargo_barcode_sync', array($this, 'handleBarcodeSync'));
        add_action('wp_ajax_basit_kargo_manual_tracking_sync', array($this, 'handleTrackingSync'));
        add_action('wp_ajax_basit_kargo_send_mail', array($this, 'handleSendMail'));
        add_action('wp_ajax_basit_kargo_send_owner_mail', array($this, 'handleSendOwnerMail'));
        add_action('wp_ajax_basit_kargo_send_delivered_mail', array($this, 'handleSendDeliveredMail'));
        add_action('wp_ajax_basit_kargo_save_manual_map', array($this, 'handleSaveManualMap'));
        add_action('wp_ajax_basit_kargo_force_create_barcode', array($this, 'handleForceCreateBarcode'));
        add_action('wp_ajax_basit_kargo_convert_old_meta', array($this, 'handleConvertOldMeta'));
        add_action('wp_ajax_basit_kargo_sync_completed_orders', array($this, 'handleSyncCompletedOrders'));
        add_action('wp_ajax_basit_kargo_sync_order_by_number', array($this, 'handleSyncOrderByNumber'));
        add_action('wp_ajax_basit_kargo_sync_order_by_name', array($this, 'handleSyncOrderByName'));
        add_action('wp_ajax_basit_kargo_bulk_discover_sync', array($this, 'handleBulkDiscoverSync'));
        add_action('wp_ajax_basit_kargo_import_csv', array($this, 'handleImportCsv'));
        add_action('wp_ajax_basit_kargo_save_inline_manual', array($this, 'handleSaveInlineManual'));
        // Client-side error logging from admin UI
        add_action('wp_ajax_basit_kargo_log_client_error', array($this, 'handleLogClientError'));
        // Open BK tracking link (ensure API ID/reference exists)
        add_action('wp_ajax_basit_kargo_open_tracking', array($this, 'handleOpenTracking'));
        // HPOS/New orders screen: register custom statuses so they appear in filters and queries
        add_filter('woocommerce_register_shop_order_post_statuses', array($this, 'registerHposOrderStatuses'));
        // Hazerfen-style: rely on proper status registration only (no query overrides)
        // HPOS/New orders screen status registration
        // (No pre_get_posts or order_query_args overrides)
        // Bulk action: mark orders as shipped
        add_filter('bulk_actions-edit-shop_order', array($this, 'registerBulkShippedAction'));
        add_filter('handle_bulk_actions-edit-shop_order', array($this, 'handleBulkShippedAction'), 10, 3);
        add_action('admin_notices', array($this, 'bulkShippedAdminNotice'));
        // Save inline tracking code on order save
        add_action('woocommerce_update_order', array($this, 'saveInlineTrackingOnOrderUpdate'), 20, 1);
        // Also persist manual checkbox on any order save (covers HPOS/new editor)
        // add_action('save_post_shop_order', array($this, 'persistManualEnabledOnSave'), 20, 3);
        // Non-AJAX fallback form handler for manual save
        add_action('admin_post_bk_manual_save', array($this, 'handleManualSaveForm'));
        add_action('admin_post_nopriv_bk_manual_save', array($this, 'handleManualSaveForm'));
        // Non-AJAX fallbacks for metabox actions
        add_action('admin_post_bk_sync_barcode', array($this, 'handleBarcodeSyncForm'));
        add_action('admin_post_bk_force_barcode', array($this, 'handleForceCreateBarcodeForm'));
        add_action('wp_ajax_basit_kargo_manual_map', array($this, 'handleManualMap'));
    }

    /**
     * Register custom WooCommerce order statuses
     */
    public function registerOrderStatuses() {
        if (!function_exists('register_post_status')) { return; }

        // Hazerfen-style: public=false but visible in admin lists
        register_post_status('wc-shipped', array(
            'label' => _x('Kargoya Verildi', 'Order status', 'basit-kargo'),
            'public' => false,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Kargoya Verildi <span class="count">(%s)</span>', 'Kargoya Verildi <span class="count">(%s)</span>', 'basit-kargo')
        ));

        register_post_status('wc-delivered', array(
            'label' => _x('Teslim Edildi', 'Order status', 'basit-kargo'),
            'public' => false,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('Teslim Edildi <span class="count">(%s)</span>', 'Teslim Edildi <span class="count">(%s)</span>', 'basit-kargo')
        ));
    }

    /**
     * Add custom statuses to WooCommerce status dropdowns
     */
    public function injectOrderStatuses($order_statuses) {
        $new = array();
        foreach ($order_statuses as $key => $label) {
            $new[$key] = $label;
            // Insert our status right after processing if possible
            if ($key === 'wc-processing' && !isset($order_statuses['wc-shipped'])) {
                $new['wc-shipped'] = _x('Kargoya Verildi', 'Order status', 'basit-kargo');
            }
            if ($key === 'wc-completed' && !isset($order_statuses['wc-delivered'])) {
                $new['wc-delivered'] = _x('Teslim Edildi', 'Order status', 'basit-kargo');
            }
        }
        if (!isset($new['wc-shipped'])) {
            $new['wc-shipped'] = _x('Kargoya Verildi', 'Order status', 'basit-kargo');
        }
        if (!isset($new['wc-delivered'])) {
            $new['wc-delivered'] = _x('Teslim Edildi', 'Order status', 'basit-kargo');
        }
        return $new;
    }

    /**
     * HPOS/New orders screen status registry (ensures visibility in new UI)
     */
    public function registerHposOrderStatuses($statuses) {
        // Hazerfen-style: mark visible in admin lists, not public
        $statuses['wc-shipped'] = array(
            'label' => _x('Kargoya Verildi', 'Order status', 'basit-kargo'),
            'public' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'exclude_from_search' => false,
            'label_count' => _n_noop('Kargoya Verildi <span class="count">(%s)</span>', 'Kargoya Verildi <span class="count">(%s)</span>', 'basit-kargo'),
        );
        $statuses['wc-delivered'] = array(
            'label' => _x('Teslim Edildi', 'Order status', 'basit-kargo'),
            'public' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'exclude_from_search' => false,
            'label_count' => _n_noop('Teslim Edildi <span class="count">(%s)</span>', 'Teslim Edildi <span class="count">(%s)</span>', 'basit-kargo'),
        );
        return $statuses;
    }

    /**
     * Add bulk action: change status to shipped
     */
    public function registerBulkShippedAction($bulk_actions) {
        $bulk_actions['mark_wc-shipped'] = __('Durumu kargoya verildi olarak değiştir', 'basit-kargo');
        return $bulk_actions;
    }

    public function handleBulkShippedAction($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'mark_wc-shipped' || empty($post_ids)) { return $redirect_to; }
        $changed = 0;
        foreach ($post_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) { continue; }
            if ($order->get_status() !== self::getShippedStatusKey()) {
                $order->update_status(self::getShippedStatusKey(), __('Toplu işlem: Kargoya verildi', 'basit-kargo'));
                $changed++;
            }
        }
        $redirect_to = add_query_arg(array('bk_bulk_shipped' => $changed), $redirect_to);
        return $redirect_to;
    }

    public function bulkShippedAdminNotice() {
        if (!isset($_GET['bk_bulk_shipped'])) { return; }
        $count = intval($_GET['bk_bulk_shipped']);
        if ($count > 0) {
            printf('<div class="updated"><p>%s</p></div>', esc_html(sprintf(_n('%s sipariş kargoya verildi olarak güncellendi.', '%s sipariş kargoya verildi olarak güncellendi.', $count, 'basit-kargo'), number_format_i18n($count))));
        }
    }

    /**
     * Force include wc-shipped and wc-delivered in orders list when "All" is selected
     */
    public function includeCustomStatusesInAllList($query) {
        if (!is_admin() || !$query->is_main_query()) { return; }
        global $typenow;
        if ($typenow !== 'shop_order' && (!isset($_GET['post_type']) || $_GET['post_type'] !== 'shop_order')) { return; }

        // If explicitly filtering to a specific non-all status, respect it
        if (!empty($_GET['post_status']) && $_GET['post_status'] !== 'all') { return; }

        if (defined('WP_DEBUG') && WP_DEBUG) { error_log('BasitKargo: includeCustomStatusesInAllList running'); }
        
        // Force include ALL order statuses including our custom ones
        $query->set('post_status', array('publish', 'wc-shipped', 'wc-delivered', 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-failed'));
        
        if (defined('WP_DEBUG') && WP_DEBUG) { 
            error_log('BasitKargo: includeCustomStatusesInAllList - Forced post_status to include all order statuses'); 
        }
    }

    /**
     * Override WooCommerce admin orders query completely
     */
    public function overrideOrdersQuery($query) {
        // Removed: we no longer override the main query (Hazerfen approach)
        return;
    }

    /**
     * Force include custom statuses in WooCommerce orders
     */
    public function forceIncludeCustomStatuses($args) {
        if (!is_admin()) { return $args; }
        
        // Check if we're on the orders page
        $is_orders_page = (isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order') || 
                          (isset($_GET['page']) && $_GET['page'] === 'wc-orders');
        
        if (!$is_orders_page) { return $args; }
        
        // Check if we're viewing "All" orders
        $post_status = isset($_GET['post_status']) ? $_GET['post_status'] : 'all';
        if ($post_status !== 'all') { return $args; }
        
        if (defined('WP_DEBUG') && WP_DEBUG) { error_log('BasitKargo: forceIncludeCustomStatuses running'); }
        
        // Force include custom statuses (include non-prefixed for safety)
        if (isset($args['status'])) {
            $statuses = $args['status'];
            if (empty($statuses)) { $statuses = array(); }
            if (is_string($statuses)) { $statuses = array($statuses); }
            
            $custom_statuses = array('wc-shipped', 'wc-delivered', 'shipped', 'delivered');
            $statuses = array_merge($statuses, $custom_statuses);
            $args['status'] = array_values(array_unique(array_filter($statuses)));
        } else {
            $args['status'] = array('wc-shipped', 'wc-delivered', 'shipped', 'delivered', 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-failed');
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) { 
            error_log('BasitKargo: forceIncludeCustomStatuses - Final args: ' . print_r($args, true)); 
        }
        
        return $args;
    }

    /**
     * HPOS orders table: include custom statuses under "All" tab
     * Applies to filters:
     * - woocommerce_shop_order_list_table_prepare_items_query_args
     * - woocommerce_orders_table_query_args
     */
    public function includeCustomStatusesInAllHpos($args) {
        // Removed: rely on proper status registration like Hazerfen
        return $args;
    }

    
    /**
     * Auto create barcode when a new order is created (if enabled)
     */
    public function autoCreateOnNewOrder($order_id) {
        $auto_enabled = get_option('basit_kargo_auto_generate_barcode', 'yes') !== 'no';
        if (!$auto_enabled) { return; }
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        // Only if no barcode yet
        if (!$order->get_meta('basit_kargo_barcode')) {
            // If manual mode enabled, skip auto-create
            if ($order->get_meta('basit_kargo_manual_enabled') === 'yes') { return; }
            $api = new API();
            $result = $api->createBarcode($order);
            if ($result['success']) {
                $order->add_order_note(__('Basit Kargo: yeni siparişte otomatik barkod oluşturuldu', 'basit-kargo'));
                // Notify warehouse about the new barcode
                try { $email = new Email(); $email->sendOwnerEmail($order); } catch (\Throwable $e) {}
            }
        }
    }

    /**
     * Add custom cron interval (5 minutes)
     */
    public function addCustomCronInterval($schedules) {
        $schedules['basit_kargo_2min'] = array(
            'interval' => 2 * 60, // 2 minutes in seconds
            'display' => __('Her 2 Dakika', 'basit-kargo')
        );
        $schedules['basit_kargo_5min'] = array(
            'interval' => 5 * 60, // 5 minutes in seconds
            'display' => __('Her 5 Dakika', 'basit-kargo')
        );
        $schedules['basit_kargo_10min'] = array(
            'interval' => 10 * 60, // 10 minutes in seconds
            'display' => __('Her 10 Dakika', 'basit-kargo')
        );
        $schedules['basit_kargo_15min'] = array(
            'interval' => 15 * 60, // 15 minutes in seconds
            'display' => __('Her 15 Dakika', 'basit-kargo')
        );
        $schedules['basit_kargo_30min'] = array(
            'interval' => 30 * 60, // 30 minutes in seconds
            'display' => __('Her 30 Dakika', 'basit-kargo')
        );
        return $schedules;
    }
    
    /**
     * Schedule auto sync cron job
     */
    public function scheduleAutoSync() {
        // Mevcut cron job'ı temizle
        wp_clear_scheduled_hook('basit_kargo_auto_sync');
        
        // Yeni interval ile yeniden planla
        $interval = get_option('basit_kargo_auto_sync_interval', 'basit_kargo_5min');
        wp_schedule_event(time(), $interval, 'basit_kargo_auto_sync');
        
        error_log("BasitKargo Auto Sync: Cron job yeniden planlandı - Interval: $interval");
    }
    
    /**
     * Run auto sync
     */
    public function runAutoSync() {
        // Check if auto sync is enabled
        if (get_option('basit_kargo_auto_sync_enabled', 'yes') !== 'yes') {
            return;
        }
        
        // Sadece aktif siparişleri sync et (son 7 gün)
        $orders = wc_get_orders(array(
            'limit' => 50,
            'status' => array('processing', 'shipped', 'delivered'),
            'date_created' => '>' . (time() - 7 * 24 * 60 * 60),
            'meta_query' => array(
                array(
                    'key' => 'basit_kargo_barcode',
                    'compare' => 'EXISTS'
                )
            )
        ));
        
        if (empty($orders)) {
            return;
        }
        
        error_log("BasitKargo Auto Sync: " . count($orders) . " sipariş sync ediliyor");
        
        $api = new \BasitKargo\Api();
        $synced_count = 0;
        
        foreach ($orders as $order) {
            try {
                $result = $api->syncOrder($order->get_id());
                if ($result['success']) {
                    $synced_count++;
                }
            } catch (Exception $e) {
                error_log("BasitKargo Auto Sync Error for Order " . $order->get_id() . ": " . $e->getMessage());
            }
        }
        
        error_log("BasitKargo Auto Sync: $synced_count sipariş başarıyla sync edildi");
    }
    
    /**
     * Clean up cron jobs on deactivation
     */
    public function cleanupCronJobs() {
        wp_clear_scheduled_hook('basit_kargo_auto_sync');
    }
    
    /**
     * Auto create barcode when payment completes (if enabled)
     */
    public function autoCreateOnPaymentComplete($order_id) {
        $auto_enabled = get_option('basit_kargo_auto_generate_barcode', 'yes') !== 'no';
        if (!$auto_enabled) { return; }
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        if (!$order->get_meta('basit_kargo_barcode')) {
            // If manual mode enabled, skip auto-create
            if ($order->get_meta('basit_kargo_manual_enabled') === 'yes') { return; }
            $api = new API();
            $result = $api->createBarcode($order);
            if ($result['success']) {
                $order->add_order_note(__('Basit Kargo: ödeme tamamlandı, otomatik barkod oluşturuldu', 'basit-kargo'));
                // Notify warehouse about the new barcode
                try { $email = new Email(); $email->sendOwnerEmail($order); } catch (\Throwable $e) {}
            }
        }
    }
    
    /**
     * Add admin menu
     */
    public function addAdminMenu() {
        // WooCommerce altında tek menü olarak ekle
        add_submenu_page(
            'woocommerce',
            __('Basit Kargo', 'basit-kargo'),
            __('Basit Kargo', 'basit-kargo'),
            'manage_woocommerce',
            'basit-kargo',
            array($this, 'renderMainPage')
        );

        // Alt menüler (görünmez)
        add_submenu_page(
            'basit-kargo',
            __('Ayarlar', 'basit-kargo'),
            __('Ayarlar', 'basit-kargo'),
            'manage_woocommerce',
            'basit-kargo-settings',
            array($this, 'renderSettingsPage')
        );

        // 'Siparişler' alt menüsü kaldırıldı

        add_submenu_page(
            'basit-kargo',
            __('Senkronizasyon', 'basit-kargo'),
            __('Senkronizasyon', 'basit-kargo'),
            'manage_woocommerce',
            'basit-kargo-sync',
            array($this, 'renderSyncPage')
        );
    }
    
    /**
     * Add metabox to WooCommerce order edit page
     */
    public function addOrderMetabox() {
        // No-op: eşleştirme metaboxı kaldırıldı
    }

    /**
     * Render metabox content
     */
    public function renderOrderMetabox($post) {
        $order = wc_get_order($post->ID);
        if (!$order) {
            echo '<p>' . __('Sipariş bulunamadı.', 'basit-kargo') . '</p>';
            return;
        }

        $api_id = $order->get_meta('basit_kargo_api_id');
        $barcode = $order->get_meta('basit_kargo_barcode');
        $tracking_link = $order->get_meta('basit_kargo_handler_tracking_link');
        $handler_shipment_code = $order->get_meta('basit_kargo_handler_shipment_code');

        wp_nonce_field('basit_kargo_manual_map', 'bk_manual_map_nonce');

        echo '<div class="basit-kargo-metabox">';
        echo '<p><label for="bk_api_id"><strong>' . __('Basit Kargo Order ID', 'basit-kargo') . '</strong></label><br/>';
        echo '<input type="text" id="bk_api_id" name="bk_api_id" value="' . esc_attr($api_id) . '" class="widefat" placeholder="RN3-XXX-XXX" /></p>';

        echo '<p><label for="bk_barcode"><strong>' . __('Barkod', 'basit-kargo') . '</strong></label><br/>';
        echo '<input type="text" id="bk_barcode" name="bk_barcode" value="' . esc_attr($barcode) . '" class="widefat" placeholder="BST1234567890" /></p>';

        echo '<p><label for="bk_handler_code"><strong>' . __('Takip Kodu (Kargo)', 'basit-kargo') . '</strong></label><br/>';
        echo '<input type="text" id="bk_handler_code" name="bk_handler_code" value="' . esc_attr($handler_shipment_code) . '" class="widefat" placeholder="Kargo firmasının takip numarası" /></p>';

        if ($tracking_link) {
            echo '<p><a href="' . esc_url($tracking_link) . '" target="_blank" class="button">' . __('Takip Linki', 'basit-kargo') . '</a></p>';
        }

        echo '<p><button type="button" class="button button-primary" id="bk-manual-map-btn" data-order-id="' . esc_attr($order->get_id()) . '">' . __('Doğrula ve Senkronize Et', 'basit-kargo') . '</button></p>';
        echo '<form id="bk-metabox-fallback" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:none;">';
        echo '<input type="hidden" name="order_id" value="' . (int) $order->get_id() . '" />';
        echo '<input type="hidden" name="nonce" value="' . esc_attr(wp_create_nonce('basit_kargo_nonce')) . '" />';
        echo '<input type="hidden" name="action" value="bk_sync_barcode" />';
        echo '</form>';
        echo '<div id="bk-manual-map-result"></div>';
        echo '</div>';

        // Inline JS for AJAX action
        echo '<script>jQuery(function($){\n'
            . '$("#bk-manual-map-btn").on("click", function(){\n'
            . '  var $btn = $(this);\n'
            . '  var orderId = $btn.data("order-id");\n'
            . '  var apiId = $("#bk_api_id").val();\n'
            . '  var barcode = $("#bk_barcode").val();\n'
            . '  var trackingCode = $("#bk_handler_code").val();\n'
            . '  var nonce = $("#bk_manual_map_nonce, #bk-manual-map-nonce, input[name=\'bk_manual_map_nonce\']").val();\n'
            . '  $btn.prop("disabled", true).text("' . esc_js(__('Senkronize ediliyor...', 'basit-kargo')) . '");\n'
            . '  $("#bk-manual-map-result").html("");\n'
            . '  $.post(ajaxurl, { action: "basit_kargo_manual_map", nonce: nonce, order_id: orderId, api_id: apiId, barcode: barcode, tracking_code: trackingCode }, function(resp){\n'
            . '    if(resp && resp.success){\n'
            . '      $("#bk-manual-map-result").html("<div class=\\"notice notice-success\\"><p>" + resp.data.message + "</p></div>");\n'
            . '      // Hard reload fallback\n'
            . '      setTimeout(function(){ try{ var f=document.getElementById("bk-metabox-fallback"); if(f){ f.submit(); } else { location.reload(); } }catch(e){ location.reload(); } }, 300);\n'
            . '    } else {\n'
            . '      var msg = resp && resp.data ? resp.data : "' . esc_js(__('Bir hata oluştu', 'basit-kargo')) . '";\n'
            . '      $("#bk-manual-map-result").html("<div class=\\"notice notice-error\\"><p>" + msg + "</p></div>");\n'
            . '    }\n'
            . '  }).fail(function(){\n'
            . '    $("#bk-manual-map-result").html("<div class=\\"notice notice-error\\"><p>' . esc_js(__('İstek başarısız oldu.', 'basit-kargo')) . '</p></div>");\n'
            . '  }).always(function(){\n'
            . '    $btn.prop("disabled", false).text("' . esc_js(__('Doğrula ve Senkronize Et', 'basit-kargo')) . '");\n'
            . '  });\n'
            . '});</script>';
    }

    /**
     * Save metabox fields
     */
    public function saveOrderMetabox($post_id, $post, $update) {
        if (!isset($_POST['bk_manual_map_nonce']) || !wp_verify_nonce($_POST['bk_manual_map_nonce'], 'basit_kargo_manual_map')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if ($post->post_type !== 'shop_order') {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            return;
        }

        if (isset($_POST['bk_api_id'])) {
            $order->update_meta_data('basit_kargo_api_id', sanitize_text_field($_POST['bk_api_id']));
        }
        if (isset($_POST['bk_barcode'])) {
            $order->update_meta_data('basit_kargo_barcode', sanitize_text_field($_POST['bk_barcode']));
        }
        if (isset($_POST['bk_handler_code'])) {
            $order->update_meta_data('basit_kargo_handler_shipment_code', sanitize_text_field($_POST['bk_handler_code']));
        }
        $order->save();
    }

    /**
     * Handle manual mapping AJAX: save inputs and try to fetch & sync from API
     */
    public function handleManualMap() {
        check_ajax_referer('basit_kargo_manual_map', 'nonce');

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(__('Geçersiz sipariş ID', 'basit-kargo'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }

        $api_id = isset($_POST['api_id']) ? sanitize_text_field($_POST['api_id']) : '';
        $barcode = isset($_POST['barcode']) ? sanitize_text_field($_POST['barcode']) : '';
        $tracking_code = isset($_POST['tracking_code']) ? sanitize_text_field($_POST['tracking_code']) : '';

        if (empty($api_id) && empty($barcode)) {
            wp_send_json_error(__('Lütfen Basit Kargo Order ID veya Barkod girin', 'basit-kargo'));
        }

        if (!empty($api_id)) {
            $order->update_meta_data('basit_kargo_api_id', $api_id);
        }
        if (!empty($barcode)) {
            $order->update_meta_data('basit_kargo_barcode', $barcode);
        }
        if (!empty($tracking_code)) {
            $order->update_meta_data('basit_kargo_handler_shipment_code', $tracking_code);
        }
        $order->save();

        // Try to fetch details from API now
        $api = new \BasitKargo\API();
        $result = $api->fetchBarcodeData($order);

        if ($result['success']) {
            wp_send_json_success(array(
                'message' => __('Eşleştirme başarılı. Sipariş verileri senkronize edildi.', 'basit-kargo')
            ));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Save manual barcode and tracking from UI metabox
     */
    public function handleSaveManualMap() {
        // Debug log
        error_log('Basit Kargo: handleSaveManualMap called with POST data: ' . print_r($_POST, true));
        
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_POST['_ajax_nonce']) ? $_POST['_ajax_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'basit_kargo_nonce')) {
            error_log('Basit Kargo: Nonce verification failed. Nonce: ' . $nonce);
            wp_send_json_error(__('Geçersiz istek (nonce)', 'basit-kargo'));
        }
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log('Basit Kargo: Order not found. Order ID: ' . $order_id);
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }
        $barcode = isset($_POST['barcode']) ? sanitize_text_field(wp_unslash($_POST['barcode'])) : '';
        $tracking = isset($_POST['tracking']) ? sanitize_text_field(wp_unslash($_POST['tracking'])) : '';
        $handler = isset($_POST['handler']) ? sanitize_text_field(wp_unslash($_POST['handler'])) : '';
        $manual_enabled = isset($_POST['manual_enabled']) ? (int) $_POST['manual_enabled'] : null;
        
        error_log('Basit Kargo: Saving data - Handler: ' . $handler . ', Tracking: ' . $tracking . ', Barcode: ' . $barcode);
        
        if ($barcode !== '') {
            $order->update_meta_data('basit_kargo_barcode', $barcode);
        }
        if ($tracking !== '') {
            $order->update_meta_data('basit_kargo_handler_shipment_code', $tracking);
            $order->add_order_note(__('Manuel takip kodu güncellendi: ', 'basit-kargo') . $tracking);
        }
        if ($handler !== '') {
            $order->update_meta_data('basit_kargo_handler_name', $handler);
        }
        if ($manual_enabled !== null) {
            $order->update_meta_data('basit_kargo_manual_enabled', $manual_enabled ? 'yes' : 'no');
        }
        
        $saved = $order->save();
        error_log('Basit Kargo: Order save result: ' . ($saved ? 'success' : 'failed'));

        // If manual enabled and a tracking code is provided, mark as shipped and trigger email
        $is_manual_enabled = $order->get_meta('basit_kargo_manual_enabled') === 'yes';
        $has_manual_tracking = !empty($order->get_meta('basit_kargo_handler_shipment_code'));
        if ($is_manual_enabled && $has_manual_tracking) {
            if ($order->get_status() !== self::getShippedStatusKey()) {
                $order->update_status(self::getShippedStatusKey(), __('Manuel takip kodu girildi - Kargoya verildi', 'basit-kargo'));
            }
            $already_sent = $order->get_meta('basit_kargo_shipped_mail_sent');
            if (!$already_sent) {
                try {
                    $email = new \BasitKargo\Email();
                    $sent = $email->sendTrackingEmail($order);
                    if (is_array($sent) ? ($sent['success'] ?? false) : (bool) $sent) {
                        $order->update_meta_data('basit_kargo_shipped_mail_sent', current_time('mysql'));
                        $order->save();
                    }
                } catch (\Throwable $e) {}
            }
        }

        wp_send_json_success(array('message' => __('Kaydedildi', 'basit-kargo')));
    }

    /**
     * Non-AJAX fallback: handle form POST from UI if JS blocked
     */
    public function handleManualSaveForm() {
        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Yetkiniz yok', 'basit-kargo'));
        }
        $nonce = isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : (isset($_REQUEST['_wpnonce']) ? $_REQUEST['_wpnonce'] : '');
        if (!wp_verify_nonce($nonce, 'basit_kargo_nonce')) {
            wp_die(__('Geçersiz istek (nonce)', 'basit-kargo'));
        }
        $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_safe_redirect(admin_url('admin.php?page=wc-orders'));
            exit;
        }
        $barcode = isset($_REQUEST['barcode']) ? sanitize_text_field(wp_unslash($_REQUEST['barcode'])) : '';
        $tracking = isset($_REQUEST['tracking']) ? sanitize_text_field(wp_unslash($_REQUEST['tracking'])) : '';
        $handler = isset($_REQUEST['handler']) ? sanitize_text_field(wp_unslash($_REQUEST['handler'])) : '';
        $manual_enabled = isset($_REQUEST['manual_enabled']) ? (int) $_REQUEST['manual_enabled'] : null;
        
        if ($handler === '') {
            // Missing required carrier: redirect back with notice
            $ref = wp_get_referer();
            if ($ref) {
                $ref .= (strpos($ref, '?') === false ? '?' : '&') . 'bk_notice=' . rawurlencode(__('Lütfen kargo firmasını seçin.', 'basit-kargo')) . '&r=' . time();
                wp_safe_redirect($ref);
            } else {
                wp_safe_redirect(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id . '&bk_notice=' . rawurlencode(__('Lütfen kargo firmasını seçin.', 'basit-kargo'))));
            }
            exit;
        }
        
        // Update meta data
        if ($barcode !== '') { 
            $order->update_meta_data('basit_kargo_barcode', $barcode); 
        }
        if ($tracking !== '') {
            $order->update_meta_data('basit_kargo_handler_shipment_code', $tracking);
            $order->add_order_note(__('Manuel takip kodu güncellendi: ', 'basit-kargo') . $tracking);
        }
        if ($handler !== '') {
            $order->update_meta_data('basit_kargo_handler_name', $handler);
            $order->add_order_note(__('Kargo firması güncellendi: ', 'basit-kargo') . $handler);
        }
        if ($manual_enabled !== null) {
            $order->update_meta_data('basit_kargo_manual_enabled', $manual_enabled ? 'yes' : 'no');
        }
        
        // Save the order
        $saved = $order->save();
        
        if ($saved) {
            // Add success note
            $order->add_order_note(__('Manuel kargo bilgileri başarıyla kaydedildi.', 'basit-kargo'));

            // If manual enabled and tracking provided, mark as shipped and trigger email
            $is_manual_enabled = $order->get_meta('basit_kargo_manual_enabled') === 'yes';
            $has_manual_tracking = !empty($order->get_meta('basit_kargo_handler_shipment_code'));
            if ($is_manual_enabled && $has_manual_tracking) {
                if ($order->get_status() !== self::getShippedStatusKey()) {
                    $order->update_status(self::getShippedStatusKey(), __('Manuel takip kodu girildi - Kargoya verildi', 'basit-kargo'));
                }
                $already_sent = $order->get_meta('basit_kargo_shipped_mail_sent');
                if (!$already_sent) {
                    try {
                        $email = new \BasitKargo\Email();
                        $sent = $email->sendTrackingEmail($order);
                        if (is_array($sent) ? ($sent['success'] ?? false) : (bool) $sent) {
                            $order->update_meta_data('basit_kargo_shipped_mail_sent', current_time('mysql'));
                            $order->save();
                        }
                    } catch (\Throwable $e) {}
                }
            }
        }
        
        // Redirect back to order edit
        $redirect = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id . '&bk_success=1');
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Force-create a new barcode: clears existing Basit Kargo metas and recreates with unique foreign code
     */
    public function handleForceCreateBarcode() {
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_GET['_ajax_nonce']) ? $_GET['_ajax_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'basit_kargo_nonce')) { wp_send_json_error(__('Geçersiz istek', 'basit-kargo')); }
        $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }
        // If manual mode is enabled, automatically disable it before forcing barcode
        if ($order->get_meta('basit_kargo_manual_enabled') === 'yes') {
            $order->update_meta_data('basit_kargo_manual_enabled', 'no');
            $order->add_order_note(__('Manuel kargo devre dışı bırakıldı (Zorla Barkod).', 'basit-kargo'));
            $order->save();
        }
        // Clear all related metas to avoid server-side duplicates
        $meta_keys = array(
            'basit_kargo_api_id','basit_kargo_barcode','basit_kargo_reference','basit_kargo_status',
            'basit_kargo_handler_name','basit_kargo_handler_code','basit_kargo_handler_shipment_code',
            'basit_kargo_handler_tracking_link','basit_kargo_created_time','basit_kargo_updated_time',
            'basit_kargo_shipped_time','basit_kargo_delivered_time','basit_kargo_shipment_fee','basit_kargo_total_cost'
        );
        foreach ($meta_keys as $k) { $order->delete_meta_data($k); }
        $order->save();
        
        $api = new \BasitKargo\API();
        $unique_code = $order->get_order_number() . '-' . substr((string) time(), -4);
        $create = $api->createBarcode($order, $unique_code);
        if ($create['success']) {
            // Mark email sent to avoid duplicates in this request and other hooks
            $order->update_meta_data('basit_kargo_owner_mail_sent_at', current_time('mysql'));
            $order->save();
            try { $email = new \BasitKargo\Email(); $email->sendOwnerEmail($order); } catch (\Throwable $e) {}
            $redirect = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id() . '&r=' . time());
            $barcode = $order->get_meta('basit_kargo_barcode');
            wp_send_json_success(array('message' => __('Yeni barkod oluşturuldu', 'basit-kargo'), 'barcode' => $barcode, 'redirect' => $redirect));
        }
        wp_send_json_error($create['message'] ?? __('Barkod oluşturulamadı', 'basit-kargo'));
    }

    // Non-AJAX fallback: sync via admin-post
    public function handleBarcodeSyncForm() {
        $nonce = isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'basit_kargo_nonce')) { wp_die('Geçersiz istek'); }
        $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
        if (!$order_id) { wp_die('Sipariş bulunamadı'); }
        // Delegate to AJAX handler logic by calling API directly for simplicity
        $order = wc_get_order($order_id);
        if ($order) {
            $api = new \BasitKargo\API();
            $current_barcode = $order->get_meta('basit_kargo_barcode');
            if ($current_barcode) { $api->fetchBarcodeData($order); } else { $api->createBarcode($order); }
        }
        wp_safe_redirect(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id . '&r=' . time()));
        exit;
    }

    // Non-AJAX fallback: force create via admin-post
    public function handleForceCreateBarcodeForm() {
        $nonce = isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'basit_kargo_nonce')) { wp_die('Geçersiz istek'); }
        $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
        if (!$order_id) { wp_die('Sipariş bulunamadı'); }
        $order = wc_get_order($order_id);
        if ($order) {
            // If manual mode is enabled, automatically disable it before forcing barcode
            if ($order->get_meta('basit_kargo_manual_enabled') === 'yes') {
                $order->update_meta_data('basit_kargo_manual_enabled', 'no');
                $order->add_order_note(__('Manuel kargo devre dışı bırakıldı (Zorla Barkod).', 'basit-kargo'));
                $order->save();
            }
            $api = new \BasitKargo\API();
            $unique_code = $order->get_order_number() . '-' . substr((string) time(), -4);
            $api->createBarcode($order, $unique_code);
        }
        wp_safe_redirect(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id . '&r=' . time()));
        exit;
    }

    /**
     * Initialize settings
     */
    public function initSettings() {
        // Register settings
        register_setting('basit_kargo_settings', 'basit_kargo_token');
        register_setting('basit_kargo_settings', 'basit_kargo_handler_code');
        register_setting('basit_kargo_settings', 'basit_kargo_city_name');
        register_setting('basit_kargo_settings', 'basit_kargo_town_name');
        register_setting('basit_kargo_settings', 'basit_kargo_notify_email');
        register_setting('basit_kargo_settings', 'basit_kargo_auto_generate_barcode');
        register_setting('basit_kargo_settings', 'basit_kargo_auto_send_email');
        register_setting('basit_kargo_settings', 'basit_kargo_auto_send_delivered_email');
        register_setting('basit_kargo_settings', 'basit_kargo_auto_update_status');
        register_setting('basit_kargo_settings', 'basit_kargo_auto_sync_enabled');
        register_setting('basit_kargo_settings', 'basit_kargo_auto_sync_interval');
        
        // Add settings sections
        add_settings_section(
            'basit_kargo_main_section',
            __('Ana Ayarlar', 'basit-kargo'),
            array($this, 'mainSectionCallback'),
            'basit_kargo_settings'
        );
        
        add_settings_section(
            'basit_kargo_automation_section',
            __('Otomasyon Ayarları', 'basit-kargo'),
            array($this, 'automationSectionCallback'),
            'basit_kargo_settings'
        );
        
        add_settings_section(
            'basit_kargo_cancelled_failed_section',
            __('İptal/Başarısız Sipariş Yönetimi', 'basit-kargo'),
            array($this, 'cancelledFailedSectionCallback'),
            'basit_kargo_settings'
        );
        
        // Add settings fields
        add_settings_field(
            'basit_kargo_token',
            __('API Token', 'basit-kargo'),
            array($this, 'tokenFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_main_section'
        );
        
        add_settings_field(
            'basit_kargo_handler_code',
            __('Handler Kodu', 'basit-kargo'),
            array($this, 'handlerCodeFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_main_section'
        );
        
        add_settings_field(
            'basit_kargo_city_name',
            __('Şehir Adı', 'basit-kargo'),
            array($this, 'cityNameFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_main_section'
        );
        
        add_settings_field(
            'basit_kargo_town_name',
            __('İlçe Adı', 'basit-kargo'),
            array($this, 'townNameFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_main_section'
        );
        
        add_settings_field(
            'basit_kargo_notify_email',
            __('Bildirim E-postası', 'basit-kargo'),
            array($this, 'notifyEmailFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_main_section'
        );
        
        add_settings_field(
            'basit_kargo_auto_generate_barcode',
            __('Otomatik Barkod Oluştur', 'basit-kargo'),
            array($this, 'autoGenerateBarcodeFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_automation_section'
        );
        
        add_settings_field(
            'basit_kargo_auto_send_email',
            __('Otomatik E-posta Gönder', 'basit-kargo'),
            array($this, 'autoSendEmailFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_automation_section'
        );
        
        add_settings_field(
            'basit_kargo_auto_send_delivered_email',
            __('Teslim Edildi E-postasını Gönder', 'basit-kargo'),
            array($this, 'autoSendDeliveredEmailFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_automation_section'
        );
        
        add_settings_field(
            'basit_kargo_auto_update_status',
            __('Otomatik Durum Güncelle', 'basit-kargo'),
            array($this, 'autoUpdateStatusFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_automation_section'
        );
        
        add_settings_field(
            'basit_kargo_auto_sync_enabled',
            __('Otomatik Senkronizasyon', 'basit-kargo'),
            array($this, 'autoSyncEnabledFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_automation_section'
        );
        
        add_settings_field(
            'basit_kargo_auto_sync_interval',
            __('Senkronizasyon Sıklığı', 'basit-kargo'),
            array($this, 'autoSyncIntervalFieldCallback'),
            'basit_kargo_settings',
            'basit_kargo_automation_section'
        );
    }
    
    /**
     * Section callbacks
     */
    public function mainSectionCallback() {
        echo '<p>' . __('Basit Kargo API ayarlarını yapılandırın.', 'basit-kargo') . '</p>';
    }
    
    public function automationSectionCallback() {
        echo '<p>' . __('Otomatik işlemler için ayarları yapılandırın.', 'basit-kargo') . '</p>';
    }
    
    public function cancelledFailedSectionCallback() {
        echo '<p>' . __('İptal edilen ve başarısız siparişleri yönetin.', 'basit-kargo') . '</p>';
    }
    
    /**
     * Field callbacks
     */
    public function tokenFieldCallback() {
        $value = get_option('basit_kargo_token', '');
        echo '<input type="text" name="basit_kargo_token" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Basit Kargo API token\'ınızı girin.', 'basit-kargo') . '</p>';
    }
    
    public function handlerCodeFieldCallback() {
        $value = get_option('basit_kargo_handler_code', '');
        echo '<input type="text" name="basit_kargo_handler_code" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Handler kodunuzu girin.', 'basit-kargo') . '</p>';
    }
    
    public function cityNameFieldCallback() {
        $value = get_option('basit_kargo_city_name', '');
        echo '<input type="text" name="basit_kargo_city_name" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Gönderen şehir adını girin.', 'basit-kargo') . '</p>';
    }
    
    public function townNameFieldCallback() {
        $value = get_option('basit_kargo_town_name', '');
        echo '<input type="text" name="basit_kargo_town_name" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Gönderen ilçe adını girin.', 'basit-kargo') . '</p>';
    }
    
    public function notifyEmailFieldCallback() {
        $value = get_option('basit_kargo_notify_email', get_option('admin_email'));
        echo '<input type="email" name="basit_kargo_notify_email" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Bildirim e-postası adresini girin.', 'basit-kargo') . '</p>';
    }
    
    public function autoGenerateBarcodeFieldCallback() {
        $value = get_option('basit_kargo_auto_generate_barcode', 'yes');
        echo '<input type="checkbox" name="basit_kargo_auto_generate_barcode" value="yes" ' . checked($value, 'yes', false) . ' />';
        echo '<p class="description">' . __('Sipariş durumu değiştiğinde otomatik barkod oluştur.', 'basit-kargo') . '</p>';
    }
    
    public function autoSendEmailFieldCallback() {
        $value = get_option('basit_kargo_auto_send_email', 'yes');
        echo '<input type="checkbox" name="basit_kargo_auto_send_email" value="yes" ' . checked($value, 'yes', false) . ' />';
        echo '<p class="description">' . __('Barkod oluşturulduğunda otomatik e-posta gönder.', 'basit-kargo') . '</p>';
    }
    
    public function autoSendDeliveredEmailFieldCallback() {
        $value = get_option('basit_kargo_auto_send_delivered_email', 'yes');
        echo '<input type="checkbox" name="basit_kargo_auto_send_delivered_email" value="yes" ' . checked($value, 'yes', false) . ' />';
        echo '<p class="description">' . __('Sipariş "Teslim Edildi" olduğunda müşteriye bilgilendirme e-postası gönder.', 'basit-kargo') . '</p>';
    }
    
    public function autoUpdateStatusFieldCallback() {
        $value = get_option('basit_kargo_auto_update_status', 'yes');
        echo '<input type="checkbox" name="basit_kargo_auto_update_status" value="yes" ' . checked($value, 'yes', false) . ' />';
        echo '<p class="description">' . __('Takip kodu (kargo firması kodu) ilk kez oluştuğunda sipariş durumunu "Kargoya Verildi" yap.', 'basit-kargo') . '</p>';
    }
    
    public function autoSyncEnabledFieldCallback() {
        $value = get_option('basit_kargo_auto_sync_enabled', 'yes');
        echo '<input type="checkbox" name="basit_kargo_auto_sync_enabled" value="yes" ' . checked($value, 'yes', false) . ' />';
        echo '<p class="description">' . __('Otomatik olarak siparişleri Basit Kargo ile senkronize et.', 'basit-kargo') . '</p>';
    }
    
    public function autoSyncIntervalFieldCallback() {
        $value = get_option('basit_kargo_auto_sync_interval', 'basit_kargo_5min');
        $intervals = array(
            'basit_kargo_2min' => __('Her 2 Dakika', 'basit-kargo'),
            'basit_kargo_5min' => __('Her 5 Dakika', 'basit-kargo'),
            'basit_kargo_10min' => __('Her 10 Dakika', 'basit-kargo'),
            'basit_kargo_15min' => __('Her 15 Dakika', 'basit-kargo'),
            'basit_kargo_30min' => __('Her 30 Dakika', 'basit-kargo'),
            'hourly' => __('Her Saat', 'basit-kargo'),
            'twicedaily' => __('Günde 2 Kez', 'basit-kargo'),
            'daily' => __('Günde 1 Kez', 'basit-kargo')
        );
        
        echo '<select name="basit_kargo_auto_sync_interval">';
        foreach ($intervals as $key => $label) {
            echo '<option value="' . $key . '" ' . selected($value, $key, false) . '>' . $label . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Senkronizasyon sıklığını seçin.', 'basit-kargo') . '</p>';
    }
    
    /**
     * Render main page with tabs
     */
    public function renderMainPage() {
        $current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';
        if ($current_tab === 'orders') { $current_tab = 'settings'; }
        
        echo '<div class="wrap">';
        echo '<h1>' . __('Basit Kargo', 'basit-kargo') . '</h1>';
        
        // Tab navigation
        echo '<nav class="nav-tab-wrapper">';
        echo '<a href="' . admin_url('admin.php?page=basit-kargo&tab=settings') . '" class="nav-tab ' . ($current_tab === 'settings' ? 'nav-tab-active' : '') . '">' . __('Ayarlar', 'basit-kargo') . '</a>';
        
        echo '<a href="' . admin_url('admin.php?page=basit-kargo&tab=sync') . '" class="nav-tab ' . ($current_tab === 'sync' ? 'nav-tab-active' : '') . '">' . __('Senkronizasyon', 'basit-kargo') . '</a>';
        echo '</nav>';
        
        // Tab content
        echo '<div class="tab-content">';
        switch ($current_tab) {
            case 'sync':
                $this->renderSyncPage();
                break;
            case 'settings':
            default:
                $this->renderSettingsPage();
                break;
        }
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Render settings page
     */
    public function renderSettingsPage() {
        if (isset($_POST['submit'])) {
            $this->saveSettings();
        }
        
        echo '<div class="wrap">';
        echo '<h1>' . __('Basit Kargo Ayarları', 'basit-kargo') . '</h1>';
        
        echo '<form method="post" action="">';
        settings_fields('basit_kargo_settings');
        do_settings_sections('basit_kargo_settings');
        submit_button();
        echo '</form>';
        
        // İstatistik bölümü kaldırıldı
        
        echo '</div>';
    }
    
    /**
     * Save settings
     */
    private function saveSettings() {
        $settings = array(
            'basit_kargo_token',
            'basit_kargo_handler_code',
            'basit_kargo_city_name',
            'basit_kargo_town_name',
            'basit_kargo_notify_email',
            'basit_kargo_auto_generate_barcode',
            'basit_kargo_auto_send_email',
            'basit_kargo_auto_send_delivered_email',
            'basit_kargo_auto_update_status',
            'basit_kargo_auto_sync_enabled',
            'basit_kargo_auto_sync_interval'
        );
        
        foreach ($settings as $setting) {
            if (isset($_POST[$setting])) {
                update_option($setting, sanitize_text_field($_POST[$setting]));
            } else {
                update_option($setting, '');
            }
        }
        
        // Eğer sync interval değiştiyse cron job'ı yeniden planla
        if (isset($_POST['basit_kargo_auto_sync_interval'])) {
            $this->scheduleAutoSync();
        }
        
        echo '<div class="notice notice-success"><p>' . __('Ayarlar kaydedildi.', 'basit-kargo') . '</p></div>';
    }
    
    /**
     * Render statistics
     */
    private function renderStatistics() {
        echo '<div class="basit-kargo-metabox" style="margin-top: 20px;">';
        echo '<h2>' . __('İstatistikler', 'basit-kargo') . '</h2>';
        
        // Get order counts
        $total_orders = $this->getOrderCount();
        $barcode_orders = $this->getBarcodeOrderCount();
        $cancelled_orders = $this->getCancelledOrderCount();
        $failed_orders = $this->getFailedOrderCount();
        $refunded_orders = $this->getRefundedOrderCount();
        
        echo '<table class="basit-kargo-table">';
        echo '<tr><td><strong>' . __('Toplam Sipariş:', 'basit-kargo') . '</strong></td><td>' . $total_orders . '</td></tr>';
        echo '<tr><td><strong>' . __('Barkodlu Sipariş:', 'basit-kargo') . '</strong></td><td>' . $barcode_orders . '</td></tr>';
        echo '<tr><td><strong>' . __('İptal Edilen:', 'basit-kargo') . '</strong></td><td>' . $cancelled_orders . '</td></tr>';
        echo '<tr><td><strong>' . __('Başarısız:', 'basit-kargo') . '</strong></td><td>' . $failed_orders . '</td></tr>';
        echo '<tr><td><strong>' . __('İade Edilen:', 'basit-kargo') . '</strong></td><td>' . $refunded_orders . '</td></tr>';
        echo '</table>';
        
        // Sync button
        echo '<div style="margin-top: 15px;">';
        echo '<button class="basit-kargo-button" data-action="basit_kargo_sync_cancelled_failed_orders">' . __('🔄 İptal/Başarısız Siparişleri Senkronize Et', 'basit-kargo') . '</button>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Render orders page
     */
    public function renderOrdersPage() {
        echo '<div class="wrap">';
        echo '<h1>' . __('Basit Kargo Siparişleri', 'basit-kargo') . '</h1>';
        
        // Get orders with barcodes
        $orders = wc_get_orders(array(
            'limit' => 50,
            'meta_query' => array(
                array(
                    'key' => 'basit_kargo_barcode',
                    'compare' => 'EXISTS'
                )
            )
        ));
        
        if (!empty($orders)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>' . __('Sipariş', 'basit-kargo') . '</th>';
            echo '<th>' . __('Müşteri', 'basit-kargo') . '</th>';
            echo '<th>' . __('Barkod', 'basit-kargo') . '</th>';
            echo '<th>' . __('Durum', 'basit-kargo') . '</th>';
            echo '<th>' . __('Tarih', 'basit-kargo') . '</th>';
            echo '<th>' . __('İşlemler', 'basit-kargo') . '</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';
            
            foreach ($orders as $order) {
                $barcode = $order->get_meta('basit_kargo_barcode');
                echo '<tr>';
                echo '<td><a href="' . admin_url('post.php?post=' . $order->get_id() . '&action=edit') . '">#' . $order->get_order_number() . '</a></td>';
                echo '<td>' . $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() . '</td>';
                echo '<td>' . $barcode . '</td>';
                echo '<td>' . wc_get_order_status_name($order->get_status()) . '</td>';
                echo '<td>' . $order->get_date_created()->date_i18n(get_option('date_format')) . '</td>';
                echo '<td>';
                echo '<a href="https://basitkargo.com/takip/' . $barcode . '" target="_blank" class="basit-kargo-button">' . __('Takip Et', 'basit-kargo') . '</a>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody>';
            echo '</table>';
        } else {
            echo '<p>' . __('Henüz barkodlu sipariş bulunmuyor.', 'basit-kargo') . '</p>';
        }
        
        echo '</div>';
    }
    
    /**
     * Handle order status change
     */
    public function handleOrderStatusChange($order_id, $old_status, $new_status) {
        // Auto-generate enabled by default unless explicitly disabled in settings
        $auto_enabled = get_option('basit_kargo_auto_generate_barcode', 'yes') !== 'no';
        if ($auto_enabled) {
            $order = wc_get_order($order_id);
            
            // Hazırlanıyor (processing) aşamasına geçişte tetikle
            if ($order && in_array($new_status, array('processing', 'completed'))) {
                $api = new API();
                $order->add_order_note(__('Basit Kargo: durum değişiminde otomatik tetikleme', 'basit-kargo'));
                $barcode = $order->get_meta('basit_kargo_barcode');
                if (empty($barcode)) {
                    $create = $api->createBarcode($order);
                    if ($create['success']) {
                        $order->add_order_note(__('Otomatik barkod oluşturuldu', 'basit-kargo'));
                        // Send owner mail once
                        if (!$order->get_meta('basit_kargo_owner_mail_sent_at')) {
                            $email = new Email();
                            $email->sendOwnerEmail($order);
                            $order->update_meta_data('basit_kargo_owner_mail_sent_at', current_time('mysql'));
                            $order->save();
                        }
                    }
                } else {
                    $sync = $api->fetchBarcodeData($order);
                    if (!$sync['success']) {
                        // Silinmişse temizle ve benzersiz kodla oluştur
                        $order->delete_meta_data('basit_kargo_api_id');
                        $order->delete_meta_data('basit_kargo_barcode');
                        $order->save();
                        $unique_code = $order->get_order_number() . '-' . substr((string) time(), -4);
                        $create = $api->createBarcode($order, $unique_code);
                        if ($create['success']) {
                            // Clear manual carrier + tracking since we created a fresh barcode
                            $order->delete_meta_data('basit_kargo_handler_shipment_code');
                            $order->delete_meta_data('basit_kargo_handler_name');
                            $order->save();
                            $order->add_order_note(__('Uzak kayıt bulunamadı, yeni barkod oluşturuldu', 'basit-kargo'));
                            if (!$order->get_meta('basit_kargo_owner_mail_sent_at')) {
                                $email = new Email();
                                $email->sendOwnerEmail($order);
                                $order->update_meta_data('basit_kargo_owner_mail_sent_at', current_time('mysql'));
                                $order->save();
                            }
                        }
                    }
                }
            }
            
            // Handle cancelled/failed orders
            if (in_array($new_status, array('cancelled', 'failed', 'refunded'))) {
                $this->handleCancelledFailedOrder($order_id, $new_status);
            }
        }
    }
    
    /**
     * Handle cancelled/failed order
     */
    private function handleCancelledFailedOrder($order_id, $status) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        $barcode = $order->get_meta('basit_kargo_barcode');
        
        if ($barcode) {
            // Update existing order status
            $this->updateOrderStatus($order, $status);
        } else {
            // Create new cancelled/failed entry
            $this->createCancelledFailedEntry($order, $status);
        }
    }
    
    /**
     * Update order status in Basit Kargo
     */
    private function updateOrderStatus($order, $status) {
        $api = new API();
        $result = $api->updateOrderStatus($order, $status);
        
        if ($result['success']) {
            $order->add_order_note(__('Basit Kargo durumu güncellendi: ', 'basit-kargo') . $status);
        }
    }
    
    /**
     * Create cancelled/failed entry
     */
    private function createCancelledFailedEntry($order, $status) {
        $api = new API();
        $result = $api->createCancelledFailedEntry($order, $status);
        
        if ($result['success']) {
            $order->add_order_note(__('Basit Kargo iptal/başarısız kaydı oluşturuldu', 'basit-kargo'));
        }
    }
    
    /**
     * Sync cancelled/failed orders
     */
    public function syncCancelledFailedOrders() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $orders = wc_get_orders(array(
            'status' => array('cancelled', 'failed', 'refunded'),
            'limit' => -1,
            'meta_query' => array(
                array(
                    'key' => 'basit_kargo_barcode',
                    'compare' => 'EXISTS'
                )
            )
        ));
        
        $synced = 0;
        $errors = 0;
        
        foreach ($orders as $order) {
            $result = $this->handleCancelledFailedOrder($order->get_id(), $order->get_status());
            if ($result) {
                $synced++;
            } else {
                $errors++;
            }
        }
        
        wp_send_json_success(array(
            'synced' => $synced,
            'errors' => $errors,
            'message' => $synced . ' sipariş senkronize edildi, ' . $errors . ' hata'
        ));
    }
    
    /**
     * Get order counts
     */
    private function getOrderCount() {
        return wp_count_posts('shop_order')->publish;
    }
    
    private function getBarcodeOrderCount() {
        global $wpdb;
        return $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->postmeta} pm 
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
            WHERE pm.meta_key = 'basit_kargo_barcode' 
            AND p.post_type = 'shop_order'
        ");
    }
    
    private function getCancelledOrderCount() {
        return wp_count_posts('shop_order')->wc-cancelled;
    }
    
    private function getFailedOrderCount() {
        return wp_count_posts('shop_order')->wc-failed;
    }
    
    private function getRefundedOrderCount() {
        return wp_count_posts('shop_order')->wc-refunded;
    }
    
    /**
     * Handle generate barcode AJAX request
     */
    public function handleGenerateBarcode() {
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_GET['_ajax_nonce']) ? $_GET['_ajax_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'basit_kargo_nonce')) { wp_send_json_error(__('Geçersiz istek', 'basit-kargo')); }
        
        $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }
        
        $api = new \BasitKargo\API();
        $result = $api->generateBarcode($order);
        
        if ($result['success']) {
            // Notify warehouse
            try { $email = new \BasitKargo\Email(); $email->sendOwnerEmail($order); } catch (\Throwable $e) {}
            $redirect = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id() . '&r=' . time());
            $barcode = $order->get_meta('basit_kargo_barcode');
            wp_send_json_success(array('message' => __('Barkod başarıyla oluşturuldu', 'basit-kargo'), 'barcode' => $barcode, 'redirect' => $redirect));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Handle print PDF AJAX request
     */
    public function handlePrintPdf() {
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_GET['_ajax_nonce']) ? $_GET['_ajax_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'basit_kargo_nonce')) { wp_send_json_error(__('Geçersiz istek', 'basit-kargo')); }
        
        $order_id = isset($_REQUEST['order_id']) ? intval($_REQUEST['order_id']) : 0;
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }
        
        $pdf = new \BasitKargo\PDF();
        $result = $pdf->generatePDFForOrder($order);
        
        if ($result['success']) {
            wp_send_json_success(__('PDF başarıyla oluşturuldu', 'basit-kargo'));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Handle print barcode AJAX request
     */
    public function handlePrintBarcode() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }
        
        $barcode = $order->get_meta('basit_kargo_barcode');
        if (!$barcode) {
            wp_send_json_error(__('Barkod bulunamadı', 'basit-kargo'));
        }
        
        // Redirect to print page
        $print_url = admin_url('admin.php?page=basit-kargo-print&order_id=' . $order_id);
        wp_send_json_success(array('redirect' => $print_url));
    }
    
    /**
     * Handle barcode sync AJAX request
     */
    public function handleBarcodeSync() {
        // Clear any output buffer to prevent "orbitcamper.com says" error
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        try {
            check_ajax_referer('basit_kargo_nonce', 'nonce');
            
            $order_id = intval($_POST['order_id']);
            $order = wc_get_order($order_id);
            
            if (!$order) {
                wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
                return;
            }
            
            // Prevent concurrent duplicate syncs (5s lock)
            $lock_key = 'basit_kargo_sync_lock_' . $order_id;
            if (get_transient($lock_key)) {
                wp_send_json_error(__('Senkron işlemi zaten sürüyor, lütfen bekleyin.', 'basit-kargo'));
            }
            set_transient($lock_key, 1, 5);

            $api = new \BasitKargo\API();
            $current_barcode = $order->get_meta('basit_kargo_barcode');
            if (empty($current_barcode)) {
                // Barkod yoksa oluştur ve bitir
                $create = $api->createBarcode($order);
                if ($create['success']) {
                    if (!$order->get_meta('basit_kargo_owner_mail_sent_at')) {
                        try { $email = new \BasitKargo\Email(); $email->sendOwnerEmail($order); } catch (\Throwable $e) {}
                        $order->update_meta_data('basit_kargo_owner_mail_sent_at', current_time('mysql'));
                        $order->save();
                    }
                    delete_transient($lock_key);
                    wp_send_json_success(__('Barkod oluşturuldu', 'basit-kargo'));
                } else {
                    delete_transient($lock_key);
                    wp_send_json_error($create['message'] ?? __('Barkod oluşturulamadı', 'basit-kargo'));
                }
            }
            // Barkod varsa önce senkron dene
            $sync = $api->fetchBarcodeData($order);
            if ($sync['success']) {
                // Extra guard: ensure order still has a barcode after sync
                $refreshed = wc_get_order($order_id);
                $new_barcode_after_sync = $refreshed ? $refreshed->get_meta('basit_kargo_barcode') : $order->get_meta('basit_kargo_barcode');
                if (!empty($new_barcode_after_sync)) {
                    $redirect = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id() . '&r=' . time());
                    delete_transient($lock_key);
                    wp_send_json_success(array('message' => __('Barkod senkronize edildi', 'basit-kargo'), 'barcode' => $new_barcode_after_sync, 'redirect' => $redirect));
                }
                // If API reported success but barcode meta yok, yeni barkod oluşturmayı dene
                $order->delete_meta_data('basit_kargo_api_id');
                $order->delete_meta_data('basit_kargo_barcode');
                $order->save();
                $unique_code_fallback = $order->get_order_number() . '-' . substr((string) time(), -4);
                $create_fallback = $api->createBarcode($order, $unique_code_fallback);
                if ($create_fallback['success']) {
                    // Clear manual carrier + tracking since we created a fresh barcode
                    $order->delete_meta_data('basit_kargo_handler_shipment_code');
                    $order->delete_meta_data('basit_kargo_handler_name');
                    $order->save();
                    $redirect = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id() . '&r=' . time());
                    delete_transient($lock_key);
                    wp_send_json_success(array('message' => __('Yeni barkod oluşturuldu', 'basit-kargo'), 'barcode' => $order->get_meta('basit_kargo_barcode'), 'redirect' => $redirect));
                }
            }
            // Uzakta silinmiş olabilir: eski meta temizle ve benzersiz kodla tek seferlik oluştur
            $order->delete_meta_data('basit_kargo_api_id');
            $order->delete_meta_data('basit_kargo_barcode');
            $order->save();
            $unique_code = $order->get_order_number() . '-' . substr((string) time(), -4);
            $create2 = $api->createBarcode($order, $unique_code);
            if ($create2['success']) {
                if (!$order->get_meta('basit_kargo_owner_mail_sent_at')) {
                    try { $email = new \BasitKargo\Email(); $email->sendOwnerEmail($order); } catch (\Throwable $e) {}
                    $order->update_meta_data('basit_kargo_owner_mail_sent_at', current_time('mysql'));
                    $order->save();
                }
                // Clear manual carrier + tracking since we created a fresh barcode
                $order->delete_meta_data('basit_kargo_handler_shipment_code');
                $order->delete_meta_data('basit_kargo_handler_name');
                $order->save();
                $redirect = admin_url('admin.php?page=wc-orders&action=edit&id=' . $order->get_id() . '&r=' . time());
                delete_transient($lock_key);
                wp_send_json_success(array('message' => __('Yeni barkod oluşturuldu', 'basit-kargo'), 'barcode' => $order->get_meta('basit_kargo_barcode'), 'redirect' => $redirect));
            }
            delete_transient($lock_key);
            wp_send_json_error($sync['message'] ?? __('Senkronizasyon başarısız', 'basit-kargo'));
        } catch (Exception $e) {
            if (isset($lock_key)) { delete_transient($lock_key); }
            wp_send_json_error(__('Bir hata oluştu: ', 'basit-kargo') . $e->getMessage());
        }
    }
    
    /**
     * Handle tracking sync AJAX request
     */
    public function handleTrackingSync() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }
        
        $api = new \BasitKargo\API();
        $result = $api->syncTrackingInfo($order);
        
        if ($result['success']) {
            wp_send_json_success(__('Takip bilgileri başarıyla senkronize edildi', 'basit-kargo'));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Handle send mail AJAX request (customer)
     */
    public function handleSendMail() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }
        
        $email = new \BasitKargo\Email();
        $result = $email->sendTrackingEmail($order);
        
        if ($result['success']) {
            wp_send_json_success(__('Müşteriye mail başarıyla gönderildi', 'basit-kargo'));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Handle send owner mail AJAX request
     */
    public function handleSendOwnerMail() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }
        
        $email = new \BasitKargo\Email();
        $result = $email->sendOwnerEmail($order);
        
        if ($result['success']) {
            wp_send_json_success(__('Site sahibine mail başarıyla gönderildi', 'basit-kargo'));
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Handle send delivered mail AJAX request (customer)
     */
    public function handleSendDeliveredMail() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }
        
        $email = new \BasitKargo\Email();
        $sent = $email->sendDeliveredEmail($order);
        if ($sent) {
            $order->update_meta_data('basit_kargo_delivered_mail_sent', current_time('mysql'));
            $order->save();
            wp_send_json_success(__('Teslim maili gönderildi', 'basit-kargo'));
        } else {
            wp_send_json_error(__('Teslim maili gönderilemedi', 'basit-kargo'));
        }
    }
    
    /**
     * Handle convert old meta data AJAX request
     */
    public function handleConvertOldMeta() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Sipariş bulunamadı', 'basit-kargo'));
        }
        
        $result = $this->convertOldMetaData($order);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Convert old meta data format to new format
     */
    private function convertOldMetaData($order) {
        $old_handler_code = $order->get_meta('basit_kargo_handler_code');
        $old_tracking_status = $order->get_meta('basit_kargo_tracking_status');
        $old_tracking_link = $order->get_meta('basit_kargo_tracking_link');
        $old_tracking_number = $order->get_meta('basit_kargo_tracking_number');
        $old_tracking_date = $order->get_meta('basit_kargo_tracking_date');
        
        if (!$old_handler_code) {
            return array('success' => false, 'message' => 'Eski format meta veri bulunamadı');
        }
        
        // Convert handler code to handler name
        $handler_names = array(
            'SURAT' => 'Surat Kargo',
            'YURTICI' => 'Yurtiçi Kargo',
            'ARAS' => 'Aras Kargo',
            'MNG' => 'MNG Kargo',
            'PTT' => 'PTT Kargo',
            'UPS' => 'UPS Kargo',
            'DHL' => 'DHL Kargo',
            'FEDEX' => 'FedEx Kargo'
        );
        
        $handler_name = isset($handler_names[$old_handler_code]) ? $handler_names[$old_handler_code] : $old_handler_code . ' Kargo';
        
        // Update meta data
        $order->update_meta_data('basit_kargo_handler_name', $handler_name);
        $order->update_meta_data('basit_kargo_status', $old_tracking_status);
        $order->update_meta_data('basit_kargo_handler_tracking_link', $old_tracking_link);
        $order->update_meta_data('basit_kargo_handler_shipment_code', $old_tracking_number);
        
        if ($old_tracking_status === 'teslim_edildi') {
            $order->update_meta_data('basit_kargo_delivered_time', $old_tracking_date);
        } elseif ($old_tracking_status === 'kargoya_verildi') {
            $order->update_meta_data('basit_kargo_shipped_time', $old_tracking_date);
        }
        
        $order->save();
        
        return array('success' => true, 'message' => 'Eski format meta veriler yeni formata dönüştürüldü');
    }
    
    /**
     * Handle sync completed orders AJAX request
     */
    public function handleSyncCompletedOrders() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 50;
        
        $api = new \BasitKargo\API();
        $result = $api->syncCompletedOrders($limit);
        
        if ($result['success']) {
            $message = sprintf(
                __('%d sipariş bulundu, %d sipariş senkronize edildi.', 'basit-kargo'),
                $result['total_found'],
                $result['synced_count']
            );
            
            if (!empty($result['errors'])) {
                $message .= ' Hatalar: ' . implode(', ', $result['errors']);
            }
            
            wp_send_json_success($message);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Render sync page
     */
    public function renderSyncPage() {
        ?>
        <div class="card">
            <h2><?php _e('Otomatik Senkronizasyon', 'basit-kargo'); ?></h2>
            <div class="card">
                <h2><?php _e('Otomatik Senkronizasyon', 'basit-kargo'); ?></h2>
                <p><?php _e('Basit Kargo\'dan tüm siparişleri otomatik olarak keşfet ve WooCommerce ile senkronize et.', 'basit-kargo'); ?></p>
                <p class="submit">
                    <button type="button" class="button button-primary" id="bulk-discover-sync-btn"><?php _e('🔄 Tüm Siparişleri Senkronize Et', 'basit-kargo'); ?></button>
                </p>
                <div id="bulk-sync-results" style="margin-top: 20px; display: none;">
                    <h3><?php _e('Senkronizasyon Sonuçları', 'basit-kargo'); ?></h3>
                    <div id="bulk-sync-results-content"></div>
                </div>
            </div>
            
            <div class="card">
                <h2><?php _e('CSV ile Toplu Eşleştirme', 'basit-kargo'); ?></h2>
                <p><?php _e('Tamamlanan siparişlerinizi CSV dosyası ile içe aktararak Basit Kargo ID/Barkod eşleştirmesini topluca yapın.', 'basit-kargo'); ?></p>
                <p><?php _e('CSV sütunları: order_id veya order_number, basit_kargo_id (veya api_id), barcode. Basit Kargo dışa aktarımı (Türkçe başlıklar) da desteklenir.', 'basit-kargo'); ?></p>
                <form id="csv-import-form" enctype="multipart/form-data">
                    <?php wp_nonce_field('basit_kargo_nonce', 'nonce'); ?>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" />
                    <p class="submit" style="margin-top:10px;">
                        <button type="submit" class="button button-primary" id="import-csv-btn"><?php _e('CSV Yükle ve Senkronize Et', 'basit-kargo'); ?></button>
                    </p>
                </form>
                <div id="csv-import-results" style="margin-top: 20px; display: none;">
                    <h3><?php _e('İçe Aktarma Sonuçları', 'basit-kargo'); ?></h3>
                    <div id="csv-import-results-content"></div>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).ready(function($) {
            // Bulk discover sync
            $('#bulk-discover-sync-btn').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $results = $('#bulk-sync-results');
                var $content = $('#bulk-sync-results-content');
                
                $btn.prop('disabled', true).text('Senkronize ediliyor...');
                $results.show();
                $content.html('<p>Senkronizasyon başlatılıyor...</p>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'basit_kargo_bulk_discover_sync',
                        nonce: $('#nonce').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $content.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                        } else {
                            $content.html('<div class="notice notice-error"><p>Hata: ' + response.data + '</p></div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX Error:', xhr.responseText);
                        $content.html('<div class="notice notice-error"><p>Senkronizasyon sırasında hata oluştu: ' + error + '</p></div>');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('🔄 Tüm Siparişleri Senkronize Et');
                    }
                });
            });
            
            // CSV import only
            $('#csv-import-form').on('submit', function(e) {
                e.preventDefault();
                var $btn = $('#import-csv-btn');
                var $results = $('#csv-import-results');
                var $content = $('#csv-import-results-content');

                var fileInput = $('#csv_file')[0];
                if (!fileInput.files.length) {
                    $results.show();
                    $content.html('<div class="notice notice-error"><p><?php _e('Lütfen bir CSV dosyası seçin.', 'basit-kargo'); ?></p></div>');
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'basit_kargo_import_csv');
                formData.append('nonce', $('#nonce').val());
                formData.append('csv_file', fileInput.files[0]);

                $btn.prop('disabled', true).text('<?php _e('Yükleniyor ve işleniyor...', 'basit-kargo'); ?>');
                $results.hide();

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            var d = response.data;
                            var html = '<div class="notice notice-success"><p>' + d.message + '</p></div>';
                            if (d.details && d.details.length) {
                                html += '<table class="wp-list-table widefat fixed striped">';
                                html += '<thead><tr><th>#</th><th>Order</th><th>API ID</th><th>Barcode</th><th>Durum</th><th>Mesaj</th></tr></thead><tbody>';
                                d.details.slice(0, 50).forEach(function(row, idx){
                                    html += '<tr>' +
                                        '<td>' + (idx + 1) + '</td>' +
                                        '<td>' + (row.order_id || row.order_number || '') + '</td>' +
                                        '<td>' + (row.api_id || '') + '</td>' +
                                        '<td>' + (row.barcode || '') + '</td>' +
                                        '<td>' + (row.success ? 'Başarılı' : 'Hata') + '</td>' +
                                        '<td>' + (row.message || '') + '</td>' +
                                        '</tr>';
                                });
                                html += '</tbody></table>';
                                if (d.details.length > 50) {
                                    html += '<p><em><?php _e('İlk 50 satır gösterildi.', 'basit-kargo'); ?></em></p>';
                                }
                            }
                            $content.html(html);
                        } else {
                            $content.html('<div class="notice notice-error"><p>' + (response.data || '<?php _e('İçe aktarma başarısız.', 'basit-kargo'); ?>') + '</p></div>');
                        }
                        $results.show();
                    },
                    error: function(){
                        $results.show();
                        $content.html('<div class="notice notice-error"><p><?php _e('İçe aktarma sırasında bir hata oluştu.', 'basit-kargo'); ?></p></div>');
                    },
                    complete: function(){
                        $btn.prop('disabled', false).text('<?php _e('CSV Yükle ve Senkronize Et', 'basit-kargo'); ?>');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Handle sync order by number AJAX request
     */
    public function handleSyncOrderByNumber() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_number = isset($_POST['order_number']) ? sanitize_text_field($_POST['order_number']) : '';
        
        if (empty($order_number)) {
            wp_send_json_error('Sipariş numarası gerekli');
        }
        
        $api = new \BasitKargo\API();
        $potential_orders = $api->findOrdersByNumber(array($order_number));
        
        if (empty($potential_orders)) {
            wp_send_json_error('Sipariş bulunamadı veya zaten Basit Kargo bilgileri mevcut');
        }
        
        $synced_count = 0;
        $errors = array();
        
        foreach ($potential_orders as $potential_order) {
            $order = $potential_order['order'];
            $barcode = $potential_order['barcode'];
            $api_id = $potential_order['api_id'];
            $search_type = $potential_order['search_type'];
            $order_number = $potential_order['order_number'];
            
            // Try to fetch updated data from Basit Kargo
            $sync_result = $api->syncOrderFromBasitKargo($order, $barcode, $api_id, $search_type, $order_number);
            
            if ($sync_result['success']) {
                $synced_count++;
            } else {
                $errors[] = 'Sipariş ' . $order->get_id() . ': ' . $sync_result['message'];
            }
        }
        
        if ($synced_count > 0) {
            $message = sprintf(
                __('Sipariş %s senkronize edildi.', 'basit-kargo'),
                $order_number
            );
            
            if (!empty($errors)) {
                $message .= ' Hatalar: ' . implode(', ', $errors);
            }
            
            wp_send_json_success($message);
        } else {
            wp_send_json_error('Sipariş Basit Kargo\'da bulunamadı: ' . implode(', ', $errors));
        }
    }
    
    /**
     * Handle sync order by customer name AJAX request
     */
    public function handleSyncOrderByName() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $customer_name = isset($_POST['customer_name']) ? sanitize_text_field($_POST['customer_name']) : '';
        
        if (empty($customer_name)) {
            wp_send_json_error('Müşteri adı gerekli');
        }
        
        $api = new \BasitKargo\API();
        $potential_orders = $api->findOrdersByName($customer_name);
        
        if (empty($potential_orders)) {
            wp_send_json_error('Bu isimde müşteri bulunamadı veya zaten Basit Kargo bilgileri mevcut');
        }
        
        $synced_count = 0;
        $errors = array();
        $results = array();
        
        foreach ($potential_orders as $potential_order) {
            $order = $potential_order['order'];
            $barcode = $potential_order['barcode'];
            $api_id = $potential_order['api_id'];
            $search_type = $potential_order['search_type'];
            $customer_name = $potential_order['customer_name'];
            $match_score = $potential_order['match_score'];
            
            // Try to fetch updated data from Basit Kargo
            $sync_result = $api->syncOrderFromBasitKargo($order, $barcode, $api_id, $search_type, null, $customer_name);
            
            $result = array(
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'match_score' => $match_score,
                'success' => $sync_result['success'],
                'message' => $sync_result['message']
            );
            
            $results[] = $result;
            
            if ($sync_result['success']) {
                $synced_count++;
            } else {
                $errors[] = 'Sipariş ' . $order->get_id() . ': ' . $sync_result['message'];
            }
        }
        
        if ($synced_count > 0) {
            $message = sprintf(
                __('%d sipariş bulundu, %d sipariş senkronize edildi.', 'basit-kargo'),
                count($potential_orders),
                $synced_count
            );
            
            if (!empty($errors)) {
                $message .= ' Hatalar: ' . implode(', ', $errors);
            }
            
            wp_send_json_success(array(
                'message' => $message,
                'results' => $results,
                'synced_count' => $synced_count,
                'total_found' => count($potential_orders)
            ));
        } else {
            wp_send_json_error('Hiçbir sipariş Basit Kargo\'da bulunamadı: ' . implode(', ', $errors));
        }
    }
    
    /**
     * Handle bulk discover and sync AJAX request
     */
    public function handleBulkDiscoverSync() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        // Ensure BulkSync class is loaded
        if (!class_exists('BasitKargo\BulkSync')) {
            require_once BASIT_KARGO_PLUGIN_DIR . 'includes/class-bulk-sync.php';
        }
        
        $bulk_sync = new \BasitKargo\BulkSync();
        
        // Step 1: Discover orders from Basit Kargo
        $discover_result = $bulk_sync->discoverAllOrders();
        
        if (!$discover_result['success']) {
            wp_send_json_error('Basit Kargo siparişleri keşfedilemedi');
        }
        
        $discovered_orders = $discover_result['orders'];
        
        if (empty($discovered_orders)) {
            wp_send_json_error('Basit Kargo\'da hiç sipariş bulunamadı');
        }
        
        // Step 2: Sync discovered orders with WooCommerce
        $sync_result = $bulk_sync->syncDiscoveredOrders($discovered_orders);
        
        // Step 3: Get statistics
        $stats = $bulk_sync->getSyncStatistics();
        
        $message = sprintf(
            __('%d sipariş keşfedildi, %d sipariş eşleştirildi, %d sipariş senkronize edildi.', 'basit-kargo'),
            $discover_result['total_found'],
            $sync_result['matched_count'],
            $sync_result['synced_count']
        );
        
        if (!empty($sync_result['errors'])) {
            $message .= ' Hatalar: ' . implode(', ', $sync_result['errors']);
        }
        
        wp_send_json_success(array(
            'message' => $message,
            'discovered_count' => $discover_result['total_found'],
            'matched_count' => $sync_result['matched_count'],
            'synced_count' => $sync_result['synced_count'],
            'errors' => $sync_result['errors'],
            'statistics' => $stats,
            'discovered_orders' => $discovered_orders,
            'debug_info' => isset($discover_result['debug_info']) ? $discover_result['debug_info'] : null
        ));
    }
    
    /**
     * Handle CSV import for bulk mapping
     */
    public function handleImportCsv() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Yetkiniz yok', 'basit-kargo'));
        }

        if (!isset($_FILES['csv_file']) || empty($_FILES['csv_file']['name'])) {
            wp_send_json_error(__('CSV dosyası gerekli', 'basit-kargo'));
        }

        $file = $_FILES['csv_file'];
        $overrides = array('test_form' => false, 'mimes' => array('csv' => 'text/csv', 'txt' => 'text/plain'));
        $upload = wp_handle_upload($file, $overrides);
        if (!isset($upload['file'])) {
            wp_send_json_error(__('Dosya yüklenemedi', 'basit-kargo'));
        }

        $path = $upload['file'];
        $handle = fopen($path, 'r');
        if (!$handle) {
            wp_send_json_error(__('Dosya açılamadı', 'basit-kargo'));
        }

        $header = fgetcsv($handle, 0, ',');
        if ($header === false) {
            fclose($handle);
            wp_send_json_error(__('Boş veya geçersiz CSV', 'basit-kargo'));
        }

        // Normalize headers (supports Turkish and spaces)
        $normalize = function($str) {
            $str = strtolower(trim($str));
            $str = strtr($str, array(
                'ç'=>'c','ğ'=>'g','ı'=>'i','ö'=>'o','ş'=>'s','ü'=>'u',
                'Ç'=>'c','Ğ'=>'g','İ'=>'i','Ö'=>'o','Ş'=>'s','Ü'=>'u',
                '/'=>' ', '\\'=>' ', '-'=>' '
            ));
            $str = preg_replace('/\s+/', '_', $str);
            $str = preg_replace('/[^a-z0-9_]/', '', $str);
            return $str;
        };

        $map = array();
        foreach ($header as $i => $h) {
            $map[$normalize($h)] = $i;
        }

        // Expected columns (map both our template and Basit Kargo export)
        $col_order_id = $map['order_id'] ?? ($map['id_wc'] ?? null);
        $col_order_number = $map['order_number'] ?? ($map['siparis_no'] ?? null);
        $col_api_id = $map['basit_kargo_id'] ?? ($map['api_id'] ?? ($map['id'] ?? null)); // BK export: first col is ID
        $col_barcode = $map['barcode'] ?? ($map['barkod'] ?? ($map['kargo_kodu'] ?? null));
        $col_recipient_name = $map['alici_adi'] ?? null;
        $col_recipient_phone = $map['alici_telefon'] ?? null;
        $col_date = $map['tarih'] ?? null;

        $details = array();
        $processed = 0; $updated = 0; $synced = 0; $errors = 0;

        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $processed++;
            $get = function($col) use ($row) { return ($col !== null && isset($row[$col])) ? trim($row[$col]) : ''; };
            $order_id_val = $get($col_order_id);
            $order_number_val = $get($col_order_number);
            $api_id_val = $get($col_api_id);
            $barcode_val = $get($col_barcode);
            $recipient_name_val = $get($col_recipient_name);
            $recipient_phone_val = preg_replace('/\D+/', '', $get($col_recipient_phone));
            $date_val = $get($col_date);

            $row_result = array(
                'order_id' => $order_id_val,
                'order_number' => $order_number_val,
                'api_id' => $api_id_val,
                'barcode' => $barcode_val,
                'success' => false,
                'message' => ''
            );

            // Find order
            $order = null;
            if ($order_id_val !== '' && ctype_digit($order_id_val)) {
                $order = wc_get_order(intval($order_id_val));
            }
            if (!$order && $order_number_val !== '') {
                // Try direct by ID if numeric
                if (ctype_digit($order_number_val)) {
                    $order = wc_get_order(intval($order_number_val));
                }
                // Fallback: meta _order_number
                if (!$order) {
                    $orders = wc_get_orders(array(
                        'limit' => 1,
                        'meta_key' => '_order_number',
                        'meta_value' => $order_number_val
                    ));
                    if (!empty($orders)) {
                        $order = $orders[0];
                    }
                }
            }

            // Fallback: match by recipient phone (exact) and name/date proximity if available
            if (!$order && $recipient_phone_val !== '') {
                $orders = wc_get_orders(array(
                    'type' => 'shop_order',
                    'limit' => 10,
                    'meta_key' => '_billing_phone',
                    'meta_value' => $recipient_phone_val
                ));
                if (!empty($orders)) {
                    $best = null; $bestScore = -1;
                    foreach ($orders as $o) {
                        // Skip non-order/refund objects safely
                        if (!is_object($o) || !is_callable(array($o, 'get_billing_first_name'))) { continue; }
                        $score = 0;
                        if ($recipient_name_val !== '') {
                            $n1 = $normalize($recipient_name_val);
                            $n2 = $normalize($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
                            if ($n1 === $n2) { $score += 50; }
                            else {
                                $parts = explode('_', $n1);
                                if (!empty($parts[0]) && strpos($n2, $parts[0]) !== false) { $score += 20; }
                            }
                        }
                        if ($date_val !== '') {
                            $od = $o->get_date_created();
                            if ($od) {
                                $odate = strtotime($od->date('Y-m-d H:i:s'));
                                $rdate = strtotime($date_val);
                                if ($rdate) {
                                    $diff = abs($odate - $rdate);
                                    if ($diff <= 24*3600) $score += 20; else if ($diff <= 7*24*3600) $score += 10;
                                }
                            }
                        }
                        if ($score > $bestScore) { $bestScore = $score; $best = $o; }
                    }
                    if ($best) { $order = $best; }
                }
            }

            // Fallback: match by recipient name only (if still not found)
            if (!$order && $recipient_name_val !== '') {
                $orders = wc_get_orders(array(
                    'type' => 'shop_order',
                    'limit' => 200,
                    'orderby' => 'date',
                    'order' => 'DESC'
                ));
                if (!empty($orders)) {
                    $best = null; $bestScore = -1;
                    $n1 = $normalize($recipient_name_val);
                    foreach ($orders as $o) {
                        if (!is_object($o) || !is_callable(array($o, 'get_billing_first_name'))) { continue; }
                        $n2 = $normalize($o->get_billing_first_name() . ' ' . $o->get_billing_last_name());
                        $score = 0;
                        if ($n1 === $n2) {
                            $score += 70;
                        } else {
                            $len1 = strlen($n1); $len2 = strlen($n2);
                            $maxLen = max($len1, $len2);
                            if ($maxLen > 0) {
                                $distance = levenshtein($n1, $n2);
                                $sim = 1 - ($distance / $maxLen);
                                $score += (int) round($sim * 50);
                            }
                            // simple partial bonus
                            $parts = explode('_', $n1);
                            if (!empty($parts[0]) && strpos($n2, $parts[0]) !== false) { $score += 10; }
                        }
                        if ($date_val !== '') {
                            $od = $o->get_date_created();
                            if ($od) {
                                $odate = strtotime($od->date('Y-m-d H:i:s'));
                                $rdate = strtotime($date_val);
                                if ($rdate) {
                                    $diff = abs($odate - $rdate);
                                    if ($diff <= 24*3600) $score += 10; else if ($diff <= 7*24*3600) $score += 5;
                                }
                            }
                        }
                        if ($score > $bestScore) { $bestScore = $score; $best = $o; }
                    }
                    // Accept only reasonable matches
                    if ($best && $bestScore >= 40) { $order = $best; }
                }
            }

            if (!$order) {
                $errors++;
                $row_result['message'] = __('Sipariş bulunamadı', 'basit-kargo');
                $details[] = $row_result;
                continue;
            }

            if ($api_id_val !== '') {
                $order->update_meta_data('basit_kargo_api_id', $api_id_val);
            }
            if ($barcode_val !== '') {
                $order->update_meta_data('basit_kargo_barcode', $barcode_val);
            }
            // Save mapped data and any optional columns
            $order->save();
            $updated++;

            // Try to fetch & sync details
            $api = new \BasitKargo\API();
            $res = $api->fetchBarcodeData($order);
            if ($res['success']) {
                $synced++;
                $row_result['success'] = true;
                $row_result['message'] = __('Senkronize edildi', 'basit-kargo');
            } else {
                $row_result['success'] = false;
                $row_result['message'] = $res['message'];
            }
            $details[] = $row_result;
        }
        fclose($handle);

        $message = sprintf(__('İşlenen: %d, Güncellenen: %d, Senkronize: %d, Hata: %d', 'basit-kargo'), $processed, $updated, $synced, $errors);
        wp_send_json_success(array(
            'message' => $message,
            'processed' => $processed,
            'updated' => $updated,
            'synced' => $synced,
            'errors' => $errors,
            'details' => $details
        ));
    }

    /**
     * Receive client-side error logs from admin UI
     */
    public function handleLogClientError() {
        // Accept both nonce in POST body or _ajax_nonce
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_POST['_ajax_nonce']) ? $_POST['_ajax_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'basit_kargo_nonce')) {
            wp_send_json_error('Geçersiz nonce');
        }

        // Normalize entries
        $entries = array();
        if (isset($_POST['entries'])) {
            $decoded = json_decode(stripslashes((string) $_POST['entries']), true);
            if (is_array($decoded)) { $entries = $decoded; }
        } else {
            $entries[] = array(
                'message' => isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '',
                'source' => isset($_POST['source']) ? esc_url_raw(wp_unslash($_POST['source'])) : '',
                'lineno' => isset($_POST['lineno']) ? intval($_POST['lineno']) : 0,
                'colno' => isset($_POST['colno']) ? intval($_POST['colno']) : 0,
                'stack' => isset($_POST['stack']) ? sanitize_textarea_field(wp_unslash($_POST['stack'])) : '',
                'url' => isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '',
                'userAgent' => isset($_POST['userAgent']) ? sanitize_text_field(wp_unslash($_POST['userAgent'])) : '',
                'time' => current_time('mysql')
            );
        }

        // Persist last 100 entries in option for quick inspection
        $opt_key = 'basit_kargo_client_errors';
        $stored = get_option($opt_key, array());
        if (!is_array($stored)) { $stored = array(); }
        foreach ($entries as $e) {
            $e['time'] = isset($e['time']) ? $e['time'] : current_time('mysql');
            $stored[] = array(
                'message' => isset($e['message']) ? sanitize_text_field($e['message']) : '',
                'source' => isset($e['source']) ? esc_url_raw($e['source']) : '',
                'lineno' => isset($e['lineno']) ? intval($e['lineno']) : 0,
                'colno' => isset($e['colno']) ? intval($e['colno']) : 0,
                'stack' => isset($e['stack']) ? sanitize_textarea_field($e['stack']) : '',
                'url' => isset($e['url']) ? esc_url_raw($e['url']) : '',
                'userAgent' => isset($e['userAgent']) ? sanitize_text_field($e['userAgent']) : '',
                'time' => sanitize_text_field($e['time'])
            );
        }
        // Trim to last 100
        if (count($stored) > 100) { $stored = array_slice($stored, -100); }
        update_option($opt_key, $stored, false);

        wp_send_json_success('Logged');
    }

    /**
     * Ensure BK order id/reference exists and return tracking URL for the UI button.
     */
    public function handleOpenTracking() {
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_POST['_ajax_nonce']) ? $_POST['_ajax_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'basit_kargo_nonce')) {
            wp_send_json_error('Geçersiz nonce');
        }
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if (!$order_id) { wp_send_json_error('Geçersiz sipariş ID'); }
        $order = wc_get_order($order_id);
        if (!$order) { wp_send_json_error('Sipariş bulunamadı'); }

        $api_id = $order->get_meta('basit_kargo_api_id');
        $reference = $order->get_meta('basit_kargo_reference');

        if (empty($api_id) && empty($reference)) {
            // Try to fetch from Basit Kargo now
            $api = new \BasitKargo\API();
            $res = $api->fetchBarcodeData($order);
            if ($res && !empty($res['success'])) {
                $api_id = $order->get_meta('basit_kargo_api_id');
                $reference = $order->get_meta('basit_kargo_reference');
            }
        }

        $slug = !empty($api_id) ? $api_id : (!empty($reference) ? $reference : '');
        if (empty($slug)) {
            wp_send_json_error('Takip verisi bulunamadı');
        }
        wp_send_json_success(array('url' => 'https://basitkargo.com/takip/' . $slug));
    }



}
