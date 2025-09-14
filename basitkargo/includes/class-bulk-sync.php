<?php
/**
 * Basit Kargo Bulk Sync Class
 * Handles bulk synchronization with Basit Kargo API
 */

namespace BasitKargo;

if (!defined('ABSPATH')) {
    exit;
}

class BulkSync {
    
    private $api_url = 'https://basitkargo.com/api/v2';
    private $token;
    
    public function __construct() {
        $this->token = get_option('basit_kargo_token', '');
        $this->api_url = 'https://basitkargo.com/api/v2';
    }
    
    /**
     * Discover all orders from Basit Kargo
     */
    public function discoverAllOrders() {
        $discovered_orders = array();
        $errors = array();
        
        // Method 1: Try known working IDs and variations
        $known_ids = array(
            'RN3-QOQ-MAL',
            'RN1-QOQ-MAL', 'RN2-QOQ-MAL', 'RN4-QOQ-MAL', 'RN5-QOQ-MAL',
            'RN3-QO1-MAL', 'RN3-QO2-MAL', 'RN3-QO3-MAL',
            'RN3-QOQ-MA1', 'RN3-QOQ-MA2', 'RN3-QOQ-MA3',
            'BK1-QOQ-MAL', 'BK2-QOQ-MAL', 'BK3-QOQ-MAL',
            'ORD-QOQ-MAL', 'SIP-QOQ-MAL', 'KAR-QOQ-MAL'
        );
        
        foreach ($known_ids as $api_id) {
            $order_data = $this->fetchOrderById($api_id);
            if ($order_data) {
                $discovered_orders[] = $order_data;
            }
        }
        
        // Method 2: Try to find orders by searching with WooCommerce order numbers
        $wc_orders = wc_get_orders(array(
            'limit' => 10,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        foreach ($wc_orders as $wc_order) {
            $order_number = $wc_order->get_order_number();
            $first_name = $wc_order->get_billing_first_name();
            $last_name = $wc_order->get_billing_last_name();
            
            // Try to find by order number in Basit Kargo
            $order_data = $this->searchOrderByNumber($order_number);
            if ($order_data) {
                $discovered_orders[] = $order_data;
            }
        }
        
        return array(
            'success' => true,
            'orders' => $discovered_orders,
            'total_found' => count($discovered_orders),
            'errors' => $errors,
            'debug_info' => array(
                'known_ids_tested' => count($known_ids),
                'wc_orders_searched' => count($wc_orders),
                'api_token_set' => !empty($this->token)
            )
        );
    }
    
    /**
     * Generate possible API IDs
     */
    private function generatePossibleIds() {
        $possible_ids = array();
        
        // Start with known working IDs
        $possible_ids[] = 'RN3-QOQ-MAL';
        
        // Add more known patterns based on working ID
        $possible_ids[] = 'RN1-QOQ-MAL';
        $possible_ids[] = 'RN2-QOQ-MAL';
        $possible_ids[] = 'RN4-QOQ-MAL';
        $possible_ids[] = 'RN5-QOQ-MAL';
        $possible_ids[] = 'RN3-QO1-MAL';
        $possible_ids[] = 'RN3-QO2-MAL';
        $possible_ids[] = 'RN3-QO3-MAL';
        $possible_ids[] = 'RN3-QOQ-MA1';
        $possible_ids[] = 'RN3-QOQ-MA2';
        $possible_ids[] = 'RN3-QOQ-MA3';
        
        // Pattern 1: RN3-QOQ-MAL format (3 letters, 3 letters, 3 letters)
        $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        
        // Generate some common patterns based on known working ID
        $prefixes = array('RN3', 'RN1', 'RN2', 'RN4', 'RN5', 'BK1', 'BK2', 'BK3', 'ORD', 'SIP', 'KAR');
        $middles = array('QOQ', 'QO1', 'QO2', 'QO3', 'ABC', 'DEF', 'GHI', 'JKL', 'MNO', 'PQR', 'STU', 'VWX', 'YZ0');
        $suffixes = array('MAL', 'MA1', 'MA2', 'MA3', '123', '456', '789', 'ABC', 'DEF', 'GHI', 'JKL', 'MNO');
        
        foreach ($prefixes as $prefix) {
            foreach ($middles as $middle) {
                foreach ($suffixes as $suffix) {
                    $possible_ids[] = $prefix . '-' . $middle . '-' . $suffix;
                }
            }
        }
        
        // Add some random combinations
        for ($i = 0; $i < 50; $i++) {
            $prefix = substr(str_shuffle($letters), 0, 3);
            $middle = substr(str_shuffle($letters), 0, 3);
            $suffix = substr(str_shuffle($letters), 0, 3);
            $possible_ids[] = $prefix . '-' . $middle . '-' . $suffix;
        }
        
        return array_unique($possible_ids);
    }
    
    /**
     * Fetch order by API ID
     */
    private function fetchOrderById($api_id) {
        $response = wp_remote_get($this->api_url . '/order/' . $api_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->token
            ),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!empty($data) && isset($data['id'])) {
            return $data;
        }
        
        return null;
    }
    
    /**
     * Search order by number in Basit Kargo
     */
    private function searchOrderByNumber($order_number) {
        // Try different search endpoints
        $endpoints = array(
            '/order/search?order_number=' . urlencode($order_number),
            '/order/search?foreignCode=' . urlencode($order_number),
            '/order/' . urlencode($order_number)
        );
        
        foreach ($endpoints as $endpoint) {
            $response = wp_remote_get($this->api_url . $endpoint, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->token
                ),
                'timeout' => 10
            ));
            
            if (is_wp_error($response)) {
                continue;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (!empty($data) && isset($data['id'])) {
                return $data;
            }
        }
        
        return null;
    }
    
    /**
     * Sync discovered orders with WooCommerce
     */
    public function syncDiscoveredOrders($discovered_orders) {
        $synced_count = 0;
        $matched_count = 0;
        $errors = array();
        
        foreach ($discovered_orders as $order_data) {
            // Try to find matching WooCommerce order
            $wc_order = $this->findMatchingWooCommerceOrder($order_data);
            
            if ($wc_order) {
                $matched_count++;
                
                // Sync the order
                $sync_result = $this->syncOrderData($wc_order, $order_data);
                
                if ($sync_result['success']) {
                    $synced_count++;
                } else {
                    $errors[] = 'Sipariş ' . $wc_order->get_id() . ': ' . $sync_result['message'];
                }
            }
        }
        
        return array(
            'success' => true,
            'total_discovered' => count($discovered_orders),
            'matched_count' => $matched_count,
            'synced_count' => $synced_count,
            'errors' => $errors
        );
    }
    
    /**
     * Find matching WooCommerce order
     */
    private function findMatchingWooCommerceOrder($order_data) {
        // Try to find by foreign code (order number)
        if (isset($order_data['foreignCode'])) {
            // First try with _order_number meta
            $orders = wc_get_orders(array(
                'meta_key' => '_order_number',
                'meta_value' => $order_data['foreignCode'],
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
            
            // If not found, try with order ID directly
            $order_id = intval($order_data['foreignCode']);
            if ($order_id > 0) {
                $order = wc_get_order($order_id);
                if ($order && !$order->is_empty()) {
                    return $order;
                }
            }
        }
        
        // Debug: Log the search attempt
        error_log('BulkSync: Searching for order with foreignCode: ' . (isset($order_data['foreignCode']) ? $order_data['foreignCode'] : 'none'));
        error_log('BulkSync: Recipient name: ' . (isset($order_data['recipient']['name']) ? $order_data['recipient']['name'] : 'none'));
        
        // Try to find by customer name with improved matching
        if (isset($order_data['recipient']['name'])) {
            $recipient_name = $order_data['recipient']['name'];
            $name_parts = explode(' ', trim($recipient_name));
            
            if (count($name_parts) >= 2) {
                $first_name = $name_parts[0];
                $last_name = implode(' ', array_slice($name_parts, 1));
                
                // Try exact match first
                error_log('BulkSync: Trying exact match - First: ' . $first_name . ', Last: ' . $last_name);
                $orders = wc_get_orders(array(
                    'meta_query' => array(
                        'relation' => 'AND',
                        array(
                            'key' => '_billing_first_name',
                            'value' => $first_name,
                            'compare' => '='
                        ),
                        array(
                            'key' => '_billing_last_name',
                            'value' => $last_name,
                            'compare' => '='
                        )
                    ),
                    'limit' => 1
                ));
                
                error_log('BulkSync: Exact match found ' . count($orders) . ' orders');
                if (!empty($orders)) {
                    return $orders[0];
                }
                
                // Try LIKE match
                $orders = wc_get_orders(array(
                    'meta_query' => array(
                        'relation' => 'AND',
                        array(
                            'key' => '_billing_first_name',
                            'value' => $first_name,
                            'compare' => 'LIKE'
                        ),
                        array(
                            'key' => '_billing_last_name',
                            'value' => $last_name,
                            'compare' => 'LIKE'
                        )
                    ),
                    'limit' => 1
                ));
                
                if (!empty($orders)) {
                    return $orders[0];
                }
                
                // Try with normalized names (Turkish characters)
                $normalized_first = $this->normalizeName($first_name);
                $normalized_last = $this->normalizeName($last_name);
                
                $orders = wc_get_orders(array(
                    'meta_query' => array(
                        'relation' => 'AND',
                        array(
                            'key' => '_billing_first_name',
                            'value' => $normalized_first,
                            'compare' => 'LIKE'
                        ),
                        array(
                            'key' => '_billing_last_name',
                            'value' => $normalized_last,
                            'compare' => 'LIKE'
                        )
                    ),
                    'limit' => 1
                ));
                
                if (!empty($orders)) {
                    return $orders[0];
                }
            }
        }
        
        // Try to find by phone number
        if (isset($order_data['recipient']['phone'])) {
            $orders = wc_get_orders(array(
                'meta_key' => '_billing_phone',
                'meta_value' => $order_data['recipient']['phone'],
                'limit' => 1
            ));
            
            if (!empty($orders)) {
                return $orders[0];
            }
        }
        
        return null;
    }
    
    /**
     * Normalize name for Turkish characters
     */
    private function normalizeName($name) {
        $name = strtolower(trim($name));
        $name = str_replace(
            array('ç', 'ğ', 'ı', 'ö', 'ş', 'ü', 'Ç', 'Ğ', 'I', 'İ', 'Ö', 'Ş', 'Ü'),
            array('c', 'g', 'i', 'o', 's', 'u', 'c', 'g', 'i', 'i', 'o', 's', 'u'),
            $name
        );
        return $name;
    }
    
    /**
     * Sync order data
     */
    private function syncOrderData($wc_order, $order_data) {
        try {
            // Update basic info
            if (isset($order_data['id'])) {
                $wc_order->update_meta_data('basit_kargo_api_id', $order_data['id']);
            }
            if (isset($order_data['barcode'])) {
                $wc_order->update_meta_data('basit_kargo_barcode', $order_data['barcode']);
            }
            if (isset($order_data['foreignCode'])) {
                $wc_order->update_meta_data('basit_kargo_reference', $order_data['foreignCode']);
            }
            
            // Map status
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
                $wc_order->update_meta_data('basit_kargo_status', $mapped_status);
                
                // Debug log
                error_log("BasitKargo Bulk Sync - Order {$wc_order->get_id()}: API Status: {$api_status}, Mapped: {$mapped_status}, Current WC Status: " . $wc_order->get_status());
                
                // Update WooCommerce status based on mapped status
                if ($mapped_status === 'kargoya_verildi' && $wc_order->get_status() !== 'wc-shipped') {
                    $wc_order->update_status('wc-shipped', 'Basit Kargo: Kargoya verildi');
                }
                if ($mapped_status === 'teslim_edildi' && $wc_order->get_status() !== 'wc-delivered') {
                    $wc_order->update_status('wc-delivered', 'Basit Kargo: Teslim edildi');
                    
                    // Send delivered email if enabled
                    if (get_option('basit_kargo_auto_send_delivered_email') === 'yes' && !$wc_order->get_meta('basit_kargo_delivered_mail_sent')) {
                        $email = new \BasitKargo\Email();
                        $email->sendDeliveredEmail($wc_order);
                        $wc_order->update_meta_data('basit_kargo_delivered_mail_sent', current_time('mysql'));
                    }
                }
            }
            
            // Update handler info
            if (isset($order_data['shipmentInfo']['handler'])) {
                $handler = $order_data['shipmentInfo']['handler'];
                if (isset($handler['name'])) {
                    $wc_order->update_meta_data('basit_kargo_handler_name', $handler['name']);
                }
                if (isset($handler['code'])) {
                    $wc_order->update_meta_data('basit_kargo_handler_code', $handler['code']);
                }
            }
            
            if (isset($order_data['shipmentInfo']['handlerShipmentCode'])) {
                $previous_code = $wc_order->get_meta('basit_kargo_handler_shipment_code');
                $new_code = $order_data['shipmentInfo']['handlerShipmentCode'];
                $wc_order->update_meta_data('basit_kargo_handler_shipment_code', $new_code);
                
                // If first time we get a real shipment code, mark as shipped and email
                if (empty($previous_code) && !empty($new_code)) {
                    if ($wc_order->get_status() !== 'wc-shipped') {
                        $wc_order->update_status('wc-shipped', 'Kargo takip kodu oluşturuldu - Kargoya verildi');
                    }
                    if (get_option('basit_kargo_auto_send_email') === 'yes' && !$wc_order->get_meta('basit_kargo_tracking_mail_sent')) {
                        $email = new \BasitKargo\Email();
                        $email->sendTrackingEmail($wc_order);
                        $wc_order->update_meta_data('basit_kargo_tracking_mail_sent', current_time('mysql'));
                    }
                }
            }
            
            if (isset($order_data['shipmentInfo']['handlerShipmentTrackingLink'])) {
                $wc_order->update_meta_data('basit_kargo_handler_tracking_link', $order_data['shipmentInfo']['handlerShipmentTrackingLink']);
            }
            
            // Update timing info
            if (isset($order_data['createdTime'])) {
                $wc_order->update_meta_data('basit_kargo_created_time', $order_data['createdTime']);
            }
            if (isset($order_data['updatedTime'])) {
                $wc_order->update_meta_data('basit_kargo_updated_time', $order_data['updatedTime']);
            }
            if (isset($order_data['shipmentInfo']['shippedTime'])) {
                $wc_order->update_meta_data('basit_kargo_shipped_time', $order_data['shipmentInfo']['shippedTime']);
            }
            if (isset($order_data['shipmentInfo']['deliveredTime'])) {
                $wc_order->update_meta_data('basit_kargo_delivered_time', $order_data['shipmentInfo']['deliveredTime']);
            }
            
            // Update price info
            if (isset($order_data['priceInfo']['shipmentFee'])) {
                $wc_order->update_meta_data('basit_kargo_shipment_fee', $order_data['priceInfo']['shipmentFee']);
            }
            if (isset($order_data['priceInfo']['totalCost'])) {
                $wc_order->update_meta_data('basit_kargo_total_cost', $order_data['priceInfo']['totalCost']);
            }
            
            $wc_order->save();
            
            return array('success' => true, 'message' => 'Sipariş senkronize edildi');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => $e->getMessage());
        }
    }
    
    /**
     * Get sync statistics
     */
    public function getSyncStatistics() {
        $total_orders = wc_get_orders(array(
            'limit' => -1,
            'return' => 'ids'
        ));
        
        $synced_orders = wc_get_orders(array(
            'limit' => -1,
            'meta_query' => array(
                array(
                    'key' => 'basit_kargo_api_id',
                    'compare' => 'EXISTS'
                )
            ),
            'return' => 'ids'
        ));
        
        return array(
            'total_orders' => count($total_orders),
            'synced_orders' => count($synced_orders),
            'unsynced_orders' => count($total_orders) - count($synced_orders),
            'sync_percentage' => count($total_orders) > 0 ? round((count($synced_orders) / count($total_orders)) * 100, 2) : 0
        );
    }
}
