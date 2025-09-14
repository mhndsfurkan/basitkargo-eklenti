<?php
/**
 * Plugin Name: Basit Kargo
 * Plugin URI: https://basitkargo.com
 * Description: Basit Kargo entegrasyonu için WooCommerce eklentisi
 * Version: 2.0.0
 * Author: Basit Kargo
 * Text Domain: basit-kargo
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.6
 * WC requires at least: 5.0
 * WC tested up to: 9.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BASIT_KARGO_VERSION', '2.0.0');
define('BASIT_KARGO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BASIT_KARGO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BASIT_KARGO_PLUGIN_FILE', __FILE__);

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>Basit Kargo</strong> eklentisi WooCommerce gerektirir. Lütfen WooCommerce\'i aktifleştirin.</p></div>';
    });
    return;
}

// Declare HPOS compatibility
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
    // Ensure custom statuses are registered early for HPOS orders table
    add_filter('woocommerce_register_shop_order_post_statuses', function($statuses) {
        $statuses['wc-shipped'] = array(
            'label' => _x('Kargoya Verildi', 'Order status', 'basit-kargo'),
            'public' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'exclude_from_search' => false,
            'label_count' => _n_noop('Kargoya Verildi <span class="count">(%s)</span>', 'Kargoya Verildi <span class="count">(%s)</span>', 'basit-kargo'),
        );
        $statuses['wc-delivered'] = array(
            'label' => _x('Teslim Edildi', 'Order status', 'basit-kargo'),
            'public' => true,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'exclude_from_search' => false,
            'label_count' => _n_noop('Teslim Edildi <span class="count">(%s)</span>', 'Teslim Edildi <span class="count">(%s)</span>', 'basit-kargo'),
        );
        return $statuses;
    });
});

// Check WooCommerce version compatibility
add_action('admin_init', function() {
    if (class_exists('WooCommerce')) {
        $wc_version = WC()->version;
        $required_version = '5.0.0';
        
        if (version_compare($wc_version, $required_version, '<')) {
            add_action('admin_notices', function() use ($wc_version, $required_version) {
                echo '<div class="notice notice-error"><p><strong>Basit Kargo</strong> eklentisi WooCommerce ' . $required_version . ' veya üzeri gerektirir. Mevcut sürüm: ' . $wc_version . '</p></div>';
            });
        }
    }
});

// Autoloader for classes
spl_autoload_register(function ($class) {
    $prefix = 'BasitKargo\\';
    $base_dir = BASIT_KARGO_PLUGIN_DIR . 'includes/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('\\', '-', $relative_class)) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Main plugin class
class BasitKargo {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init();
    }
    
    private function init() {
        // Load text domain
        add_action('plugins_loaded', array($this, 'loadTextDomain'));
        
        // Initialize components
        add_action('init', array($this, 'initComponents'));
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function loadTextDomain() {
        load_plugin_textdomain('basit-kargo', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function initComponents() {
        // Initialize API
        new BasitKargo\API();
        
        // Initialize Admin
        new BasitKargo\Admin();
        
        // Initialize UI
        new BasitKargo\UI();
        
        // Initialize Email
        new BasitKargo\Email();
        
        // Initialize PDF
        new BasitKargo\PDF();
    }
    
    public function activate() {
        // Create database tables if needed
        $this->createTables();
        
        // Set default options
        $this->setDefaultOptions();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        // Clean up if needed
        flush_rewrite_rules();
    }
    
    private function createTables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'basit_kargo_orders';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            barcode varchar(255) DEFAULT NULL,
            api_id varchar(255) DEFAULT NULL,
            status varchar(50) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY order_id (order_id),
            KEY barcode (barcode),
            KEY api_id (api_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    private function setDefaultOptions() {
        $defaults = array(
            'basit_kargo_token' => '',
            'basit_kargo_handler_code' => '',
            'basit_kargo_city_name' => '',
            'basit_kargo_town_name' => '',
            'basit_kargo_notify_email' => get_option('admin_email'),
            'basit_kargo_auto_generate_barcode' => 'yes',
            'basit_kargo_auto_send_email' => 'yes',
            'basit_kargo_auto_send_delivered_email' => 'yes',
            'basit_kargo_auto_update_status' => 'yes'
        );
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
}

// Initialize the plugin
function basit_kargo_init() {
    return BasitKargo::getInstance();
}

// Start the plugin
basit_kargo_init();
