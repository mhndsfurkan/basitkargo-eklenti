<?php
/**
 * Basit Kargo API Class
 * Handles all API communications with Basit Kargo service
 */

namespace BasitKargo;

if (!defined('ABSPATH')) {
    exit;
}

class API {
    
    private $api_url = 'https://basitkargo.com/api/v2';
    private $token;
    
    public function __construct() {
        $this->token = get_option('basit_kargo_token', '');
        $this->api_url = 'https://basitkargo.com/api/v2';
        $this->initHooks();
    }
    
    private function initHooks() {
        // AJAX handlers
        add_action('wp_ajax_basit_kargo_generate_barcode', array($this, 'generateBarcode'));
        add_action('wp_ajax_basit_kargo_sync_barcode', array($this, 'syncBarcode'));
        add_action('wp_ajax_basit_kargo_sync_tracking', array($this, 'syncTracking'));
        add_action('wp_ajax_basit_kargo_send_mail', array($this, 'sendMail'));
        add_action('wp_ajax_basit_kargo_sync_cancelled_failed_orders', array($this, 'syncCancelledFailedOrders'));
    }
    
    /**
     * Generate barcode for order
     */
    public function generateBarcode() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_die('Sipariş bulunamadı');
        }
        
        $result = $this->createBarcode($order);
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Create barcode via API
     */
    public function createBarcode($order, $override_code = null) {
        $payload = $this->prepareOrderPayload($order, $override_code);
        
        $response = wp_remote_post($this->api_url . '/order/barcode', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'API bağlantı hatası: ' . $response->get_error_message()
            );
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Debug: Log API response (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Basit Kargo API Response Code: ' . $response_code);
            error_log('Basit Kargo API Response Body: ' . $body);
        }
        
        if ($response_code === 200 && !empty($data) && isset($data['id'])) {
            // Store all barcode data from API response
            $order->update_meta_data('basit_kargo_barcode', $data['barcode']);
            $order->update_meta_data('basit_kargo_api_id', $data['id']);
            // Basit Kargo takip slug'ı (ör. 45V-4Y6-TSZ) bazı API cevaplarında "reference" alanında gelir
            $ref = isset($data['reference']) && $data['reference'] ? $data['reference'] : $data['id'];
            $order->update_meta_data('basit_kargo_reference', $ref);
            $order->update_meta_data('basit_kargo_status', $data['status']);
            $order->update_meta_data('basit_kargo_created_time', $data['createdTime']);
            $order->update_meta_data('basit_kargo_updated_time', $data['updatedTime']);
            
            // Store shipment info
            if (isset($data['shipmentInfo']['handler'])) {
                $handler = $data['shipmentInfo']['handler'];
                $order->update_meta_data('basit_kargo_handler_name', $handler['name']);
                $order->update_meta_data('basit_kargo_handler_code', $handler['code']);
                if (!empty($handler['handlerShipmentCode'])) {
                    $order->update_meta_data('basit_kargo_handler_shipment_code', $handler['handlerShipmentCode']);
                }
                if (!empty($handler['handlerShipmentTrackingLink'])) {
                    $order->update_meta_data('basit_kargo_handler_tracking_link', $handler['handlerShipmentTrackingLink']);
                }
                if (!empty($handler['shippedTime'])) {
                    $order->update_meta_data('basit_kargo_shipped_time', $handler['shippedTime']);
                }
                if (!empty($handler['deliveredTime'])) {
                    $order->update_meta_data('basit_kargo_delivered_time', $handler['deliveredTime']);
                }
            }
            
            // Store price info
            if (isset($data['priceInfo'])) {
                $priceInfo = $data['priceInfo'];
                $order->update_meta_data('basit_kargo_shipment_fee', $priceInfo['shipmentFee']);
                $order->update_meta_data('basit_kargo_total_cost', $priceInfo['totalCost']);
            }
            
            // Persist immediately so UI/metabox sees latest barcode
            $order->save();
            // Fire event for other systems (emails, etc.)
            do_action('basit_kargo_barcode_generated', $order->get_id(), $data['barcode']);
            
            // Auto update order status if enabled AND a real carrier tracking code exists
            if (get_option('basit_kargo_auto_update_status') === 'yes') {
                $carrier_shipment_code = $order->get_meta('basit_kargo_handler_shipment_code');
                if (!empty($carrier_shipment_code)) {
                    $order->update_status(\BasitKargo\Admin::getShippedStatusKey(), 'Kargo takip kodu oluştu - Kargoya Verildi');
                } else {
                    // Add an informative note instead of changing status
                    $order->add_order_note(__('Barkod oluşturuldu. Kargo takip kodu geldiğinde durum otomatik "Kargoya Verildi" yapılacak.', 'basit-kargo'));
                }
            }
            
            return array(
                'success' => true,
                'data' => $data
            );
        } else {
            // Add diagnostic note
            $order->add_order_note('Basit Kargo barkod oluşturma HATA [' . $response_code . ']: ' . substr($body, 0, 300));
            // Retry once with a unique foreign code if the default code is rejected
            $alt_code = $override_code ? ($override_code . '-r') : ($order->get_order_number() . '-' . substr((string) time(), -4));
            $retry_payload = $this->prepareOrderPayload($order, $alt_code);
            $retry_response = wp_remote_post($this->api_url . '/order/barcode', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($retry_payload),
                'timeout' => 30
            ));
            if (!is_wp_error($retry_response)) {
                $retry_code = wp_remote_retrieve_response_code($retry_response);
                $retry_body = wp_remote_retrieve_body($retry_response);
                $retry_data = json_decode($retry_body, true);
                if ($retry_code === 200 && !empty($retry_data) && isset($retry_data['id'])) {
                    $order->update_meta_data('basit_kargo_barcode', $retry_data['barcode']);
                    $order->update_meta_data('basit_kargo_api_id', $retry_data['id']);
                    $ref2 = isset($retry_data['reference']) && $retry_data['reference'] ? $retry_data['reference'] : $alt_code;
                    $order->update_meta_data('basit_kargo_reference', $ref2);
                    if (isset($retry_data['status'])) {
                        $order->update_meta_data('basit_kargo_status', $retry_data['status']);
                    }
                    $order->save();
                    do_action('basit_kargo_barcode_generated', $order->get_id(), $retry_data['barcode']);
                    return array('success' => true, 'data' => $retry_data);
                } else {
                    $order->add_order_note('Basit Kargo barkod yeniden deneme HATA [' . $retry_code . ']: ' . substr($retry_body, 0, 300));
                }
            }
            return array(
                'success' => false,
                'message' => isset($data['message']) ? $data['message'] : 'Barkod oluşturulamadı'
            );
        }
    }
    
    /**
     * Prepare order payload for API
     */
    private function prepareOrderPayload($order, $override_code = null) {
        $billing = $order->get_address('billing');
        $shipping = $order->get_address('shipping');
        
        // Build items; ensure at least one item exists
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = array(
                'name' => $item->get_name(),
                'quantity' => max(1, (int) $item->get_quantity()),
                'price' => (float) $item->get_total()
            );
        }
        if (empty($items)) {
            $items[] = array(
                'name' => 'Sipariş #' . $order->get_order_number(),
                'quantity' => 1,
                'price' => (float) $order->get_total()
            );
        }
        
        // Determine city/town and phone
        $states = function_exists('WC') ? WC()->countries->get_states('TR') : array();
        $city_name = isset($states[$shipping['state']]) && !empty($states[$shipping['state']])
            ? $states[$shipping['state']]
            : (!empty($shipping['city']) ? $shipping['city'] : 'İstanbul');
        $town_name = !empty($shipping['city']) ? $shipping['city'] : 'Merkez';
        $raw_phone = !empty($shipping['phone']) ? $shipping['phone'] : (isset($billing['phone']) ? $billing['phone'] : '');
        $digits_phone = preg_replace('/\D+/', '', (string) $raw_phone);
        if (strlen($digits_phone) > 10 && substr($digits_phone, 0, 2) === '90') {
            $digits_phone = substr($digits_phone, 2);
        }
        if (strlen($digits_phone) < 10) {
            // Fallback güvenli numara (API zorunlu alanı için)
            $digits_phone = '5555555555';
        }
        
        return array(
            'handlerCode' => get_option('basit_kargo_handler_code', 'SURAT'),
            'content' => array(
                'name' => 'Sipariş #' . $order->get_order_number(),
                'code' => $override_code ? $override_code : $order->get_order_number(),
                'items' => $items,
                'packages' => array(
                    array(
                        'height' => 10,
                        'width' => 15,
                        'depth' => 5,
                        'weight' => 1
                    )
                )
            ),
            'client' => array(
                'name' => trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? '')),
                'phone' => $digits_phone,
                'city' => $city_name,
                'town' => $town_name,
                'address' => trim(($shipping['address_1'] ?? '') . ' ' . ($shipping['address_2'] ?? ''))
            ),
            'collect' => 0,
            'collectOnDeliveryType' => 'CASH'
        );
    }
    
    /**
     * Sync barcode from remote
     */
    public function syncBarcode() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_die('Sipariş bulunamadı');
        }
        
        $result = $this->fetchBarcodeData($order);
        
        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Find WooCommerce orders that might be in Basit Kargo
     */
    public function findPotentialBasitKargoOrders($limit = 50) {
        // Find orders with Basit Kargo meta data but missing some info
        $orders = wc_get_orders(array(
            'limit' => $limit,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => 'basit_kargo_barcode',
                    'compare' => 'EXISTS'
                ),
                array(
                    'key' => 'basit_kargo_handler_code',
                    'compare' => 'EXISTS'
                )
            ),
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        $potential_orders = array();
        
        foreach ($orders as $order) {
            $barcode = $order->get_meta('basit_kargo_barcode');
            $api_id = $order->get_meta('basit_kargo_api_id');
            $handler_name = $order->get_meta('basit_kargo_handler_name');
            
            // If we have barcode but missing handler name, try to sync
            if ($barcode && !$handler_name) {
                // Check if barcode is valid format (not old system)
                if (preg_match('/^[0-9]+$/', $barcode) || preg_match('/^BK-[0-9]+$/', $barcode)) {
                    $potential_orders[] = array(
                        'order' => $order,
                        'barcode' => $barcode,
                        'api_id' => $api_id,
                        'search_type' => 'barcode'
                    );
                }
            }
        }
        
        return $potential_orders;
    }
    
    /**
     * Find orders by order number that might be in Basit Kargo
     */
    public function findOrdersByNumber($order_numbers) {
        $potential_orders = array();
        
        foreach ($order_numbers as $order_number) {
            $order = wc_get_order($order_number);
            if ($order) {
                $barcode = $order->get_meta('basit_kargo_barcode');
                $api_id = $order->get_meta('basit_kargo_api_id');
                $handler_name = $order->get_meta('basit_kargo_handler_name');
                
                // If no Basit Kargo data exists, try to find in API
                if (!$barcode && !$api_id && !$handler_name) {
                    $potential_orders[] = array(
                        'order' => $order,
                        'barcode' => null,
                        'api_id' => null,
                        'search_type' => 'order_number',
                        'order_number' => $order->get_order_number()
                    );
                }
            }
        }
        
        return $potential_orders;
    }
    
    /**
     * Find orders by customer name that might be in Basit Kargo
     */
    public function findOrdersByName($customer_name) {
        $potential_orders = array();
        
        // Search for orders with similar customer names
        $orders = wc_get_orders(array(
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        foreach ($orders as $order) {
            $barcode = $order->get_meta('basit_kargo_barcode');
            $api_id = $order->get_meta('basit_kargo_api_id');
            $handler_name = $order->get_meta('basit_kargo_handler_name');
            
            // If no Basit Kargo data exists, check if name matches
            if (!$barcode && !$api_id && !$handler_name) {
                $order_customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                
                // Use our name matching algorithm
                $normalized_search = $this->normalizeName($customer_name);
                $normalized_order = $this->normalizeName($order_customer_name);
                
                if ($this->isNameSimilar($normalized_search, $normalized_order)) {
                    $potential_orders[] = array(
                        'order' => $order,
                        'barcode' => null,
                        'api_id' => null,
                        'search_type' => 'customer_name',
                        'customer_name' => $customer_name,
                        'match_score' => $this->calculateNameSimilarity($normalized_search, $normalized_order)
                    );
                }
            }
        }
        
        // Sort by match score (highest first)
        usort($potential_orders, function($a, $b) {
            return $b['match_score'] - $a['match_score'];
        });
        
        return $potential_orders;
    }
    
    /**
     * Calculate name similarity score (0-100)
     */
    private function calculateNameSimilarity($name1, $name2) {
        if ($name1 === $name2) {
            return 100;
        }
        
        $distance = levenshtein($name1, $name2);
        $max_length = max(strlen($name1), strlen($name2));
        
        if ($max_length == 0) {
            return 0;
        }
        
        $similarity = (1 - ($distance / $max_length)) * 100;
        return (int)$similarity;
    }
    
    /**
     * Sync completed orders with WooCommerce
     */
    public function syncCompletedOrders($limit = 50) {
        $potential_orders = $this->findPotentialBasitKargoOrders($limit);
        $synced_count = 0;
        $errors = array();
        
        foreach ($potential_orders as $potential_order) {
            $order = $potential_order['order'];
            $barcode = $potential_order['barcode'];
            $api_id = $potential_order['api_id'];
            $search_type = $potential_order['search_type'];
            $order_number = isset($potential_order['order_number']) ? $potential_order['order_number'] : null;
            
            // Try to fetch updated data from Basit Kargo
            $sync_result = $this->syncOrderFromBasitKargo($order, $barcode, $api_id, $search_type, $order_number);
            
            if ($sync_result['success']) {
                $synced_count++;
            } else {
                $errors[] = 'Sipariş ' . $order->get_id() . ': ' . $sync_result['message'];
            }
        }
        
        $message = sprintf(
            __('%d sipariş bulundu, %d sipariş senkronize edildi.', 'basit-kargo'),
            count($potential_orders),
            $synced_count
        );
        
        if (!empty($errors)) {
            $message .= ' Hatalar: ' . implode(', ', $errors);
        }
        
        return array(
            'success' => true,
            'synced_count' => $synced_count,
            'total_found' => count($potential_orders),
            'errors' => $errors,
            'message' => $message
        );
    }
    
    /**
     * Sync individual order from Basit Kargo
     */
    private function syncOrderFromBasitKargo($order, $barcode, $api_id, $search_type = 'barcode', $order_number = null, $customer_name = null) {
        try {
            $response = null;
            
            if ($search_type === 'barcode' && $barcode) {
                // Try to get order data by barcode
                $response = wp_remote_get($this->api_url . '/order/barcode/' . $barcode, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $this->token
                    ),
                    'timeout' => 30
                ));
            } elseif ($search_type === 'order_number' && $order_number) {
                // Try to get order data by order number
                $response = wp_remote_get($this->api_url . '/order/search?order_number=' . $order_number, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $this->token
                    ),
                    'timeout' => 30
                ));
            } elseif ($search_type === 'customer_name' && $customer_name) {
                // Try to get order data by customer name
                $response = wp_remote_get($this->api_url . '/order/search?customer_name=' . urlencode($customer_name), array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $this->token
                    ),
                    'timeout' => 30
                ));
                
                // Also try recipient_name parameter
                if (!$response || is_wp_error($response)) {
                    $response = wp_remote_get($this->api_url . '/order/search?recipient_name=' . urlencode($customer_name), array(
                        'headers' => array(
                            'Authorization' => 'Bearer ' . $this->token
                        ),
                        'timeout' => 30
                    ));
                }
            }
            
            if (!$response || is_wp_error($response)) {
                return array('success' => false, 'message' => $response ? $response->get_error_message() : 'Geçersiz arama türü');
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (!empty($data) && isset($data['barcode'])) {
                // New API format - direct data
                $order_data = $data;
                
                // Update order meta with fresh data
                $this->updateOrderMetaFromBasitKargo($order, $order_data);
                
                return array('success' => true, 'message' => 'Sipariş senkronize edildi');
            } elseif (isset($data['success']) && $data['success'] && !empty($data['data'])) {
                // Old API format - wrapped data
                $order_data = null;
                
                if ($search_type === 'barcode' && !empty($data['data'])) {
                    $order_data = $data['data'];
                } elseif ($search_type === 'order_number' && !empty($data['data'])) {
                    $order_data = $data['data'][0]; // Take first result
                }
                
                if ($order_data) {
                    // Update order meta with fresh data
                    $this->updateOrderMetaFromBasitKargo($order, $order_data);
                    
                    return array('success' => true, 'message' => 'Sipariş senkronize edildi');
                }
            }
            
            // More detailed error message
            $error_message = 'Sipariş Basit Kargo\'da bulunamadı';
            if (isset($data['message'])) {
                $error_message .= ': ' . $data['message'];
            } elseif (isset($data['error'])) {
                $error_message .= ': ' . $data['error'];
            }
            
            return array('success' => false, 'message' => $error_message);
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Update order meta from Basit Kargo data
     */
    private function updateOrderMetaFromBasitKargo($order, $order_data) {
        // Update basic info
        if (isset($order_data['barcode'])) {
            $order->update_meta_data('basit_kargo_barcode', $order_data['barcode']);
        }
        if (isset($order_data['id'])) {
            $order->update_meta_data('basit_kargo_api_id', $order_data['id']);
        }
        if (isset($order_data['foreignCode'])) {
            $order->update_meta_data('basit_kargo_reference', $order_data['foreignCode']);
        }
        
        // Map API status to our format
        if (isset($order_data['status'])) {
            $api_status = strtolower($order_data['status']);
            $mapped_status = '';
            switch($api_status) {
                case 'completed':
                case 'delivered':
                    $mapped_status = 'teslim_edildi';
                    break;
                case 'shipped':
                case 'in_transit':
                    $mapped_status = 'kargoya_verildi';
                    break;
                default:
                    $mapped_status = $api_status;
            }
            $order->update_meta_data('basit_kargo_status', $mapped_status);
            // Reflect to Woo status & notify (Hazerfen-style non-prefixed keys)
            if ($mapped_status === 'kargoya_verildi' && $order->get_status() !== \BasitKargo\Admin::getShippedStatusKey()) {
                $order->update_status(\BasitKargo\Admin::getShippedStatusKey(), 'Basit Kargo: Kargoya verildi');
            }
            if ($mapped_status === 'teslim_edildi' && $order->get_status() !== \BasitKargo\Admin::getDeliveredStatusKey()) {
                $order->update_status(\BasitKargo\Admin::getDeliveredStatusKey(), 'Basit Kargo: Teslim edildi');
                // send delivered email (guarded by option inside email class)
                $email = new \BasitKargo\Email();
                $sent = $email->sendDeliveredEmail($order);
                if ($sent) {
                    $order->update_meta_data('basit_kargo_delivered_mail_sent', current_time('mysql'));
                    $order->save();
                }
            }
        }
        
        // Update handler info (new API format)
        if (isset($order_data['shipmentInfo']['handler'])) {
            $handler = $order_data['shipmentInfo']['handler'];
            if (isset($handler['name'])) {
                $order->update_meta_data('basit_kargo_handler_name', $handler['name']);
            }
            if (isset($handler['code'])) {
                $order->update_meta_data('basit_kargo_handler_code', $handler['code']);
            }
        }
        
        if (isset($order_data['shipmentInfo']['handlerShipmentCode'])) {
            $previous_code = $order->get_meta('basit_kargo_handler_shipment_code');
            $new_code = $order_data['shipmentInfo']['handlerShipmentCode'];
            $order->update_meta_data('basit_kargo_handler_shipment_code', $new_code);
            // If first time we get a real shipment code, mark as shipped and email
            if (empty($previous_code) && !empty($new_code)) {
                if ($order->get_status() !== \BasitKargo\Admin::getShippedStatusKey()) {
                    $order->update_status(\BasitKargo\Admin::getShippedStatusKey(), 'Kargo takip kodu oluşturuldu - Kargoya verildi');
                }
                if (get_option('basit_kargo_auto_send_email') === 'yes' && !$order->get_meta('basit_kargo_tracking_mail_sent')) {
                    $email = new \BasitKargo\Email();
                    $email->sendTrackingEmail($order);
                    $order->update_meta_data('basit_kargo_tracking_mail_sent', current_time('mysql'));
                }
            }
        }
        
        if (isset($order_data['shipmentInfo']['handlerShipmentTrackingLink'])) {
            $order->update_meta_data('basit_kargo_handler_tracking_link', $order_data['shipmentInfo']['handlerShipmentTrackingLink']);
        }
        
        // Update price info
        if (isset($order_data['priceInfo']['shipmentFee'])) {
            $order->update_meta_data('basit_kargo_shipment_fee', $order_data['priceInfo']['shipmentFee']);
        }
        
        if (isset($order_data['priceInfo']['totalCost'])) {
            $order->update_meta_data('basit_kargo_total_cost', $order_data['priceInfo']['totalCost']);
        }
        
        // Update timestamps
        if (isset($order_data['createdTime'])) {
            $order->update_meta_data('basit_kargo_created_time', $order_data['createdTime']);
        }
        
        if (isset($order_data['updatedTime'])) {
            $order->update_meta_data('basit_kargo_updated_time', $order_data['updatedTime']);
        }
        
        if (isset($order_data['shipmentInfo']['shippedTime'])) {
            $order->update_meta_data('basit_kargo_shipped_time', $order_data['shipmentInfo']['shippedTime']);
        }
        
        if (isset($order_data['shipmentInfo']['deliveredTime'])) {
            $order->update_meta_data('basit_kargo_delivered_time', $order_data['shipmentInfo']['deliveredTime']);
        }
        
        // Update timing info
        if (isset($order_data['createdTime'])) {
            $order->update_meta_data('basit_kargo_created_time', $order_data['createdTime']);
        }
        if (isset($order_data['updatedTime'])) {
            $order->update_meta_data('basit_kargo_updated_time', $order_data['updatedTime']);
        }
        if (isset($order_data['shippedTime'])) {
            $order->update_meta_data('basit_kargo_shipped_time', $order_data['shippedTime']);
        }
        if (isset($order_data['deliveredTime'])) {
            $order->update_meta_data('basit_kargo_delivered_time', $order_data['deliveredTime']);
        }
        
        // Update cost info
        if (isset($order_data['shipmentFee'])) {
            $order->update_meta_data('basit_kargo_shipment_fee', $order_data['shipmentFee']);
        }
        if (isset($order_data['totalCost'])) {
            $order->update_meta_data('basit_kargo_total_cost', $order_data['totalCost']);
        }
        
        $order->save();
    }
    
    /**
     * Find matching WooCommerce order
     */
    private function findMatchingWooCommerceOrder($completed_order) {
        // Try to find by order number
        if (isset($completed_order['order_number'])) {
            $orders = wc_get_orders(array(
                'meta_key' => '_order_number',
                'meta_value' => $completed_order['order_number'],
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        // Try to find by barcode
        if (isset($completed_order['barcode'])) {
            $orders = wc_get_orders(array(
                'meta_key' => 'basit_kargo_barcode',
                'meta_value' => $completed_order['barcode'],
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        // Try to find by API ID
        if (isset($completed_order['id'])) {
            $orders = wc_get_orders(array(
                'meta_key' => 'basit_kargo_api_id',
                'meta_value' => $completed_order['id'],
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        return null;
    }
    
    /**
     * Sync completed order data
     */
    private function syncCompletedOrderData($wc_order, $completed_order) {
        try {
            // Update basic info
            if (isset($completed_order['barcode'])) {
                $wc_order->update_meta_data('basit_kargo_barcode', $completed_order['barcode']);
            }
            if (isset($completed_order['id'])) {
                $wc_order->update_meta_data('basit_kargo_api_id', $completed_order['id']);
            }
            if (isset($completed_order['reference'])) {
                $wc_order->update_meta_data('basit_kargo_reference', $completed_order['reference']);
            }
            if (isset($completed_order['status'])) {
                $wc_order->update_meta_data('basit_kargo_status', $completed_order['status']);
            }
            
            // Update handler info
            if (isset($completed_order['handler'])) {
                $handler = $completed_order['handler'];
                if (isset($handler['name'])) {
                    $wc_order->update_meta_data('basit_kargo_handler_name', $handler['name']);
                }
                if (isset($handler['code'])) {
                    $wc_order->update_meta_data('basit_kargo_handler_code', $handler['code']);
                }
                if (isset($handler['shipmentCode'])) {
                    $wc_order->update_meta_data('basit_kargo_handler_shipment_code', $handler['shipmentCode']);
                }
                if (isset($handler['trackingLink'])) {
                    $wc_order->update_meta_data('basit_kargo_handler_tracking_link', $handler['trackingLink']);
                }
            }
            
            // Update timing info
            if (isset($completed_order['createdTime'])) {
                $wc_order->update_meta_data('basit_kargo_created_time', $completed_order['createdTime']);
            }
            if (isset($completed_order['updatedTime'])) {
                $wc_order->update_meta_data('basit_kargo_updated_time', $completed_order['updatedTime']);
            }
            if (isset($completed_order['shippedTime'])) {
                $wc_order->update_meta_data('basit_kargo_shipped_time', $completed_order['shippedTime']);
            }
            if (isset($completed_order['deliveredTime'])) {
                $wc_order->update_meta_data('basit_kargo_delivered_time', $completed_order['deliveredTime']);
            }
            
            // Update cost info
            if (isset($completed_order['shipmentFee'])) {
                $wc_order->update_meta_data('basit_kargo_shipment_fee', $completed_order['shipmentFee']);
            }
            if (isset($completed_order['totalCost'])) {
                $wc_order->update_meta_data('basit_kargo_total_cost', $completed_order['totalCost']);
            }
            
            $wc_order->save();
            
            return array('success' => true, 'message' => 'Sipariş senkronize edildi');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Fetch barcode data from API
     */
    public function fetchBarcodeData($order) {
        $api_id = $order->get_meta('basit_kargo_api_id');
        $barcode = $order->get_meta('basit_kargo_barcode');
        $order_number = $order->get_order_number();
        
        // Try multiple search strategies using correct API endpoints
        $search_urls = array();
        
        if ($api_id) {
            $search_urls[] = $this->api_url . '/order/' . $api_id;
        }
        if ($barcode) {
            $search_urls[] = $this->api_url . '/order/barcode/' . $barcode;
        }
        // If no barcode or API ID, try searching by order details
        if (!$api_id && !$barcode) {
            // Try different search approaches
            if ($order_number) {
                $search_urls[] = $this->api_url . '/order/search?order_number=' . $order_number;
                $search_urls[] = $this->api_url . '/order/search?reference=' . $order_number;
                $search_urls[] = $this->api_url . '/order/search?foreignCode=' . $order_number;
            }
            
            // Try searching by customer details with enhanced name matching
            $billing_first_name = $order->get_billing_first_name();
            $billing_last_name = $order->get_billing_last_name();
            $billing_phone = $order->get_billing_phone();
            $billing_email = $order->get_billing_email();
            $order_date = $order->get_date_created()->format('Y-m-d');
            
            if ($billing_first_name && $billing_last_name) {
                $customer_name = $billing_first_name . ' ' . $billing_last_name;
                
                // Full name search
                $search_urls[] = $this->api_url . '/order/search?customer_name=' . urlencode($customer_name);
                $search_urls[] = $this->api_url . '/order/search?recipient_name=' . urlencode($customer_name);
                
                // First name only search
                $search_urls[] = $this->api_url . '/order/search?customer_name=' . urlencode($billing_first_name);
                $search_urls[] = $this->api_url . '/order/search?recipient_name=' . urlencode($billing_first_name);
                
                // Last name only search
                $search_urls[] = $this->api_url . '/order/search?customer_name=' . urlencode($billing_last_name);
                $search_urls[] = $this->api_url . '/order/search?recipient_name=' . urlencode($billing_last_name);
                
                // Reverse name order (last name first)
                $reverse_name = $billing_last_name . ' ' . $billing_first_name;
                $search_urls[] = $this->api_url . '/order/search?customer_name=' . urlencode($reverse_name);
                $search_urls[] = $this->api_url . '/order/search?recipient_name=' . urlencode($reverse_name);
            }
            
            if ($billing_phone) {
                $search_urls[] = $this->api_url . '/order/search?phone=' . urlencode($billing_phone);
                $search_urls[] = $this->api_url . '/order/search?recipient_phone=' . urlencode($billing_phone);
            }
            
            if ($billing_email) {
                $search_urls[] = $this->api_url . '/order/search?email=' . urlencode($billing_email);
            }
            
            if ($order_date) {
                $search_urls[] = $this->api_url . '/order/search?date=' . $order_date;
                $search_urls[] = $this->api_url . '/order/search?created_date=' . $order_date;
            }
        }
        
        foreach ($search_urls as $url) {
            // Debug logging
            error_log('Basit Kargo API Trying URL: ' . $url);
            
            $response = wp_remote_get($url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->token
                ),
                'timeout' => 30
            ));
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                // Debug logging
                error_log('Basit Kargo API Response for URL: ' . $url);
                error_log('Response Code: ' . wp_remote_retrieve_response_code($response));
                error_log('Response Body: ' . substr($body, 0, 500));
                
                // Handle single order response
                if (!empty($data) && isset($data['barcode'])) {
                    $order_data = $data;
                    $this->updateOrderMetaFromBasitKargo($order, $order_data);
                    $order->save();
                    
                    return array(
                        'success' => true,
                        'data' => $order_data
                    );
                }
                
                // Handle search results array
                if (!empty($data) && is_array($data) && isset($data[0]['barcode'])) {
                    // Find the best match from search results
                    $best_match = $this->findBestOrderMatch($order, $data);
                    if ($best_match) {
                        $this->updateOrderMetaFromBasitKargo($order, $best_match);
                        $order->save();
                        
                        return array(
                            'success' => true,
                            'data' => $best_match
                        );
                    }
                }
            }
        }
        
        return array(
            'success' => false,
            'message' => 'Barkod bilgisi bulunamadı'
        );
    }
    
    /**
     * Find best matching order from search results
     */
    private function findBestOrderMatch($order, $search_results) {
        $order_number = $order->get_order_number();
        $billing_first_name = $order->get_billing_first_name();
        $billing_last_name = $order->get_billing_last_name();
        $billing_phone = $order->get_billing_phone();
        $billing_email = $order->get_billing_email();
        $order_date = $order->get_date_created()->format('Y-m-d');
        
        $best_match = null;
        $best_score = 0;
        
        foreach ($search_results as $result) {
            $score = 0;
            
            // Check order number match
            if (isset($result['foreignCode']) && $result['foreignCode'] == $order_number) {
                $score += 100; // Highest priority
            }
            
            // Enhanced customer name matching
            $name_score = $this->calculateNameMatchScore($order, $result);
            $score += $name_score;
            
            // Check phone match
            if (isset($result['recipientPhone']) && $result['recipientPhone'] == $billing_phone) {
                $score += 30;
            }
            
            // Check email match
            if (isset($result['recipientEmail']) && $result['recipientEmail'] == $billing_email) {
                $score += 20;
            }
            
            // Check date match (within 7 days)
            if (isset($result['createdTime'])) {
                $result_date = date('Y-m-d', strtotime($result['createdTime']));
                if ($result_date == $order_date) {
                    $score += 10;
                } elseif (abs(strtotime($result_date) - strtotime($order_date)) <= 7 * 24 * 60 * 60) {
                    $score += 5;
                }
            }
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_match = $result;
            }
        }
        
        // Only return match if score is high enough
        return ($best_score >= 50) ? $best_match : null;
    }
    
    /**
     * Calculate name matching score with advanced algorithms
     */
    private function calculateNameMatchScore($order, $result) {
        $billing_first_name = $order->get_billing_first_name();
        $billing_last_name = $order->get_billing_last_name();
        $score = 0;
        
        // Get recipient name from different possible fields
        $recipient_name = '';
        if (isset($result['recipientName'])) {
            $recipient_name = $result['recipientName'];
        } elseif (isset($result['recipient']['name'])) {
            $recipient_name = $result['recipient']['name'];
        } elseif (isset($result['client']['name'])) {
            $recipient_name = $result['client']['name'];
        }
        
        if (empty($recipient_name)) {
            return 0;
        }
        
        // Normalize names for comparison
        $wc_full_name = $this->normalizeName($billing_first_name . ' ' . $billing_last_name);
        $bk_full_name = $this->normalizeName($recipient_name);
        
        // Exact match
        if ($wc_full_name === $bk_full_name) {
            $score += 80; // Very high score for exact match
        } else {
            // Split names into parts
            $wc_name_parts = explode(' ', $wc_full_name);
            $bk_name_parts = explode(' ', $bk_full_name);
            
            // Check for partial matches
            $matched_parts = 0;
            $total_parts = max(count($wc_name_parts), count($bk_name_parts));
            
            foreach ($wc_name_parts as $wc_part) {
                foreach ($bk_name_parts as $bk_part) {
                    if ($this->isNameSimilar($wc_part, $bk_part)) {
                        $matched_parts++;
                        break;
                    }
                }
            }
            
            // Calculate score based on matched parts
            if ($matched_parts > 0) {
                $match_ratio = $matched_parts / $total_parts;
                $score += (int)(50 * $match_ratio); // Up to 50 points for partial match
            }
            
            // Bonus for first name match (most important)
            if (count($wc_name_parts) > 0 && count($bk_name_parts) > 0) {
                if ($this->isNameSimilar($wc_name_parts[0], $bk_name_parts[0])) {
                    $score += 20; // Bonus for first name match
                }
            }
            
            // Bonus for last name match
            if (count($wc_name_parts) > 1 && count($bk_name_parts) > 1) {
                if ($this->isNameSimilar(end($wc_name_parts), end($bk_name_parts))) {
                    $score += 15; // Bonus for last name match
                }
            }
        }
        
        return $score;
    }
    
    /**
     * Normalize name for comparison
     */
    private function normalizeName($name) {
        // Convert to lowercase
        $name = strtolower(trim($name));
        
        // Remove extra spaces
        $name = preg_replace('/\s+/', ' ', $name);
        
        // Remove common Turkish characters variations
        $replacements = array(
            'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
            'Ç' => 'c', 'Ğ' => 'g', 'İ' => 'i', 'Ö' => 'o', 'Ş' => 's', 'Ü' => 'u'
        );
        
        $name = strtr($name, $replacements);
        
        return $name;
    }
    
    /**
     * Check if two name parts are similar
     */
    private function isNameSimilar($name1, $name2) {
        $name1 = $this->normalizeName($name1);
        $name2 = $this->normalizeName($name2);
        
        // Exact match
        if ($name1 === $name2) {
            return true;
        }
        
        // Check if one contains the other (for nicknames/short forms)
        if (strlen($name1) >= 3 && strlen($name2) >= 3) {
            if (strpos($name1, $name2) !== false || strpos($name2, $name1) !== false) {
                return true;
            }
        }
        
        // Check similarity using Levenshtein distance
        $distance = levenshtein($name1, $name2);
        $max_length = max(strlen($name1), strlen($name2));
        
        // If names are similar enough (80% similarity)
        if ($max_length > 0 && ($distance / $max_length) <= 0.2) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Sync tracking information
     */
    public function syncTracking() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_die('Sipariş bulunamadı');
        }
        
        $barcode = $order->get_meta('basit_kargo_barcode');
        if (!$barcode) {
            wp_send_json_error('Barkod bulunamadı');
        }
        
        $response = wp_remote_get($this->api_url . '/tracking/' . $barcode, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('API bağlantı hatası: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['success']) && $data['success']) {
            // Update tracking info
            $order->update_meta_data('basit_kargo_tracking_info', $data['data']);
            $order->save();
            
            wp_send_json_success($data['data']);
        } else {
            wp_send_json_error(isset($data['message']) ? $data['message'] : 'Takip bilgisi alınamadı');
        }
    }
    
    /**
     * Send email to customer
     */
    public function sendMail() {
        check_ajax_referer('basit_kargo_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_die('Sipariş bulunamadı');
        }
        
        $result = $this->sendCustomerEmail($order);
        
        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Sync single order from Basit Kargo
     */
    public function syncOrder($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return array('success' => false, 'message' => 'Sipariş bulunamadı');
        }
        
        $barcode = $order->get_meta('basit_kargo_barcode');
        if (!$barcode) {
            return array('success' => false, 'message' => 'Barkod bulunamadı');
        }
        
        // Try to get order data by barcode
        $response = wp_remote_get($this->api_url . '/order/barcode/' . $barcode, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'API bağlantı hatası: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['success']) && $data['success'] && isset($data['data'])) {
            // Update order meta from Basit Kargo data
            $this->updateOrderMetaFromBasitKargo($order, $data['data']);
            $order->save();
            
            return array('success' => true, 'message' => 'Sipariş senkronize edildi');
        } else {
            return array('success' => false, 'message' => isset($data['message']) ? $data['message'] : 'Sipariş verisi alınamadı');
        }
    }
    
    /**
     * Send customer notification email
     */
    private function sendCustomerEmail($order) {
        $barcode = $order->get_meta('basit_kargo_barcode');
        if (!$barcode) {
            return array(
                'success' => false,
                'message' => 'Barkod bulunamadı'
            );
        }
        
        $tracking_url = 'https://basitkargo.com/takip/' . $barcode;
        
        $subject = 'Siparişiniz Kargoya Verildi - ' . $order->get_order_number();
        
        $message = $this->getEmailTemplate($order, $tracking_url);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($order->get_billing_email(), $subject, $message, $headers);
        
        if ($sent) {
            $order->add_order_note('Müşteriye kargo bilgilendirme maili gönderildi');
            return array(
                'success' => true,
                'message' => 'Mail başarıyla gönderildi'
            );
        } else {
            return array(
                'success' => false,
                'message' => 'Mail gönderilemedi'
            );
        }
    }
    
    /**
     * Get email template
     */
    private function getEmailTemplate($order, $tracking_url) {
        $barcode = $order->get_meta('basit_kargo_barcode');
        
        return '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
            <h2>Siparişiniz Kargoya Verildi</h2>
            <p>Merhaba ' . $order->get_billing_first_name() . ',</p>
            <p>Siparişiniz (#' . $order->get_order_number() . ') kargoya verilmiştir.</p>
            
            <div style="background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px;">
                <h3>Kargo Bilgileri</h3>
                <p><strong>Barkod:</strong> ' . $barcode . '</p>
                <p><strong>Takip Linki:</strong> <a href="' . $tracking_url . '">' . $tracking_url . '</a></p>
            </div>
            
            <p>Kargonuzu takip etmek için yukarıdaki linki kullanabilirsiniz.</p>
            <p>İade kodu oluşturmak için takip sayfasındaki "İade Kodu Oluştur" butonunu kullanabilirsiniz.</p>
            
            <p>Teşekkürler,<br>Basit Kargo</p>
        </div>';
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
            $result = $this->handleCancelledFailedOrder($order);
            if ($result['success']) {
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
     * Handle cancelled/failed order
     */
    private function handleCancelledFailedOrder($order) {
        $barcode = $order->get_meta('basit_kargo_barcode');
        
        if ($barcode) {
            // Update existing order status
            return $this->updateOrderStatus($order, $order->get_status());
        } else {
            // Create new cancelled/failed entry
            return $this->createCancelledFailedEntry($order, $order->get_status());
        }
    }
    
    /**
     * Update order status in Basit Kargo
     */
    private function updateOrderStatus($order, $status) {
        $payload = array(
            'order_id' => $order->get_id(),
            'status' => $this->getStatusText($status),
            'note' => 'Durum güncellendi: ' . $status
        );
        
        $response = wp_remote_post($this->api_url . '/order/status-update', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'API bağlantı hatası: ' . $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['success']) && $data['success']) {
            $order->add_order_note('Basit Kargo durumu güncellendi: ' . $status);
            return array('success' => true);
        } else {
            return array(
                'success' => false,
                'message' => isset($data['message']) ? $data['message'] : 'Durum güncellenemedi'
            );
        }
    }
    
    /**
     * Create cancelled/failed entry
     */
    private function createCancelledFailedEntry($order, $status) {
        $payload = array(
            'order_id' => $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $this->getStatusText($status),
            'reason' => 'Sipariş ' . $status . ' durumuna geçti',
            'note' => 'Otomatik senkronizasyon'
        );
        
        $response = wp_remote_post($this->api_url . '/order/cancelled-failed', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($payload),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'API bağlantı hatası: ' . $response->get_error_message()
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['success']) && $data['success']) {
            $order->update_meta_data('basit_kargo_cancelled_failed_id', $data['data']['id']);
            $order->update_meta_data('basit_kargo_reference', $data['data']['reference']);
            $order->add_order_note('Basit Kargo iptal/başarısız kaydı oluşturuldu');
            $order->save();
            
            return array('success' => true);
        } else {
            return array(
                'success' => false,
                'message' => isset($data['message']) ? $data['message'] : 'Kayıt oluşturulamadı'
            );
        }
    }
    
    /**
     * Get status text
     */
    private function getStatusText($status) {
        $status_map = array(
            'cancelled' => 'İptal Edildi',
            'failed' => 'Başarısız',
            'refunded' => 'İade Edildi'
        );
        
        return isset($status_map[$status]) ? $status_map[$status] : $status;
    }
}
