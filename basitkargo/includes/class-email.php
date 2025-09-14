<?php
/**
 * Basit Kargo Email Class
 * Handles email notifications and templates
 */

namespace BasitKargo;

if (!defined('ABSPATH')) {
    exit;
}

class Email {
    
    public function __construct() {
        $this->initHooks();
    }
    
    private function initHooks() {
        // Email hooks
        add_action('woocommerce_order_status_changed', array($this, 'handleOrderStatusChange'), 10, 3);
        // When barcode is generated, send only to owner (not customer)
        add_action('basit_kargo_barcode_generated', array($this, 'handleBarcodeGenerated'), 10, 2);
        
        // Custom email templates
        add_filter('woocommerce_email_styles', array($this, 'addEmailStyles'));
    }
    
    /**
     * Handle order status change
     */
    public function handleOrderStatusChange($order_id, $old_status, $new_status) {
        // Debug logging
        error_log("Basit Kargo Email: handleOrderStatusChange called - Order ID: $order_id, Old: $old_status, New: $new_status");
        
        $order = wc_get_order($order_id);
        if (!$order) { 
            error_log("Basit Kargo Email: Order not found for ID: $order_id");
            return; 
        }
        
        $auto_email = get_option('basit_kargo_auto_send_email') === 'yes';
        $auto_delivered_email = get_option('basit_kargo_auto_send_delivered_email', 'yes') === 'yes';
        $auto_generate = get_option('basit_kargo_auto_generate_barcode', 'yes') !== 'no';
        
        error_log("Basit Kargo Email: Auto email: " . ($auto_email ? 'yes' : 'no') . ", Auto generate: " . ($auto_generate ? 'yes' : 'no'));
        
        // Handle barcode generation for processing/completed orders
        if ($auto_generate && in_array($new_status, array('processing', 'completed'))) {
            $api = new \BasitKargo\API();
            $barcode = $order->get_meta('basit_kargo_barcode');
            // If manual mode enabled, do NOT auto-create Basit Kargo barcode
            $manual_enabled = $order->get_meta('basit_kargo_manual_enabled') === 'yes';
            
            if (empty($barcode) && !$manual_enabled) {
                $create = $api->createBarcode($order);
                if ($create['success']) {
                    $order->add_order_note(__('Otomatik barkod oluşturuldu. Not: Durum, kargo takip kodu geldiğinde "Kargoya Verildi" yapılır.', 'basit-kargo'));
                    // Send owner mail once
                    if (!$order->get_meta('basit_kargo_owner_mail_sent_at')) {
                        $this->sendOwnerEmail($order);
                        $order->update_meta_data('basit_kargo_owner_mail_sent_at', current_time('mysql'));
                        $order->save();
                    }
                }
            } else {
                // Only try to sync existing barcode; if manual info exists, skip auto (re)creation
                $sync = !empty($barcode) ? $api->fetchBarcodeData($order) : array('success' => false);
                if (!$sync['success'] && !empty($barcode) && !$manual_enabled) {
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
                            $this->sendOwnerEmail($order);
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
        
        // On shipped (manual selection or programmatic), send customer tracking email
        if (($new_status === 'shipped' || $new_status === 'wc-shipped')) {
            // Record the moment this order was marked as shipped
            $now_shipped = current_time('mysql');
            $order->update_meta_data('basit_kargo_last_marked_shipped_at', $now_shipped);
            $auto_send_tracking = $auto_email;
            $already = $order->get_meta('basit_kargo_shipped_mail_sent');
            $last_marked = $order->get_meta('basit_kargo_last_marked_shipped_at');
            $should_send = $auto_send_tracking && (empty($already) || (strtotime($already) < strtotime($last_marked)));
            $barcode = $order->get_meta('basit_kargo_barcode');
            $shipment_code = $order->get_meta('basit_kargo_handler_shipment_code');
            $has_tracking = ($barcode || !empty($shipment_code));
            error_log("Basit Kargo Email: Shipped status detected. Auto: " . ($auto_send_tracking ? 'yes' : 'no') . ", Already: " . ($already ? $already : 'no') . ", LastMarked: " . ($last_marked ?: 'none') . ", HasTracking: " . ($has_tracking ? 'yes' : 'no'));
            if ($should_send && $has_tracking) {
                $sent = $this->sendTrackingEmail($order);
                if ($sent['success']) {
                    $order->update_meta_data('basit_kargo_shipped_mail_sent', current_time('mysql'));
                    $order->save();
                    error_log("Basit Kargo Email: Shipped email sent and marked as sent");
                } else {
                    error_log("Basit Kargo Email: Shipped email FAILED to send");
                    // Persist last_marked even if failed
                    $order->save();
                }
            } else {
                // Persist last_marked even if we won't send
                $order->save();
                error_log("Basit Kargo Email: Not sending shipped email (should_send=" . ($should_send ? 'yes' : 'no') . ")");
            }
        }
        
        // On delivered, send customer delivered confirmation email
        if (in_array($new_status, array('completed', 'delivered', 'wc-delivered'), true)) {
            // Record the moment this order was marked as delivered
            $now_delivered = current_time('mysql');
            $order->update_meta_data('basit_kargo_last_marked_delivered_at', $now_delivered);
            if ($auto_delivered_email) {
                error_log("Basit Kargo Email: Checking delivered email for status: $new_status");
                $already = $order->get_meta('basit_kargo_delivered_mail_sent');
                $last_marked_delivered = $order->get_meta('basit_kargo_last_marked_delivered_at');
                $should_send_delivered = (empty($already) || (strtotime($already) < strtotime($last_marked_delivered)));
                error_log("Basit Kargo Email: Already sent delivered email: " . ($already ? $already : 'no') . ", LastMarkedDelivered: " . ($last_marked_delivered ?: 'none'));
                if ($should_send_delivered) {
                    error_log("Basit Kargo Email: Sending delivered email...");
                    $sent = $this->sendDeliveredEmail($order);
                    if ($sent) {
                        $order->update_meta_data('basit_kargo_delivered_mail_sent', current_time('mysql'));
                        $order->save();
                        error_log("Basit Kargo Email: Delivered email sent and marked as sent");
                    } else {
                        error_log("Basit Kargo Email: Delivered email FAILED to send (wp_mail returned false)");
                        // Persist last_marked even if failed
                        $order->save();
                    }
                } else {
                    // Persist last_marked; skip resend
                    $order->save();
                    error_log("Basit Kargo Email: Delivered email already sent after last delivered mark, skipping");
                }
            } else {
                // Persist last_marked; auto delivered disabled
                $order->save();
                error_log("Basit Kargo Email: Not sending delivered email - Status: $new_status, Auto delivered: no");
            }
        } else {
            error_log("Basit Kargo Email: Not sending delivered email - Status: $new_status, Auto delivered: " . ($auto_delivered_email ? 'yes' : 'no'));
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
        $reference = $order->get_meta('basit_kargo_reference');
        $api_id    = $order->get_meta('basit_kargo_api_id');
        
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
        $api = new \BasitKargo\API();
        $result = $api->updateOrderStatus($order, $status);
        
        if ($result['success']) {
            $order->add_order_note(__('Basit Kargo durumu güncellendi: ', 'basit-kargo') . $status);
        }
    }
    
    /**
     * Create cancelled/failed entry
     */
    private function createCancelledFailedEntry($order, $status) {
        $api = new \BasitKargo\API();
        $result = $api->createCancelledFailedEntry($order, $status);
        
        if ($result['success']) {
            $order->add_order_note(__('Basit Kargo iptal/başarısız kaydı oluşturuldu', 'basit-kargo'));
        }
    }

    /**
     * Handle barcode generated event: send only to owner
     */
    public function handleBarcodeGenerated($order_id, $barcode) {
        $order = wc_get_order($order_id);
        if (!$order) { return; }
        $auto = get_option('basit_kargo_auto_send_email') === 'yes';
        if ($auto) { $this->sendOwnerEmail($order); }
    }
    
    /**
     * Send tracking email to customer (simple tracking info)
     */
    public function sendTrackingEmail($order) {
        if (!$order) {
            return array('success' => false, 'message' => 'Sipariş bulunamadı');
        }
        
        $barcode = $order->get_meta('basit_kargo_barcode');
        $shipment_code = $order->get_meta('basit_kargo_handler_shipment_code');
        
        // Allow sending if we have either a Basit Kargo barcode or any shipment code
        if (empty($barcode) && empty($shipment_code)) {
            return array('success' => false, 'message' => 'Takip bilgisi bulunamadı');
        }
        
        $result = $this->sendCustomerTrackingEmail($order);
        
        // Site sahibine de mail gönder
        $owner_result = $this->sendOwnerEmail($order);
        
        if ($result) {
            return array('success' => true, 'message' => 'Müşteriye ve site sahibine mail başarıyla gönderildi');
        } else {
            return array('success' => false, 'message' => 'Mail gönderilemedi');
        }
    }
    
    /**
     * Send detailed email to site owner (all info + barcode)
     */
    public function sendOwnerEmail($order) {
        if (!$order) {
            return array('success' => false, 'message' => 'Sipariş bulunamadı');
        }
        
        $barcode = $order->get_meta('basit_kargo_barcode');
        if (!$barcode) {
            return array('success' => false, 'message' => 'Barkod bulunamadı');
        }
        
        $result = $this->sendOwnerDetailedEmail($order);
        
        if ($result) {
            return array('success' => true, 'message' => 'Site sahibine mail başarıyla gönderildi');
        } else {
            return array('success' => false, 'message' => 'Mail gönderilemedi');
        }
    }
    
    /**
     * Send customer tracking email (simple)
     */
    private function sendCustomerTrackingEmail($order) {
        $barcode = $order->get_meta('basit_kargo_barcode');
        $shipment_code = $order->get_meta('basit_kargo_handler_shipment_code');
        $carrier_name = $order->get_meta('basit_kargo_handler_name');
        $carrier_link = $order->get_meta('basit_kargo_handler_tracking_link');
        
        // Otomatik kargo firması takip linki oluştur
        $auto_carrier_link = '';
        if ($carrier_name && $shipment_code) {
            $handler_code = $order->get_meta('basit_kargo_handler_code');
            $auto_carrier_link = $this->getCarrierTrackingUrl($carrier_name, $shipment_code, $handler_code);
        }
        
        // Basit Kargo linki: Buton ile aynı mantık (api_id > reference). Eğer ikisi de yoksa API'den çekmeyi dene.
        $bk_link = $this->resolveBkTrackingUrl($order);
        
        // Müşteriye kod olarak sadece kargo firmasının takip kodunu gönder; yoksa boş bırak
        $display_code = $shipment_code ? $shipment_code : '';
        
        $subject = sprintf(
            __('Siparişiniz Kargoya Verildi - #%s', 'basit-kargo'),
            $order->get_order_number()
        );
        
        $message = $this->getCustomerEmailTemplate($order, $display_code, $carrier_name, $auto_carrier_link, $bk_link);
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );
        
        $sent = wp_mail($order->get_billing_email(), $subject, $message, $headers);
        
        if ($sent) {
            $order->add_order_note(__('Müşteriye kargo takip maili gönderildi', 'basit-kargo'));
            return true;
        }
        
        return false;
    }

    /**
     * Send delivered confirmation email to customer
     */
    public function sendDeliveredEmail($order) {
        if (!$order) { return false; }
        $recipient = $order->get_billing_email();
        if (!$recipient) {
            error_log('Basit Kargo Email: Missing billing email for order #' . $order->get_order_number());
            return false;
        }
        $barcode = $order->get_meta('basit_kargo_barcode');
        $reference = $order->get_meta('basit_kargo_reference');
        $api_id    = $order->get_meta('basit_kargo_api_id');
        $shipment_code = $order->get_meta('basit_kargo_handler_shipment_code');
        $carrier_name = $order->get_meta('basit_kargo_handler_name');
        $carrier_link = $order->get_meta('basit_kargo_handler_tracking_link');
        
        // Otomatik kargo firması takip linki oluştur
        $auto_carrier_link = '';
        if ($carrier_name && $shipment_code) {
            $handler_code = $order->get_meta('basit_kargo_handler_code');
            $auto_carrier_link = $this->getCarrierTrackingUrl($carrier_name, $shipment_code, $handler_code);
        }
        
        // Basit Kargo linki: Buton ile aynı mantık (api_id > reference). Eğer ikisi de yoksa API'den çekmeyi dene.
        $bk_link = $this->resolveBkTrackingUrl($order);
        $subject = sprintf(__('Siparişiniz Teslim Edildi - #%s', 'basit-kargo'), $order->get_order_number());
        $message = '
        <!DOCTYPE html>
        <html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . __('Teslimat Onayı', 'basit-kargo') . '</title></head>
        <body style="margin:0;padding:0;font-family:Arial, sans-serif;background:#f4f4f4;">
          <div style="max-width:600px;margin:0 auto;background:#fff;padding:20px;">
            <div style="text-align:center;margin-bottom:20px;border-bottom:2px solid #28a745;padding-bottom:10px;">
              <h1 style="color:#28a745;margin:0;font-size:22px;">' . __('Siparişiniz Teslim Edildi', 'basit-kargo') . '</h1>
            </div>
            <p style="font-size:15px;color:#333;">' . sprintf(__('Merhaba %s,', 'basit-kargo'), $order->get_billing_first_name()) . '</p>
            <p style="font-size:14px;color:#666;">' . sprintf(__('Siparişiniz (#%s) başarıyla teslim edilmiştir.', 'basit-kargo'), $order->get_order_number()) . '</p>
            ' . ($carrier_name ? '<p style="font-size:14px;color:#333;"><strong>' . __('Kargo Firması:', 'basit-kargo') . '</strong> ' . $carrier_name . '</p>' : '') . '
            ' . ($shipment_code ? '<p style="font-size:14px;color:#333;"><strong>' . __('Takip Kodu:', 'basit-kargo') . '</strong> ' . $shipment_code . '</p>' : '') . '
            <div style="margin:15px 0;">
              ' . ($auto_carrier_link ? '<a href="' . $auto_carrier_link . '" target="_blank" style="background:#28a745;color:#fff;padding:10px 16px;text-decoration:none;border-radius:5px;display:inline-block;margin-right:8px;">' . ($carrier_name ? $carrier_name . ' Takip' : __('Kargo Firması Takip', 'basit-kargo')) . '</a>' : '') . '
              ' . ($bk_link ? '<a href="' . $bk_link . '" style="background:#0073aa;color:#fff;padding:10px 16px;text-decoration:none;border-radius:5px;display:inline-block;">' . __('Basit Kargo Takip', 'basit-kargo') . '</a>' : '') . '
            </div>
            <p style="font-size:13px;color:#999;">' . __('Bizi tercih ettiğiniz için teşekkür ederiz.', 'basit-kargo') . '</p>
          </div>
        </body></html>';
        $headers = array('Content-Type: text/html; charset=UTF-8', 'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>');
        $sent = wp_mail($recipient, $subject, $message, $headers);
        if ($sent) { 
            $order->add_order_note(__('Müşteriye teslim edildi maili gönderildi', 'basit-kargo')); 
        }
        return $sent;
    }
    
    /**
     * Send owner detailed email (all info + barcode)
     */
    private function sendOwnerDetailedEmail($order) {
        $tracking_url = $this->resolveBkTrackingUrl($order);
        
        $subject = sprintf(
            __('Kargo Etiketi - Sipariş #%s', 'basit-kargo'),
            $order->get_order_number()
        );
        
        $message = $this->getOwnerEmailTemplate($order, $barcode, $tracking_url);
        
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        );
        
        $sent = wp_mail(get_option('admin_email'), $subject, $message, $headers);
        
        if ($sent) {
            $order->add_order_note(__('Site sahibine detaylı kargo maili gönderildi', 'basit-kargo'));
            return true;
        }
        
        return false;
    }
    
    /**
     * Send barcode notification email (legacy) - DISABLED to prevent duplicate emails
     */
    public function sendBarcodeNotification($order_id, $barcode) {
        // This function is disabled to prevent duplicate emails
        // Only sendCustomerTrackingEmail should be used
        return false;
    }
    
    /**
     * Get carrier tracking URL with automatic search
     */
    private function getCarrierTrackingUrl($carrier_name, $tracking_code, $handler_code = '') {
        // Prefer handler code if available
        $code = strtoupper(trim((string) $handler_code));
        $name = strtolower(trim((string) $carrier_name));
        $name = str_replace(array(' kargo', ' cargo', ' cargó'), '', $name);
        
        // Map canonical codes to URL prefixes (Updated 2024)
        $byCode = array(
            'SURAT' => 'https://www.suratkargo.com.tr/KargoTakip/?kargotakipno=',
            'YURTICI' => 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=',
            'ARAS' => 'http://kargotakip.araskargo.com.tr/mainpage.aspx?code=',
            'MNG' => 'https://kargotakip.mngkargo.com.tr/?takipNo=',
            'PTT' => 'https://gonderitakip.ptt.gov.tr/Track/Verify?q=',
            'UPS' => 'https://www.ups.com.tr/WaybillSorgu.aspx?Waybill=',
            'DHL' => 'https://www.dhl.com.tr/exp-tr/express/tracking.html?AWB=',
            'FEDEX' => 'https://www.fedex.com/apps/fedextrack/index.html?tracknumbers='
        );
        
        if (isset($byCode[$code])) {
            $url = $byCode[$code] . $tracking_code;
            // DHL için özel parametre ekle
            if ($code === 'DHL') {
                $url .= '&brand=DHL';
            }
            return $url;
        }
        
        // Fallback: normalize by name
        $aliases = array(
            'surat' => 'SURAT',
            'sürat' => 'SURAT',
            'yurtici' => 'YURTICI',
            'yurtiçi' => 'YURTICI',
            'aras' => 'ARAS',
            'mng' => 'MNG',
            'ptt' => 'PTT',
            'ups' => 'UPS',
            'dhl' => 'DHL',
            'fedex' => 'FEDEX'
        );
        foreach ($aliases as $key => $canon) {
            if (strpos($name, $key) !== false && isset($byCode[$canon])) {
                $url = $byCode[$canon] . $tracking_code;
                // DHL için özel parametre ekle
                if ($canon === 'DHL') {
                    $url .= '&brand=DHL';
                }
                return $url;
            }
        }
        
        return '';
    }

    /**
     * Get customer email template (simple tracking info)
     */
    private function getCustomerEmailTemplate($order, $display_code, $carrier_name, $carrier_link, $bk_link) {
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . __('Siparişiniz Kargoya Verildi', 'basit-kargo') . '</title>
        </head>
        <body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
            <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px;">
                
                <!-- Header -->
                <div style="text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #0073aa;">
                    <h1 style="color: #0073aa; margin: 0; font-size: 24px;">' . __('Siparişiniz Kargoya Verildi', 'basit-kargo') . '</h1>
                </div>
                
                <!-- Content -->
                <div style="margin-bottom: 30px;">
                    <p style="font-size: 16px; color: #333; margin-bottom: 20px;">
                        ' . sprintf(__('Merhaba %s,', 'basit-kargo'), $order->get_billing_first_name()) . '
                    </p>
                    
                    <p style="font-size: 14px; color: #666; margin-bottom: 20px;">
                        ' . sprintf(__('Siparişiniz (#%s) kargoya verilmiştir.', 'basit-kargo'), $order->get_order_number()) . '
                    </p>
                    
                    <!-- Tracking Info -->
                    <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                        <h3 style="color: #0073aa; margin-top: 0;">' . __('Kargo Takip Bilgileri', 'basit-kargo') . '</h3>
                        ' . ($carrier_name ? '<p style="margin: 10px 0;"><strong>' . __('Kargo Firması:', 'basit-kargo') . '</strong> ' . $carrier_name . '</p>' : '') . '
                        ' . ($display_code ? '<p style="margin: 10px 0;"><strong>' . __('Takip Kodu:', 'basit-kargo') . '</strong> ' . $display_code . '</p>' : '') . '
                        <div style="margin: 10px 0;">
                            ' . ($carrier_link ? '<a href="' . $carrier_link . '" target="_blank" style="background-color: #28a745; color: white; padding: 10px 16px; text-decoration: none; border-radius: 5px; display: inline-block; margin-right:8px;">' . ($carrier_name ? $carrier_name . ' Takip' : __('Kargo Firması Takip', 'basit-kargo')) . '</a>' : '') . '
                            ' . ($bk_link ? '<a href="' . $bk_link . '" target="_blank" style="background-color: #0073aa; color: white; padding: 10px 16px; text-decoration: none; border-radius: 5px; display: inline-block;">' . __('Basit Kargo Takip', 'basit-kargo') . '</a>' : '') . '
                        </div>
                    </div>
                    
                    <p style="font-size: 14px; color: #666;">
                        ' . __('Kargonuzu takip etmek için yukarıdaki linkleri kullanabilirsiniz. İade kodu oluşturmak için takip sayfasındaki "İade Kodu Oluştur" butonunu kullanabilirsiniz.', 'basit-kargo') . '
                    </p>
                </div>
                
                <!-- Footer -->
                <div style="text-align: center; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px;">
                    <p>' . sprintf(__('Teşekkürler,<br>%s', 'basit-kargo'), get_bloginfo('name')) . '</p>
                </div>
                
            </div>
        </body>
        </html>';
        
        return $template;
    }
    
    /**
     * Get owner email template (all info + barcode)
     */
    private function getOwnerEmailTemplate($order, $barcode, $tracking_url) {
        $billing = $order->get_address('billing');
        $shipping = $order->get_address('shipping');
        
        // Get all Basit Kargo data
        $api_id = $order->get_meta('basit_kargo_api_id');
        $status = $order->get_meta('basit_kargo_status');
        $handler_name = $order->get_meta('basit_kargo_handler_name');
        $handler_code = $order->get_meta('basit_kargo_handler_code');
        $shipment_fee = $order->get_meta('basit_kargo_shipment_fee');
        $total_cost = $order->get_meta('basit_kargo_total_cost');
        $created_time = $order->get_meta('basit_kargo_created_time');
        $pdf_url = admin_url('admin-ajax.php?action=basit_kargo_print_pdf&order_id=' . $order->get_id());
        
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . __('Kargo Barkodu - Sipariş #%s', 'basit-kargo') . '</title>
        </head>
        <body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
            <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 15px;">
                
                <!-- Header -->
                <div style="text-align: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #0073aa;">
                    <h1 style="color: #0073aa; margin: 0; font-size: 20px;">' . __('Kargo Barkodu - Sipariş #%s', 'basit-kargo') . '</h1>
                </div>
                
                <!-- Compact Info -->
                <div style="margin-bottom: 20px; font-size: 14px;">
                    <div style="background-color: #f8f9fa; padding: 10px; border-radius: 5px; margin-bottom: 10px;">
                        <strong>' . __('Sipariş:', 'basit-kargo') . '</strong> #' . $order->get_order_number() . ' | 
                        <strong>' . __('Müşteri:', 'basit-kargo') . '</strong> ' . $billing['first_name'] . ' ' . $billing['last_name'] . ' | 
                        <strong>' . __('Tel:', 'basit-kargo') . '</strong> ' . $billing['phone'] . '
                    </div>
                    <div style="background-color: #f8f9fa; padding: 10px; border-radius: 5px;">
                        <strong>' . __('Alıcı:', 'basit-kargo') . '</strong> ' . $shipping['first_name'] . ' ' . $shipping['last_name'] . '<br>
                        <strong>' . __('Adres:', 'basit-kargo') . '</strong> ' . $shipping['address_1'] . ($shipping['address_2'] ? ', ' . $shipping['address_2'] : '') . ', ' . $shipping['city'] . ' ' . $shipping['state'] . ' ' . $shipping['postcode'] . '
                    </div>
                </div>
                
                <!-- Order Items -->
                <div style="margin-bottom: 20px;">
                    <h3 style="color: #333; margin: 0 0 10px 0; font-size: 16px;">' . __('Sipariş İçeriği', 'basit-kargo') . '</h3>
                    <div style="background-color: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 14px;">';
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $template .= '<div style="margin-bottom: 5px;">
                            <strong>' . $item->get_quantity() . 'x</strong> ' . $item->get_name();
            
            if ($product && $product->get_sku()) {
                $template .= ' <small style="color: #666;">(' . $product->get_sku() . ')</small>';
            }
            
            $template .= ' - ' . wc_price($item->get_subtotal()) . '
                        </div>';
        }
        
        $template .= '<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd; font-weight: bold;">
                        ' . __('Toplam:', 'basit-kargo') . ' ' . wc_price($order->get_total()) . '
                    </div>
                </div>
                </div>
                
                <!-- Basit Kargo Info -->
                <div style="margin-bottom: 20px;">
                    <h3 style="color: #333; margin: 0 0 10px 0; font-size: 16px;">' . __('Kargo Bilgileri', 'basit-kargo') . '</h3>
                    <div style="background-color: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 14px;">
                        <div style="margin-bottom: 5px;">
                            <strong>' . __('Kargo Barkodu:', 'basit-kargo') . '</strong> <span style="font-family: monospace; font-weight: bold;">' . $barcode . '</span>
                        </div>';
        
        if ($handler_name) {
            $template .= '<div style="margin-bottom: 5px;">
                            <strong>' . __('Kargo Firması:', 'basit-kargo') . '</strong> ' . $handler_name . ' (' . $handler_code . ')
                        </div>';
        }
        
        if ($status) {
            $template .= '<div style="margin-bottom: 5px;">
                            <strong>' . __('Durum:', 'basit-kargo') . '</strong> ' . $status . '
                        </div>';
        }
        
        if ($shipment_fee) {
            $template .= '<div style="margin-bottom: 5px;">
                            <strong>' . __('Kargo Ücreti:', 'basit-kargo') . '</strong> ' . $shipment_fee . ' TL
                        </div>';
        }
        
        $template .= '</div>
                </div>
                
                <!-- Barcode -->
                <div style="text-align: center; margin-bottom: 20px; padding: 15px; border: 2px solid #0073aa; border-radius: 8px;">
                    <h3 style="color: #0073aa; margin: 0 0 10px 0; font-size: 16px;">' . __('Kargo Barkodu (100x100mm)', 'basit-kargo') . '</h3>
                    <div style="display: inline-block; padding: 8px; background-color: white; border: 1px solid #ddd;">
                        <img src="https://barcode.tec-it.com/barcode.ashx?data=' . $barcode . '&code=Code128&dpi=300&dataseparator=" alt="Barcode" style="max-width: 200px; height: 50px;">
                    </div>
                    <p style="margin: 8px 0 0 0; font-family: monospace; font-size: 14px; font-weight: bold;">Kargo Kodu: ' . $barcode . '</p>
                    <p style="margin: 5px 0 10px 0; font-size: 12px; color: #666;">Bu barkod 100x100mm boyutunda yazdırılabilir</p>
                    <p style="margin: 0;">
                        <a href="' . $pdf_url . '" style="background-color:#28a745;color:#fff;padding:10px 16px;text-decoration:none;border-radius:5px;display:inline-block;">
                            ' . __('PDF Olarak İndir', 'basit-kargo') . '
                        </a>
                    </p>
                </div>
                
                <!-- Tracking Link -->
                <div style="text-align: center; margin-bottom: 20px;">
                    <a href="' . $tracking_url . '" style="background-color: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; font-size: 14px;">' . __('Takip Et', 'basit-kargo') . '</a>
                </div>
                
                <!-- Footer -->
                <div style="text-align: center; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px;">
                    <p>' . sprintf(__('Bu mail %s tarafından otomatik olarak gönderilmiştir.', 'basit-kargo'), get_bloginfo('name')) . '</p>
                </div>
                
            </div>
        </body>
        </html>';
        
        return $template;
    }
    
    /**
     * Get barcode email template (legacy)
     */
    private function getBarcodeEmailTemplate($order, $barcode, $tracking_url) {
        $template = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . __('Siparişiniz Kargoya Verildi', 'basit-kargo') . '</title>
        </head>
        <body style="margin: 0; padding: 0; font-family: Arial, sans-serif; background-color: #f4f4f4;">
            <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 20px;">
                
                <!-- Header -->
                <div style="text-align: center; margin-bottom: 30px; padding-bottom: 20px; border-bottom: 2px solid #0073aa;">
                    <h1 style="color: #0073aa; margin: 0; font-size: 24px;">' . __('Siparişiniz Kargoya Verildi', 'basit-kargo') . '</h1>
                </div>
                
                <!-- Greeting -->
                <div style="margin-bottom: 20px;">
                    <p style="font-size: 16px; color: #333; margin: 0;">
                        ' . sprintf(__('Merhaba %s,', 'basit-kargo'), $order->get_billing_first_name()) . '
                    </p>
                </div>
                
                <!-- Order Info -->
                <div style="background-color: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px;">
                    <h2 style="color: #0073aa; margin: 0 0 15px 0; font-size: 18px;">' . __('Sipariş Bilgileri', 'basit-kargo') . '</h2>
                    <p style="margin: 5px 0; color: #333;">
                        <strong>' . __('Sipariş Numarası:', 'basit-kargo') . '</strong> #' . $order->get_order_number() . '
                    </p>
                    <p style="margin: 5px 0; color: #333;">
                        <strong>' . __('Sipariş Tarihi:', 'basit-kargo') . '</strong> ' . $order->get_date_created()->date_i18n(get_option('date_format')) . '
                    </p>
                    <p style="margin: 5px 0; color: #333;">
                        <strong>' . __('Toplam Tutar:', 'basit-kargo') . '</strong> ' . $order->get_formatted_order_total() . '
                    </p>
                </div>
                
                <!-- Cargo Info -->
                <div style="background-color: #e7f3ff; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #0073aa;">
                    <h2 style="color: #0073aa; margin: 0 0 15px 0; font-size: 18px;">' . __('Kargo Bilgileri', 'basit-kargo') . '</h2>
                    <p style="margin: 10px 0; color: #333; font-size: 16px;">
                        <strong>' . __('Kargo Barkodu:', 'basit-kargo') . '</strong> ' . $barcode . '
                    </p>
                    <div style="text-align: center; margin: 20px 0;">
                        <a href="' . $tracking_url . '" style="display: inline-block; background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
                            ' . __('Kargonuzu Takip Edin', 'basit-kargo') . '
                        </a>
                    </div>
                    <p style="margin: 10px 0; color: #666; font-size: 14px;">
                        ' . __('Takip Linki:', 'basit-kargo') . ' <a href="' . $tracking_url . '" style="color: #0073aa;">' . $tracking_url . '</a>
                    </p>
                </div>
                
                <!-- Return Code Info -->
                <div style="background-color: #fff3cd; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
                    <h3 style="color: #856404; margin: 0 0 10px 0; font-size: 16px;">' . __('İade Kodu Oluşturma', 'basit-kargo') . '</h3>
                    <p style="margin: 0; color: #856404; font-size: 14px;">
                        ' . __('Kargonuzu iade etmek istiyorsanız, yukarıdaki takip linkine tıklayarak "İade Kodu Oluştur" butonunu kullanabilirsiniz.', 'basit-kargo') . '
                    </p>
                </div>
                
                <!-- Order Items -->
                <div style="margin-bottom: 20px;">
                    <h3 style="color: #333; margin: 0 0 15px 0; font-size: 16px;">' . __('Sipariş İçeriği', 'basit-kargo') . '</h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background-color: #f8f9fa;">
                                <th style="padding: 10px; text-align: left; border-bottom: 1px solid #dee2e6; color: #333;">' . __('Ürün', 'basit-kargo') . '</th>
                                <th style="padding: 10px; text-align: center; border-bottom: 1px solid #dee2e6; color: #333;">' . __('Adet', 'basit-kargo') . '</th>
                                <th style="padding: 10px; text-align: right; border-bottom: 1px solid #dee2e6; color: #333;">' . __('Tutar', 'basit-kargo') . '</th>
                            </tr>
                        </thead>
                        <tbody>';
        
        foreach ($order->get_items() as $item) {
            $template .= '
                            <tr>
                                <td style="padding: 10px; border-bottom: 1px solid #dee2e6; color: #333;">' . $item->get_name() . '</td>
                                <td style="padding: 10px; text-align: center; border-bottom: 1px solid #dee2e6; color: #333;">' . $item->get_quantity() . '</td>
                                <td style="padding: 10px; text-align: right; border-bottom: 1px solid #dee2e6; color: #333;">' . $order->get_formatted_line_subtotal($item) . '</td>
                            </tr>';
        }
        
        $template .= '
                        </tbody>
                    </table>
                </div>
                
                <!-- Footer -->
                <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; color: #666; font-size: 14px;">
                    <p style="margin: 0 0 10px 0;">
                        ' . __('Teşekkürler,', 'basit-kargo') . '<br>
                        <strong>' . get_bloginfo('name') . '</strong>
                    </p>
                    <p style="margin: 0; font-size: 12px;">
                        ' . __('Bu e-posta otomatik olarak gönderilmiştir. Lütfen yanıtlamayın.', 'basit-kargo') . '
                    </p>
                </div>
                
            </div>
        </body>
        </html>';
        
        return $template;
    }
    
    /**
     * Get plain text email template
     */
    private function getBarcodeEmailTemplatePlain($order, $barcode, $tracking_url) {
        $template = sprintf(
            __("Merhaba %s,\n\nSiparişiniz (#%s) kargoya verilmiştir.\n\nKargo Bilgileri:\nBarkod: %s\nTakip Linki: %s\n\nKargonuzu takip etmek için yukarıdaki linki kullanabilirsiniz.\nİade kodu oluşturmak için takip sayfasındaki 'İade Kodu Oluştur' butonunu kullanabilirsiniz.\n\nTeşekkürler,\n%s", 'basit-kargo'),
            $order->get_billing_first_name(),
            $order->get_order_number(),
            $barcode,
            $tracking_url,
            get_bloginfo('name')
        );
        
        return $template;
    }
    
    /**
     * Add custom email styles
     */
    public function addEmailStyles($styles) {
        $custom_styles = '
        .basit-kargo-email {
            font-family: Arial, sans-serif;
            max-width: 600px;
            margin: 0 auto;
        }
        .basit-kargo-header {
            background-color: #0073aa;
            color: #ffffff;
            padding: 20px;
            text-align: center;
        }
        .basit-kargo-content {
            padding: 20px;
            background-color: #ffffff;
        }
        .basit-kargo-cargo-info {
            background-color: #e7f3ff;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .basit-kargo-button {
            display: inline-block;
            background-color: #0073aa;
            color: #ffffff;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: bold;
        }
        ';
        
        return $styles . $custom_styles;
    }
    
    /**
     * Send test email
     */
    public function sendTestEmail($email) {
        $subject = __('Basit Kargo Test E-postası', 'basit-kargo');
        
        $message = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2 style="color: #0073aa;">' . __('Basit Kargo Test E-postası', 'basit-kargo') . '</h2>
            <p>' . __('Bu bir test e-postasıdır. E-posta sistemi düzgün çalışıyor.', 'basit-kargo') . '</p>
            <p>' . __('Gönderim Zamanı:', 'basit-kargo') . ' ' . current_time('Y-m-d H:i:s') . '</p>
        </div>';
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        return wp_mail($email, $subject, $message, $headers);
    }
    
    /**
     * Get email template for order status change
     */
    public function getOrderStatusEmailTemplate($order, $new_status) {
        $status_names = array(
            'shipped' => __('Kargoya Verildi', 'basit-kargo'),
            'cancelled' => __('İptal Edildi', 'basit-kargo'),
            'failed' => __('Başarısız', 'basit-kargo'),
            'refunded' => __('İade Edildi', 'basit-kargo')
        );
        
        $status_name = isset($status_names[$new_status]) ? $status_names[$new_status] : $new_status;
        
        $template = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2 style="color: #0073aa;">' . __('Sipariş Durumu Güncellendi', 'basit-kargo') . '</h2>
            <p>' . sprintf(__('Merhaba %s,', 'basit-kargo'), $order->get_billing_first_name()) . '</p>
            <p>' . sprintf(__('Siparişiniz (#%s) durumu "%s" olarak güncellenmiştir.', 'basit-kargo'), $order->get_order_number(), $status_name) . '</p>
            <p>' . __('Teşekkürler,', 'basit-kargo') . '<br>' . get_bloginfo('name') . '</p>
        </div>';
        
        return $template;
    }

    /**
     * Resolve Basit Kargo tracking URL to match the admin button logic.
     * Logic: api_id > reference. If both missing, try API sync once, then re-check.
     */
    private function resolveBkTrackingUrl($order) {
        $api_id   = $order->get_meta('basit_kargo_api_id');
        $reference= $order->get_meta('basit_kargo_reference');
        if (empty($api_id) && empty($reference)) {
            // Try to fetch from Basit Kargo once
            try {
                $api = new \BasitKargo\API();
                $api->fetchBarcodeData($order);
            } catch (\Throwable $e) {}
            $api_id   = $order->get_meta('basit_kargo_api_id');
            $reference= $order->get_meta('basit_kargo_reference');
        }
        $slug = !empty($api_id) ? $api_id : (!empty($reference) ? $reference : '');
        return $slug ? ('https://basitkargo.com/takip/' . $slug) : '';
    }
}
