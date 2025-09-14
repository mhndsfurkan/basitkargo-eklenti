<?php
/**
 * Basit Kargo UI Class
 * Handles user interface elements and styling
 */

namespace BasitKargo;

if (!defined('ABSPATH')) {
    exit;
}

class UI {
    
    public function __construct() {
        $this->initHooks();
    }
    
    /**
     * Render independent manual shipment metabox
     */
    public function renderManualMetabox($post) {
        $order = wc_get_order($post->ID);
        if (!$order) { echo '<p>' . __('Sipariş bulunamadı', 'basit-kargo') . '</p>'; return; }
        $handler_name = $order->get_meta('basit_kargo_handler_name');
        $handler_shipment_code = $order->get_meta('basit_kargo_handler_shipment_code');
        echo '<div class="basit-kargo-metabox" style="padding:10px;">';
        echo '<div id="bk-manual-form" style="margin:0;">';
        $manual_enabled = $order->get_meta('basit_kargo_manual_enabled') === 'yes';
        echo '<p style="margin-bottom:6px;">
                <label>
                    <input type="checkbox" id="bk-manual-enabled" name="manual_enabled" value="1" ' . checked($manual_enabled, true, false) . ' />
                    <strong>' . __('Manuel kargo bilgilerini kullan', 'basit-kargo') . '</strong>
                </label>
            </p>';
        echo '<p style="margin-bottom:6px;"><label><strong>' . __('Kargo Firması', 'basit-kargo') . '</strong></label><br/>';
        $carrier_options = array('Surat Kargo','Yurtiçi Kargo','Aras Kargo','MNG Kargo','PTT Kargo','UPS Kargo','DHL','FedEx');
        echo '<select name="handler" id="bk-manual-handler" class="widefat" ' . ($manual_enabled ? '' : 'disabled') . '>';
        echo '<option value="">' . __('Seçiniz', 'basit-kargo') . '</option>';
        foreach ($carrier_options as $opt) {
            $sel = ($handler_name === $opt) ? ' selected' : '';
            echo '<option value="' . esc_attr($opt) . '"' . $sel . '>' . esc_html($opt) . '</option>';
        }
        echo '</select></p>';
        echo '<p style="margin-bottom:6px;"><label><strong>' . __('Kargo Takip Kodu', 'basit-kargo') . '</strong></label><br/>';
        echo '<input type="text" name="tracking" id="bk-manual-tracking" value="' . esc_attr($handler_shipment_code) . '" class="widefat" placeholder="15818078231488" ' . ($manual_enabled ? '' : 'disabled') . ' /></p>';
        echo '<p><button type="button" id="bk-manual-save-btn" class="button button-primary">' . __('Manuel Kaydet', 'basit-kargo') . '</button></p>';
        echo '</div>';
        echo '</div>';
        
        // Add JavaScript for form handling
        echo '<script>
        jQuery(document).ready(function($) {
            // Show success message if redirected with success parameter
            if (window.location.search.indexOf("bk_success=1") !== -1) {
                alert("Manuel kargo bilgileri başarıyla kaydedildi!");
            }
            
            $("#bk-manual-save-btn").on("click", function(e) {
                e.preventDefault();
                var handler = $("#bk-manual-handler").val();
                var tracking = $("#bk-manual-tracking").val();
                var btn = $("#bk-manual-save-btn");
                var manualEnabled = $("#bk-manual-enabled").is(":checked") ? 1 : 0;
                
                if (!handler) {
                    alert("Lütfen kargo firmasını seçin.");
                    return false;
                }
                
                btn.prop("disabled", true).val("Kaydediliyor...");
                
                $.ajax({
                    url: ajaxurl,
                    type: "POST",
                    data: {
                        action: "basit_kargo_save_manual_map",
                        order_id: ' . (int) $order->get_id() . ',
                        handler: handler,
                        tracking: tracking,
                        manual_enabled: manualEnabled,
                        nonce: "' . esc_js(wp_create_nonce('basit_kargo_nonce')) . '"
                    },
                    success: function(response) {
                        if (response.success) {
                            alert("Kaydedildi!");
                            location.reload();
                        } else {
                            alert("Hata: " + (response.data || "Bilinmeyen hata"));
                        }
                    },
                    error: function() {
                        alert("AJAX hatası. Lütfen tekrar deneyin.");
                    },
                    complete: function() {
                        btn.prop("disabled", false).val("Manuel Kaydet");
                    }
                });
            });
        });
        </script>';
    }
    
    private function initHooks() {
        // Admin UI hooks
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminAssets'));
        // Aggressively block problematic third-party banners that break admin JS
        add_action('admin_enqueue_scripts', array($this, 'blockProblematicScripts'), 999);
        add_action('admin_print_scripts', array($this, 'blockProblematicScripts'), 999);
        add_action('wp_print_scripts', array($this, 'blockProblematicScripts'), 999);
        add_action('admin_head', array($this, 'addAdminStyles'));
        add_action('admin_footer', array($this, 'addAdminScripts'));
        
        // Order metabox - Only in metabox, not inline
        add_action('add_meta_boxes', array($this, 'addOrderMetabox'));
        add_action('add_meta_boxes_shop_order', array($this, 'addOrderMetabox'));
        // Manual shipment metabox (independent from Basit Kargo)
        add_action('add_meta_boxes', array($this, 'addManualMetabox'));
        add_action('add_meta_boxes_shop_order', array($this, 'addManualMetabox'));
        add_action('admin_init', array($this, 'forceAddMetabox'));
        
        // Order actions
        add_filter('woocommerce_admin_order_actions', array($this, 'addOrderActions'), 10, 2);
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueueAdminAssets() {
        $screen = get_current_screen();
        
        // Load on all admin pages for now to ensure functionality
        if (is_admin()) {
            $css_ver = file_exists(BASIT_KARGO_PLUGIN_DIR . 'assets/css/admin.css') ? filemtime(BASIT_KARGO_PLUGIN_DIR . 'assets/css/admin.css') : BASIT_KARGO_VERSION;
            $js_ver  = file_exists(BASIT_KARGO_PLUGIN_DIR . 'assets/js/admin.js') ? filemtime(BASIT_KARGO_PLUGIN_DIR . 'assets/js/admin.js') : BASIT_KARGO_VERSION;
            wp_enqueue_style(
                'basit-kargo-admin',
                BASIT_KARGO_PLUGIN_URL . 'assets/css/admin.css',
                array(),
                $css_ver
            );
            
            wp_enqueue_script(
                'basit-kargo-admin',
                BASIT_KARGO_PLUGIN_URL . 'assets/js/admin.js',
                array('jquery'),
                $js_ver,
                true
            );
            
            wp_localize_script('basit-kargo-admin', 'basitKargo', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('basit_kargo_nonce'),
                'strings' => array(
                    'loading' => __('Yükleniyor...', 'basit-kargo'),
                    'success' => __('Başarılı!', 'basit-kargo'),
                    'error' => __('Hata!', 'basit-kargo'),
                    'confirm' => __('Emin misiniz?', 'basit-kargo')
                )
            ));

            // Workaround: some third-party admin banner scripts throw parse errors and break inline JS.
            // Attempt to dequeue/deregister known problematic handle if present.
            if (wp_script_is('woo-refund-and-exchange-lite-banner', 'registered')) {
                wp_dequeue_script('woo-refund-and-exchange-lite-banner');
                wp_deregister_script('woo-refund-and-exchange-lite-banner');
            }
        }
    }

    /**
     * Block known problematic admin scripts that throw parse errors
     */
    public function blockProblematicScripts() {
        // Specific known handle
        if (wp_script_is('woo-refund-and-exchange-lite-banner', 'enqueued') || wp_script_is('woo-refund-and-exchange-lite-banner', 'registered')) {
            wp_dequeue_script('woo-refund-and-exchange-lite-banner');
            wp_deregister_script('woo-refund-and-exchange-lite-banner');
        }
        // Fuzzy match any handle/src that contains the pattern
        global $wp_scripts;
        if (isset($wp_scripts) && is_object($wp_scripts)) {
            // Ensure registered data is populated
            $registered = isset($wp_scripts->registered) ? $wp_scripts->registered : array();
            $queue = isset($wp_scripts->queue) ? $wp_scripts->queue : array();
            foreach ($queue as $handle) {
                $src = '';
                if (isset($registered[$handle]) && isset($registered[$handle]->src)) {
                    $src = (string) $registered[$handle]->src;
                }
                $name = (string) $handle;
                if (strpos($name, 'woo-refund-and-exchange-lite-banner') !== false ||
                    strpos($src, 'woo-refund-and-exchange-lite-banner') !== false ||
                    strpos($name, 'refund-and-exchange') !== false ||
                    strpos($src, 'refund-and-exchange') !== false) {
                    wp_dequeue_script($handle);
                    wp_deregister_script($handle);
                }
            }
        }
    }
    
    /**
     * Add admin styles
     */
    public function addAdminStyles() {
        $screen = get_current_screen();
        
        if (strpos($screen->id, 'woocommerce') !== false || strpos($screen->id, 'basit-kargo') !== false) {
            echo '<style>
                /* Basit Kargo Admin Styles */
                .basit-kargo-metabox {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-radius: 4px;
                    padding: 15px;
                    margin: 10px 0;
                }
                
                .basit-kargo-info-box {
                    background: #e7f3ff;
                    border: 1px solid #0073aa;
                    border-radius: 4px;
                    padding: 10px;
                    margin: 10px 0;
                }
                
                .basit-kargo-warning-box {
                    background: #fff3cd;
                    border: 1px solid #ffc107;
                    border-radius: 4px;
                    padding: 10px;
                    margin: 10px 0;
                }
                
                .basit-kargo-error-box {
                    background: #f8d7da;
                    border: 1px solid #dc3545;
                    border-radius: 4px;
                    padding: 10px;
                    margin: 10px 0;
                }
                
                .basit-kargo-button {
                    display: inline-block;
                    background: #0073aa;
                    color: #fff;
                    padding: 8px 16px;
                    text-decoration: none;
                    border-radius: 3px;
                    border: none;
                    cursor: pointer;
                    font-size: 13px;
                    margin: 2px;
                }
                
                .basit-kargo-button:hover {
                    background: #005177;
                    color: #fff;
                }
                
                .basit-kargo-button.success {
                    background: #28a745;
                }
                
                .basit-kargo-button.success:hover {
                    background: #218838;
                }
                
                .basit-kargo-button.warning {
                    background: #ffc107;
                    color: #000;
                }
                
                .basit-kargo-button.warning:hover {
                    background: #e0a800;
                }
                
                .basit-kargo-button.danger {
                    background: #dc3545;
                }
                
                .basit-kargo-button.danger:hover {
                    background: #c82333;
                }
                
                .basit-kargo-button.loading {
                    opacity: 0.6;
                    cursor: wait;
                }
                
                .basit-kargo-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 10px 0;
                }
                
                .basit-kargo-table th,
                .basit-kargo-table td {
                    padding: 8px;
                    text-align: left;
                    border-bottom: 1px solid #ddd;
                }
                
                .basit-kargo-table th {
                    background-color: #f8f9fa;
                    font-weight: bold;
                }
                
                /* WooCommerce Action Button Override - ULTRA AGGRESSIVE */
                .button.wc-action-button.basit_kargo_manual_trigger,
                .button.wc-action-button.basit_kargo_force_create_barcode,
                .button.wc-action-button.basit_kargo_send_mail,
                .button.wc-action-button.basit_kargo_print_barcode,
                .button.wc-action-button.basit_kargo_print_pdf,
                .button.wc-action-button.basit_kargo_barcode_sync,
                .button.wc-action-button.basit_kargo_manual_tracking_sync,
                a.button.wc-action-button.basit_kargo_manual_trigger,
                a.button.wc-action-button.basit_kargo_force_create_barcode,
                a.button.wc-action-button.basit_kargo_send_mail,
                a.button.wc-action-button.basit_kargo_print_barcode,
                a.button.wc-action-button.basit_kargo_print_pdf,
                a.button.wc-action-button.basit_kargo_barcode_sync,
                a.button.wc-action-button.basit_kargo_manual_tracking_sync,
                .wc-action-button.basit_kargo_manual_trigger,
                .wc-action-button.basit_kargo_force_create_barcode,
                .wc-action-button.basit_kargo_send_mail,
                .wc-action-button.basit_kargo_print_barcode,
                .wc-action-button.basit_kargo_print_pdf,
                .wc-action-button.basit_kargo_barcode_sync,
                .wc-action-button.basit_kargo_manual_tracking_sync {
                    color: #fff !important;
                    background-color: #0073aa !important;
                    border: 1px solid #0073aa !important;
                    font-size: 11px !important;
                    padding: 4px 8px !important;
                    margin: 2px !important;
                    text-decoration: none !important;
                    border-radius: 3px !important;
                    display: inline-block !important;
                    text-indent: 0 !important;
                    overflow: visible !important;
                    white-space: nowrap !important;
                    background-image: none !important;
                    background-size: 0 !important;
                    min-width: auto !important;
                    width: auto !important;
                    height: auto !important;
                    line-height: 1.3 !important;
                    position: relative !important;
                    text-overflow: unset !important;
                    font-family: inherit !important;
                    text-transform: none !important;
                    letter-spacing: normal !important;
                    word-spacing: normal !important;
                    text-shadow: none !important;
                    box-shadow: none !important;
                    outline: none !important;
                    cursor: pointer !important;
                }
                
                .button.wc-action-button.basit_kargo_manual_trigger:hover,
                .button.wc-action-button.basit_kargo_force_create_barcode:hover,
                .button.wc-action-button.basit_kargo_send_mail:hover,
                .button.wc-action-button.basit_kargo_print_barcode:hover,
                .button.wc-action-button.basit_kargo_print_pdf:hover,
                .button.wc-action-button.basit_kargo_barcode_sync:hover,
                .button.wc-action-button.basit_kargo_manual_tracking_sync:hover,
                a.button.wc-action-button.basit_kargo_manual_trigger:hover,
                a.button.wc-action-button.basit_kargo_force_create_barcode:hover,
                a.button.wc-action-button.basit_kargo_send_mail:hover,
                a.button.wc-action-button.basit_kargo_print_barcode:hover,
                a.button.wc-action-button.basit_kargo_print_pdf:hover,
                a.button.wc-action-button.basit_kargo_barcode_sync:hover,
                a.button.wc-action-button.basit_kargo_manual_tracking_sync:hover,
                .wc-action-button.basit_kargo_manual_trigger:hover,
                .wc-action-button.basit_kargo_force_create_barcode:hover,
                .wc-action-button.basit_kargo_send_mail:hover,
                .wc-action-button.basit_kargo_print_barcode:hover,
                .wc-action-button.basit_kargo_print_pdf:hover,
                .wc-action-button.basit_kargo_barcode_sync:hover,
                .wc-action-button.basit_kargo_manual_tracking_sync:hover {
                    background-color: #005177 !important;
                    color: #fff !important;
                }
                
                .woocommerce-order-actions .button.basit_kargo_loading {
                    opacity: 0.6;
                    cursor: wait !important;
                }
                
                /* Remove ALL pseudo-elements that might hide text */
                .button.wc-action-button.basit_kargo_manual_trigger::before,
                .button.wc-action-button.basit_kargo_force_create_barcode::before,
                .button.wc-action-button.basit_kargo_manual_trigger::after,
                .button.wc-action-button.basit_kargo_force_create_barcode::after,
                .button.wc-action-button.basit_kargo_send_mail::before,
                .button.wc-action-button.basit_kargo_send_mail::after,
                .button.wc-action-button.basit_kargo_print_barcode::before,
                .button.wc-action-button.basit_kargo_print_barcode::after,
                .button.wc-action-button.basit_kargo_print_pdf::before,
                .button.wc-action-button.basit_kargo_print_pdf::after,
                .button.wc-action-button.basit_kargo_barcode_sync::before,
                .button.wc-action-button.basit_kargo_barcode_sync::after,
                .button.wc-action-button.basit_kargo_manual_tracking_sync::before,
                .button.wc-action-button.basit_kargo_manual_tracking_sync::after,
                a.button.wc-action-button.basit_kargo_manual_trigger::before,
                a.button.wc-action-button.basit_kargo_force_create_barcode::before,
                a.button.wc-action-button.basit_kargo_manual_trigger::after,
                a.button.wc-action-button.basit_kargo_force_create_barcode::after,
                a.button.wc-action-button.basit_kargo_send_mail::before,
                a.button.wc-action-button.basit_kargo_send_mail::after,
                a.button.wc-action-button.basit_kargo_print_barcode::before,
                a.button.wc-action-button.basit_kargo_print_barcode::after,
                a.button.wc-action-button.basit_kargo_print_pdf::before,
                a.button.wc-action-button.basit_kargo_print_pdf::after,
                a.button.wc-action-button.basit_kargo_barcode_sync::before,
                a.button.wc-action-button.basit_kargo_barcode_sync::after,
                a.button.wc-action-button.basit_kargo_manual_tracking_sync::before,
                a.button.wc-action-button.basit_kargo_manual_tracking_sync::after,
                .wc-action-button.basit_kargo_manual_trigger::before,
                .wc-action-button.basit_kargo_force_create_barcode::before,
                .wc-action-button.basit_kargo_manual_trigger::after,
                .wc-action-button.basit_kargo_force_create_barcode::after,
                .wc-action-button.basit_kargo_send_mail::before,
                .wc-action-button.basit_kargo_send_mail::after,
                .wc-action-button.basit_kargo_print_barcode::before,
                .wc-action-button.basit_kargo_print_barcode::after,
                .wc-action-button.basit_kargo_print_pdf::before,
                .wc-action-button.basit_kargo_print_pdf::after,
                .wc-action-button.basit_kargo_barcode_sync::before,
                .wc-action-button.basit_kargo_barcode_sync::after,
                .wc-action-button.basit_kargo_manual_tracking_sync::before,
                .wc-action-button.basit_kargo_manual_tracking_sync::after {
                    display: none !important;
                    content: none !important;
                }
                
                /* Custom button class for our buttons */
                .basit-kargo-custom-button {
                    color: #fff !important;
                    background-color: #0073aa !important;
                    border: 1px solid #0073aa !important;
                    font-size: 11px !important;
                    padding: 4px 8px !important;
                    margin: 2px !important;
                    text-decoration: none !important;
                    border-radius: 3px !important;
                    display: inline-block !important;
                    text-indent: 0 !important;
                    overflow: visible !important;
                    white-space: nowrap !important;
                    background-image: none !important;
                    background-size: 0 !important;
                    min-width: auto !important;
                    width: auto !important;
                    height: auto !important;
                    line-height: 1.3 !important;
                    position: relative !important;
                    text-overflow: unset !important;
                    font-family: inherit !important;
                    text-transform: none !important;
                    letter-spacing: normal !important;
                    word-spacing: normal !important;
                    text-shadow: none !important;
                    box-shadow: none !important;
                    outline: none !important;
                    cursor: pointer !important;
                }
                
                .basit-kargo-custom-button:hover {
                    background-color: #005177 !important;
                    color: #fff !important;
                }
                
                .basit-kargo-custom-button::before,
                .basit-kargo-custom-button::after {
                    display: none !important;
                    content: none !important;
                }
            </style>';
        }
    }
    
    /**
     * Add admin scripts
     */
    public function addAdminScripts() {
        $screen = get_current_screen();
        
        if (strpos($screen->id, 'woocommerce') !== false || strpos($screen->id, 'basit-kargo') !== false) {
            echo '<script>
                jQuery(document).ready(function($) {
                    // Force button text visibility - AGGRESSIVE
                    function forceButtonTextVisibility() {
                        $(".button.wc-action-button.basit_kargo_manual_trigger, " +
                          ".button.wc-action-button.basit_kargo_force_create_barcode, " +
                          ".button.wc-action-button.basit_kargo_send_mail, " +
                          ".button.wc-action-button.basit_kargo_print_barcode, " +
                          ".button.wc-action-button.basit_kargo_print_pdf, " +
                          ".button.wc-action-button.basit_kargo_barcode_sync, " +
                          ".button.wc-action-button.basit_kargo_manual_tracking_sync, " +
                          "a.button.wc-action-button.basit_kargo_manual_trigger, " +
                          "a.button.wc-action-button.basit_kargo_force_create_barcode, " +
                          "a.button.wc-action-button.basit_kargo_send_mail, " +
                          "a.button.wc-action-button.basit_kargo_print_barcode, " +
                          "a.button.wc-action-button.basit_kargo_print_pdf, " +
                          "a.button.wc-action-button.basit_kargo_barcode_sync, " +
                          "a.button.wc-action-button.basit_kargo_manual_tracking_sync, " +
                          ".wc-action-button.basit_kargo_manual_trigger, " +
                          ".wc-action-button.basit_kargo_force_create_barcode, " +
                          ".wc-action-button.basit_kargo_send_mail, " +
                          ".wc-action-button.basit_kargo_print_barcode, " +
                          ".wc-action-button.basit_kargo_print_pdf, " +
                          ".wc-action-button.basit_kargo_barcode_sync, " +
                          ".wc-action-button.basit_kargo_manual_tracking_sync, " +
                          ".basit-kargo-custom-button").each(function() {
                            var $btn = $(this);
                            $btn.css({
                                "text-indent": "0",
                                "font-size": "11px",
                                "padding": "4px 8px",
                                "min-width": "auto",
                                "width": "auto",
                                "height": "auto",
                                "line-height": "1.3",
                                "display": "inline-block",
                                "overflow": "visible",
                                "text-overflow": "unset",
                                "white-space": "nowrap",
                                "position": "relative",
                                "background-size": "0",
                                "background-image": "none",
                                "background-color": "#0073aa",
                                "border": "1px solid #0073aa",
                                "color": "#fff",
                                "text-decoration": "none",
                                "border-radius": "3px",
                                "cursor": "pointer",
                                "margin": "2px"
                            });
                        });
                    }
                    
                    // Run immediately
                    forceButtonTextVisibility();
                    
                    // Run when new content is loaded
                    $(document).on("DOMNodeInserted", function() {
                        setTimeout(forceButtonTextVisibility, 100);
                    });
                    
                    // Run periodically
                    setInterval(forceButtonTextVisibility, 1000);
                    
                    // Inline click handler intentionally disabled; external admin.js handles actions and redirects reliably.
                    window.BK_INLINE_HANDLER_DISABLED = true;
                });
            </script>';
        }
    }
    
    /**
     * Force add metabox
     */
    public function forceAddMetabox() {
        global $pagenow;
        // Force metaboxes on both classic and HPOS order edit pages
        if (($pagenow === 'post.php' && isset($_GET['post']) && get_post_type($_GET['post']) === 'shop_order') ||
            ($pagenow === 'admin.php' && isset($_GET['page']) && $_GET['page'] === 'wc-orders' && isset($_GET['action']) && $_GET['action'] === 'edit')) {
            add_action('add_meta_boxes', array($this, 'addOrderMetabox'), 1);
            add_action('add_meta_boxes', array($this, 'addManualMetabox'), 1);
        }
    }
    
    /**
     * Add order metabox
     */
    public function addOrderMetabox() {
        $screens = array('shop_order', 'woocommerce_page_wc-orders');
        
        foreach ($screens as $screen) {
            add_meta_box(
                'basit-kargo-order-metabox',
                __('Basit Kargo', 'basit-kargo'),
                array($this, 'renderOrderMetabox'),
                $screen,
                'side',
                'high'
            );
        }
    }

    /**
     * Add independent manual shipment metabox
     */
    public function addManualMetabox() {
        $screens = array('shop_order', 'woocommerce_page_wc-orders');
        foreach ($screens as $screen) {
            add_meta_box(
                'basit-kargo-manual-shipment',
                __('Manuel Kargo Bilgisi', 'basit-kargo'),
                array($this, 'renderManualMetabox'),
                $screen,
                'side',
                'default'
            );
        }
    }
    

    
    /**
     * Render order metabox
     */
    public function renderOrderMetabox($post) {
        $order = wc_get_order($post->ID);
        
        if (!$order) {
            echo '<p>' . __('Sipariş bulunamadı', 'basit-kargo') . '</p>';
            return;
        }
        
        $barcode = $order->get_meta('basit_kargo_barcode');
        $api_id = $order->get_meta('basit_kargo_api_id');
        $handler_code = $order->get_meta('basit_kargo_handler_code');
        $tracking_info = $order->get_meta('basit_kargo_tracking_info');
        $reference = $order->get_meta('basit_kargo_reference');
        $status = $order->get_meta('basit_kargo_status');
        $created_time = $order->get_meta('basit_kargo_created_time');
        $updated_time = $order->get_meta('basit_kargo_updated_time');
        $shipment_fee = $order->get_meta('basit_kargo_shipment_fee');
        $total_cost = $order->get_meta('basit_kargo_total_cost');
        $handler_name = $order->get_meta('basit_kargo_handler_name');
        $handler_shipment_code = $order->get_meta('basit_kargo_handler_shipment_code');
        $handler_tracking_link = $order->get_meta('basit_kargo_handler_tracking_link');
        $shipped_time = $order->get_meta('basit_kargo_shipped_time');
        $delivered_time = $order->get_meta('basit_kargo_delivered_time');
        
        echo '<div class="basit-kargo-metabox" style="padding: 10px;">';
        
        if ($barcode) {
            // Status indicator
            $status_info = $this->getStatusInfo($status, $delivered_time, $shipped_time);
            
            echo '<div style="display: flex; align-items: center; margin-bottom: 10px; padding: 8px; border-radius: 5px; ' . $status_info['style'] . '">';
            echo '<span style="font-size: 16px; margin-right: 8px;">' . $status_info['icon'] . '</span>';
            echo '<strong>' . $status_info['text'] . '</strong>';
            if ($handler_name) {
                echo '<span style="margin-left: 10px; font-size: 12px; opacity: 0.8;">(' . $handler_name . ')</span>';
            }
            echo '</div>';
            
            // Compact info
            echo '<div style="font-size: 11px; margin-bottom: 10px;">';
            echo '<strong>Barkod:</strong> ' . $barcode;
            $carrier_label = $handler_name ? $handler_name : ($handler_code ? $handler_code : '');
            if ($carrier_label) {
                echo ' | <strong>Kargo:</strong> ' . esc_html($carrier_label);
            }
            if ($delivered_time) {
                echo ' | <strong>Teslim:</strong> ' . date('d.m.Y', strtotime($delivered_time));
            } elseif ($shipped_time) {
                echo ' | <strong>Kargoya Verildi:</strong> ' . date('d.m.Y', strtotime($shipped_time));
            }
            echo '</div>';
            
            // Takip Kodu: Eğer manuel takip kodu girilmişse her durumda göster
            if (!empty($handler_shipment_code)) {
                echo '<div style="font-size: 11px; margin-bottom: 10px; padding: 5px; background: #e8f5e8; border: 1px solid #4caf50; border-radius: 3px;">';
                echo '<strong>📦 Kargo Takip Kodu:</strong> <span style="font-family: monospace; font-weight: bold; color: #2e7d32;">' . esc_html($handler_shipment_code) . '</span>';
                echo '</div>';
            } elseif (($status && in_array($status, ['wc-shipped', 'wc-delivered', 'completed', 'processing'])) || $shipped_time || $delivered_time) {
                $tracking_code = $this->generateTrackingCode($barcode, $order->get_id());
                echo '<div style="font-size: 11px; margin-bottom: 10px; padding: 5px; background: #e8f5e8; border: 1px solid #4caf50; border-radius: 3px;">';
                echo '<strong>📦 Kargo Takip Kodu:</strong> <span style="font-family: monospace; font-weight: bold; color: #2e7d32;">' . $tracking_code . '</span>';
                echo '</div>';
            } else {
                echo '<div style="font-size: 11px; margin-bottom: 10px; padding: 5px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 3px;">';
                echo '<strong>📦 Kargo Takip Kodu:</strong> <span style="color: #856404;">Henüz kargoya verilmedi</span>';
                echo '</div>';
            }
            
            // Action buttons (compact)
            echo '<div style="display: flex; flex-wrap: wrap; gap: 5px;">';
            // Open via AJAX to ensure BK order id/reference is fetched if missing
            echo '<button type="button" class="button button-small basit-kargo-button" data-action="basit_kargo_open_tracking" data-order-id="' . (int) $order->get_id() . '" style="font-size: 11px; padding: 4px 8px;">🔍 Basit Kargo ile takip ve iade kodu oluşturma</button>';
            
            // Kargo firması takip butonu (eğer varsa)
            if ($handler_tracking_link) {
                echo '<a href="' . $handler_tracking_link . '" target="_blank" class="button button-small" style="font-size: 11px; padding: 4px 8px;">🚚 kargo firması ile takip</a>';
            }
            
            echo '<button class="button button-small basit-kargo-button" data-action="basit_kargo_print_pdf" data-order-id="' . $order->get_id() . '" style="font-size: 11px; padding: 4px 8px;">📄 PDF</button>';
            echo '<button class="button button-small basit-kargo-button" data-action="basit_kargo_send_mail" data-order-id="' . $order->get_id() . '" style="font-size: 11px; padding: 4px 8px;">📧 Müşteriye mail</button>';
            echo '<button class="button button-small basit-kargo-button" data-action="basit_kargo_send_owner_mail" data-order-id="' . $order->get_id() . '" style="font-size: 11px; padding: 4px 8px;">📨 Depo görevlisine mail</button>';
            echo '<button class="button button-small basit-kargo-button" data-action="basit_kargo_send_delivered_mail" data-order-id="' . $order->get_id() . '" style="font-size: 11px; padding: 4px 8px;">✅ Teslim maili</button>';
            echo '<button class="button button-small basit-kargo-button" data-action="basit_kargo_barcode_sync" data-order-id="' . $order->get_id() . '" style="font-size: 11px; padding: 4px 8px;">🔄 Senkron</button>';
            echo '<button class="button button-small basit-kargo-button" data-action="basit_kargo_force_create_barcode" data-order-id="' . $order->get_id() . '" style="font-size: 11px; padding: 4px 8px;">⚙️ Zorla Barkod</button>';
            
            // Check if old format meta data exists
            $old_handler_code = $order->get_meta('basit_kargo_handler_code');
            if ($old_handler_code && !$order->get_meta('basit_kargo_handler_name')) {
                echo '<button class="button button-small basit-kargo-button" data-action="basit_kargo_convert_old_meta" data-order-id="' . $order->get_id() . '" style="font-size: 11px; padding: 4px 8px;">🔄 Dönüştür</button>';
            }
            
            echo '</div>';
            
            // Manual fields are moved to separate metabox
            
        } else {
            echo '<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 5px; text-align: center;">';
            echo '<p style="margin: 0 0 10px 0; color: #856404;">' . __('Henüz barkod oluşturulmamış', 'basit-kargo') . '</p>';
            echo '<button class="button button-primary basit-kargo-button" data-action="basit_kargo_generate_barcode" data-order-id="' . $order->get_id() . '" style="font-size: 12px;">📦 Barkod Oluştur</button>';
            echo '<button class="button basit-kargo-button" data-action="basit_kargo_force_create_barcode" data-order-id="' . $order->get_id() . '" style="margin-left:6px;">⚙️ Zorla Barkod</button>';
            echo '</div>';

            // Inline manual fields should also be available when no barcode exists
            echo '<div style="margin-top:8px; padding:8px; background:#f8f9fa; border:1px solid #e5e5e5; border-radius:4px;">';
            $manual_enabled = $order->get_meta('basit_kargo_manual_enabled') === 'yes';
            echo '<label style="font-size:11px; display:block; margin:0 0 6px;">
                    <input type="checkbox" id="bk-manual-enabled-inline" name="manual_enabled_inline" value="1" ' . checked($manual_enabled, true, false) . ' />
                    <strong>' . __('Manuel kargo bilgilerini kullan', 'basit-kargo') . '</strong>
                  </label>';
            echo '<label style="font-size:11px; display:block; margin-bottom:4px;">' . __('Kargo Firması (Manuel)', 'basit-kargo') . '</label>';
            $carrier_options = array('Surat Kargo','Yurtiçi Kargo','Aras Kargo','MNG Kargo','PTT Kargo','UPS Kargo','DHL','FedEx');
            echo '<select id="bk_inline_handler" name="bk_inline_handler" class="widefat" style="margin-bottom:6px;">';
            echo '<option value="">' . __('Seçiniz', 'basit-kargo') . '</option>';
            foreach ($carrier_options as $opt) {
                $sel = ($handler_name === $opt) ? ' selected' : '';
                echo '<option value="' . esc_attr($opt) . '"' . $sel . '>' . esc_html($opt) . '</option>';
            }
            echo '</select>';
            echo '<label style="font-size:11px; display:block; margin:6px 0 4px;">' . __('Kargo Takip Kodu (Müşteriye gidecek)', 'basit-kargo') . '</label>';
            echo '<input type="text" id="bk_inline_shipment_code" name="bk_inline_shipment_code" value="' . esc_attr($handler_shipment_code) . '" class="widefat" placeholder="15818078231488" />';
            echo '<div style="margin-top:8px; display:flex; align-items:center; gap:8px;">';
            echo '<button type="button" class="button" id="bk-inline-manual-save" data-order-id="' . (int) $order->get_id() . '">' . __('Manuel Kaydet', 'basit-kargo') . '</button>';
            echo '<span id="bk-inline-manual-result" style="font-size:11px; color:#777;"></span>';
            echo '</div>';
            echo '<small style="opacity:0.7; display:block; margin-top:6px;">' . __('Bu alanlar Basit Kargo’dan bağımsızdır; sipariş durumu ne olursa olsun kaydedilebilir.', 'basit-kargo') . '</small>';
            echo '</div>';
        }
        
        // Show cancelled/failed order info
        if (in_array($order->get_status(), array('cancelled', 'failed', 'refunded'))) {
            $cancelled_id = $order->get_meta('basit_kargo_cancelled_failed_id');
            $reference = $order->get_meta('basit_kargo_reference');
            
            if ($cancelled_id || $reference) {
                echo '<div class="basit-kargo-warning-box">';
                echo '<h4>' . __('İptal/Başarısız Sipariş', 'basit-kargo') . '</h4>';
                if ($cancelled_id) {
                    echo '<p><strong>' . __('Basit Kargo ID:', 'basit-kargo') . '</strong> ' . $cancelled_id . '</p>';
                }
                if ($reference) {
                    echo '<p><strong>' . __('Referans:', 'basit-kargo') . '</strong> ' . $reference . '</p>';
                }
                echo '</div>';
            }
        }
        
        echo '</div>';
    }
    
    /**
     * Generate tracking code (Basit Kargo sitesindeki gibi)
     */
    private function generateTrackingCode($barcode, $order_id) {
        // Basit Kargo'nun gerçek takip kodu: Handler Shipment Code
        // Örnek: 15818078231488
        $order = wc_get_order($order_id);
        if ($order) {
            $handler_shipment_code = $order->get_meta('basit_kargo_handler_shipment_code');
            if ($handler_shipment_code) {
                return $handler_shipment_code;
            }
        }
        
        // Fallback: Eğer handler shipment code yoksa, barkod kullan
        return $barcode;
    }
    
    /**
     * Get status information with colors and icons
     */
    private function getStatusInfo($status, $delivered_time, $shipped_time) {
        if ($delivered_time) {
            return array(
                'text' => 'Teslim Edildi',
                'icon' => '✅',
                'style' => 'background: #d4edda; color: #155724; border: 1px solid #c3e6cb;'
            );
        } elseif ($shipped_time) {
            return array(
                'text' => 'Kargoya Verildi',
                'icon' => '🚚',
                'style' => 'background: #cce5ff; color: #004085; border: 1px solid #b3d7ff;'
            );
        } elseif ($status) {
            $status_map = array(
                'manuel' => array('text' => 'Manuel İşlem', 'icon' => '⚙️', 'style' => 'background: #f8f9fa; color: #495057; border: 1px solid #dee2e6;'),
                'hazirlaniyor' => array('text' => 'Hazırlanıyor', 'icon' => '📦', 'style' => 'background: #fff3cd; color: #856404; border: 1px solid #ffeaa7;'),
                'kargoya_verildi' => array('text' => 'Kargoya Verildi', 'icon' => '🚚', 'style' => 'background: #cce5ff; color: #004085; border: 1px solid #b3d7ff;'),
                'teslim_edildi' => array('text' => 'Teslim Edildi', 'icon' => '✅', 'style' => 'background: #d4edda; color: #155724; border: 1px solid #c3e6cb;'),
                'iade' => array('text' => 'İade', 'icon' => '↩️', 'style' => 'background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb;')
            );
            
            if (isset($status_map[$status])) {
                return $status_map[$status];
            }
        }
        
        return array(
            'text' => 'Henüz Kargolanmadı',
            'icon' => '⏳',
            'style' => 'background: #e2e3e5; color: #383d41; border: 1px solid #d6d8db;'
        );
    }
    
    /**
     * Add order actions
     */
    public function addOrderActions($actions, $order) {
        $barcode = $order->get_meta('basit_kargo_barcode');
        
        if ($barcode) {
            // Only show PDF download action with direct link
            $pdf_url = admin_url('admin-ajax.php?action=basit_kargo_print_pdf&order_id=' . $order->get_id() . '&_ajax_nonce=' . wp_create_nonce('basit_kargo_nonce'));
            $actions['basit_kargo_print_pdf'] = array(
                'url' => $pdf_url,
                'name' => __('📄 PDF İndir', 'basit-kargo'),
                'action' => 'basit_kargo_print_pdf',
                'class' => 'basit-kargo-custom-button'
            );
            // Add Sync action
            $actions['basit_kargo_barcode_sync'] = array(
                'url' => 'javascript:void(0);',
                'name' => __('🔄 Senkron', 'basit-kargo'),
                'action' => 'basit_kargo_barcode_sync',
                'class' => 'button wc-action-button basit_kargo_barcode_sync'
            );
            // Force create
            $actions['basit_kargo_force_create_barcode'] = array(
                'url' => 'javascript:void(0);',
                'name' => __('⚙️ Zorla Barkod', 'basit-kargo'),
                'action' => 'basit_kargo_force_create_barcode',
                'class' => 'button wc-action-button basit_kargo_force_create_barcode'
            );
        } else {
            $actions['basit_kargo_manual_trigger'] = array(
                'url' => 'javascript:void(0);',
                'name' => __('📦 Barkod Oluştur', 'basit-kargo'),
                'action' => 'basit_kargo_manual_trigger',
                'class' => 'button wc-action-button basit_kargo_manual_trigger'
            );
            // Include Sync here as well to attempt remote re-creation
            $actions['basit_kargo_barcode_sync'] = array(
                'url' => 'javascript:void(0);',
                'name' => __('🔄 Senkron', 'basit-kargo'),
                'action' => 'basit_kargo_barcode_sync',
                'class' => 'button wc-action-button basit_kargo_barcode_sync'
            );
            $actions['basit_kargo_force_create_barcode'] = array(
                'url' => 'javascript:void(0);',
                'name' => __('⚙️ Zorla Barkod', 'basit-kargo'),
                'action' => 'basit_kargo_force_create_barcode',
                'class' => 'button wc-action-button basit_kargo_force_create_barcode'
            );
        }
        
        return $actions;
    }
    
    /**
     * Get button HTML
     */
    public function getButtonHtml($text, $action, $order_id, $class = '') {
        return sprintf(
            '<button class="basit-kargo-button %s" data-action="%s" data-order-id="%d">%s</button>',
            esc_attr($class),
            esc_attr($action),
            intval($order_id),
            esc_html($text)
        );
    }
    
    /**
     * Get info box HTML
     */
    public function getInfoBoxHtml($content, $type = 'info') {
        $class = 'basit-kargo-' . $type . '-box';
        return '<div class="' . esc_attr($class) . '">' . $content . '</div>';
    }
    
    /**
     * Get table HTML
     */
    public function getTableHtml($data, $class = '') {
        $html = '<table class="basit-kargo-table ' . esc_attr($class) . '">';
        
        if (!empty($data['headers'])) {
            $html .= '<thead><tr>';
            foreach ($data['headers'] as $header) {
                $html .= '<th>' . esc_html($header) . '</th>';
            }
            $html .= '</tr></thead>';
        }
        
        if (!empty($data['rows'])) {
            $html .= '<tbody>';
            foreach ($data['rows'] as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . esc_html($cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody>';
        }
        
        $html .= '</table>';
        return $html;
    }
}
