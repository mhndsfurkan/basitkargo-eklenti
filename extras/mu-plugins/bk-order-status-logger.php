<?php
/**
 * Plugin Name: Basit Kargo - Order Status Logger (MU)
 * Description: İsteğe bağlı MU-plugin. Sipariş durum değişikliklerini güvenli bir dosyaya loglar.
 * Author: Basit Kargo (Community)
 * Version: 1.0.0
 */

// Güvenlik
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bk_osl_get_log_path')) {
    function bk_osl_get_log_path() {
        // wp-config.php içine BK_STATUS_LOG_PATH tanımlanırsa onu kullan
        if (defined('BK_STATUS_LOG_PATH') && BK_STATUS_LOG_PATH) {
            return BK_STATUS_LOG_PATH;
        }

        // uploads/basitkargo-logs/ içine yaz
        $uploads = wp_upload_dir();
        $base_dir = isset($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
        $log_dir  = rtrim($base_dir, '/').'/basitkargo-logs';
        if (!is_dir($log_dir)) {
            // @ silici; yetki yoksa hata bastırma
            @wp_mkdir_p($log_dir);
        }
        return $log_dir.'/order-status.log';
    }
}

if (!function_exists('bk_osl_safe_write')) {
    function bk_osl_safe_write($message) {
        $path = bk_osl_get_log_path();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            @wp_mkdir_p($dir);
        }
        $line = rtrim($message, "\r\n")."\n";
        $ok = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        if ($ok === false) {
            // Fallback to PHP error_log
            error_log('[BK-STATUS] '.$message);
        }
    }
}

if (!function_exists('bk_osl_current_actor')) {
    function bk_osl_current_actor() {
        $actor = array(
            'user_id' => 0,
            'user_login' => '',
            'is_cli' => (php_sapi_name() === 'cli'),
            'is_cron' => (defined('DOING_CRON') && DOING_CRON),
            'ip' => '',
            'uri' => '',
            'ref' => ''
        );
        if (function_exists('get_current_user_id')) {
            $uid = get_current_user_id();
            $actor['user_id'] = $uid;
            if ($uid) {
                $u = get_user_by('id', $uid);
                if ($u) { $actor['user_login'] = $u->user_login; }
            }
        }
        $actor['ip']  = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $actor['uri'] = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $actor['ref'] = isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        return $actor;
    }
}

// Ana kanca: durum değiştiğinde logla
add_action('woocommerce_order_status_changed', function ($order_id, $old_status, $new_status) {
    try {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $actor = bk_osl_current_actor();
        $now   = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');

        $payload = array(
            'ts' => $now,
            'order_id' => (int) $order_id,
            'old' => (string) $old_status,
            'new' => (string) $new_status,
            'total' => $order ? $order->get_total() : null,
            'status_meta' => $order ? $order->get_status() : null,
            'actor' => $actor,
        );

        // JSON satır
        bk_osl_safe_write(wp_json_encode($payload));
    } catch (\Throwable $e) {
        bk_osl_safe_write('{"error":"'.$e->getMessage().'"}');
    }
}, 10, 3);

// İsteğe bağlı: sipariş kaydedilirken durum alanını da not düş (fazla gürültü yapmasın diye düşük öncelik)
add_action('woocommerce_before_order_object_save', function ($order) {
    if (!is_a($order, 'WC_Order')) { return; }
    try {
        $actor = bk_osl_current_actor();
        $now   = function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s');
        $payload = array(
            'ts' => $now,
            'order_id' => (int) $order->get_id(),
            'event' => 'before_save',
            'status' => $order->get_status(),
            'actor' => $actor,
        );
        bk_osl_safe_write(wp_json_encode($payload));
    } catch (\Throwable $e) {
        // sessiz
    }
}, 99);


