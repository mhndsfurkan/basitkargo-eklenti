<?php
/**
 * Basit Kargo PDF Class
 * Handles PDF generation for cargo labels
 */

namespace BasitKargo;

if (!defined('ABSPATH')) {
    exit;
}

class PDF {
    
    public function __construct() {
        $this->initHooks();
    }
    
    private function initHooks() {
        // PDF generation hooks
        add_action('wp_ajax_basit_kargo_print_pdf', array($this, 'generatePDF'));
        add_action('wp_ajax_nopriv_basit_kargo_print_pdf', array($this, 'generatePDF'));
    }
    
    /**
     * Generate PDF for order (direct call)
     */
    public function generatePDFForOrder($order) {
        if (!$order) {
            return array('success' => false, 'message' => 'Sipariş bulunamadı');
        }
        
        $barcode = $order->get_meta('basit_kargo_barcode');
        if (!$barcode) {
            return array('success' => false, 'message' => 'Barkod bulunamadı');
        }

        // Not marking as shipped here; PDF etiketi ön etiket amaçlıdır.
        
        // Prefer TCPDF for a true PDF if available, else print-friendly HTML
        if (class_exists('TCPDF')) {
            $this->generateTCPDF($order, $barcode);
            return array('success' => true, 'message' => 'PDF oluşturuldu');
        }
        $this->generateHTML($order, $barcode);
        return array('success' => true, 'message' => 'HTML etiket oluşturuldu');
    }
    
    /**
     * Generate PDF for order (AJAX)
     */
    public function generatePDF() {
        try {
            $order_id = intval($_GET['order_id']);
            $order = wc_get_order($order_id);
            
            if (!$order) {
                wp_die('Sipariş bulunamadı');
            }
            
            $barcode = $order->get_meta('basit_kargo_barcode');
            if (!$barcode) {
                wp_die('Barkod bulunamadı');
            }
            
            // No status/email side-effects here.

            // Clear any output buffer
            if (ob_get_level()) {
                ob_end_clean();
            }
            
            // Allow forcing HTML via ?format=html
            $format = isset($_GET['format']) ? sanitize_text_field($_GET['format']) : '';
            if ($format === 'html') {
                $this->generateHTML($order, $barcode);
            } else if (class_exists('TCPDF')) {
                // Use TCPDF when possible for consistent printing
                $this->generateTCPDF($order, $barcode);
            } else {
                $this->generateHTML($order, $barcode);
            }
            
        } catch (Exception $e) {
            // Log error
            error_log('PDF Generation Error: ' . $e->getMessage());
            wp_die('PDF oluşturulurken hata oluştu: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate PDF using TCPDF
     */
    private function generateTCPDF($order, $barcode) {
        // Clear any output buffer
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Create new PDF document
        $pdf = new \TCPDF('P', 'mm', array(96, 96), true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Basit Kargo');
        $pdf->SetAuthor('Basit Kargo');
        $pdf->SetTitle('Kargo Barkodu - ' . $order->get_order_number());
        
        // Remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        
        // Set margins to 0
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        
        // Add a page
        $pdf->AddPage();
        
        // Set base font larger for readability
        $pdf->SetFont('helvetica', '', 10);
        
        // Get order data
        $billing = $order->get_address('billing');
        $shipping = $order->get_address('shipping');
        
        // Sender and date (compact in one row)
        $pdf->SetXY(4, 4);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(60, 3, 'Gönderici: Orbit Camper', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(66, 4);
        $pdf->Cell(30, 3, date('d.m.Y H:i'), 0, 1, 'R');
        
        // Recipient info (compact, with phone and address)
        $pdf->SetXY(4, 7);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(92, 3, 'Alıcı: ' . trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? '')), 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(4, 9.5);
        $pdf->Cell(92, 3, 'Tel: ' . $order->get_billing_phone(), 0, 1, 'L');
        $pdf->SetXY(4, 12);
        $pdf->Cell(92, 3, trim(($shipping['address_1'] ?? '') . ' ' . ($shipping['address_2'] ?? '')), 0, 1, 'L');
        $pdf->SetXY(4, 14.5);
        $pdf->Cell(92, 3, trim(($shipping['city'] ?? '') . ' / ' . ($shipping['state'] ?? '')), 0, 1, 'L');
 
        // Order items
        $pdf->SetXY(4, 18);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(92, 3, 'Sipariş İçeriği:', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $y = 21.5;
        foreach ($order->get_items() as $item) {
            if ($y > 41) break; // Prevent overflow
            $pdf->SetXY(4, $y);
            $pdf->Cell(92, 3, '- ' . $item->get_name() . ' (x' . $item->get_quantity() . ')', 0, 1, 'L');
            $y += 3;
        }
        
        // Cargo code and note (compact)
        $pdf->SetXY(4, 43);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(92, 7, 'Kargo Kodu: ' . $barcode, 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetXY(4, 46.5);
        $pdf->Cell(92, 3, 'Not: Gemen (basitkargo.com)', 0, 1, 'L');
        
        // Barcode drawing using TCPDF built-in (no external HTTP)
        $style = array(
            'position' => '',
            'align' => 'C',
            'stretch' => false,
            'fitwidth' => true,
            'cellfitalign' => '',
            'border' => false,
            'hpadding' => 0,
            'vpadding' => 0,
            'fgcolor' => array(0,0,0),
            'bgcolor' => false,
            'text' => true,
            'font' => 'helvetica',
            'fontsize' => 14,
            'stretchtext' => 0
        );
        $pdf->SetXY(4, 51);
        // Use standard bar thickness and show readable text
        $pdf->write1DBarcode($barcode, 'C128', 4, 51, 92, 30, 0.4, $style, '');
        
        // Output PDF
        $filename = 'kargo-etiketi-' . $order->get_order_number() . '.pdf';
        $pdf->Output($filename, 'D');
        exit;
    }
    
    /**
     * Generate HTML fallback
     */
    public function generateHTML($order, $barcode) {
        $billing = $order->get_address('billing');
        $shipping = $order->get_address('shipping');
        
        // Get order items for display
        $items_text = '';
        foreach ($order->get_items() as $item) {
            $items_text .= $item->get_name() . ' - (' . $item->get_quantity() . ' Adet)';
            if (count($order->get_items()) > 1) {
                $items_text .= ', ';
            }
        }
        
        // Get handler info
        $handler_name = $order->get_meta('basit_kargo_handler_name');
        $handler_logo = '';
        if ($handler_name) {
            $handler_logo = '<div style="position: absolute; bottom: 5mm; right: 5mm; font-size: 6px; color: #666;">' . $handler_name . '</div>';
        }
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Kargo Barkodu - ' . $order->get_order_number() . '</title>
            <style>
                @page {
                    size: 96mm 96mm;
                    margin: 0;
                }
                body {
                    margin: 0;
                    padding: 3mm;
                    font-family: Arial, sans-serif;
                    font-size: 10px;
                    line-height: 1.15;
                    overflow: hidden;
                    background: white;
                }
                .label {
                    width: 92mm;
                    height: 92mm;
                    border: 1px solid #000;
                    position: relative;
                    page-break-after: avoid;
                    page-break-before: avoid;
                    page-break-inside: avoid;
                }
                /* header removed; date shown inside sender-recipient */
                .sender-recipient { display: flex; justify-content: space-between; padding: 1mm 1.5mm; border-bottom: 1px solid #000; min-height: 10mm; }
                .sender, .recipient { flex: 1; padding: 0 1.5mm; }
                /* removed circular icons */
                .barcode-section { text-align: center; padding: 1mm 1.5mm; border-bottom: 1px solid #000; height: 36mm; }
                #barcodeCanvas { display: block; margin: 0 auto; width: 82mm; height: 22mm; }
                .cargo-code { font-size: 11px; font-weight: 700; margin-top: 1mm; }
                .note-section { padding: 1mm 1.5mm; border-bottom: 1px solid #000; height: 9mm; }
                .note-title { font-weight: bold; margin-bottom: 1mm; font-size: 10px; }
                .item-section { padding: 1mm 1.5mm; height: 19mm; }
                .desi-kg { font-weight: bold; margin-bottom: 1mm; font-size: 10px; }
                @media print {
                    body { margin: 0; padding: 1mm; }
                    .label { width: 94mm; height: 94mm; }
                }
            </style>
        </head>
        <body>
            <div class="label">
                <!-- Sender, Date and Recipient -->
                <div class="sender-recipient">
                    <div class="sender">
                        <strong>Gönderici:</strong><br>
                        Orbit Camper<br>
                        <span class="date-time">' . date('d.m.Y H:i') . '</span>
                    </div>
                    <div class="recipient">
                        <strong>Alıcı:</strong><br>
                        <strong>' . $shipping['first_name'] . ' ' . $shipping['last_name'] . '</strong><br>
                        Tel: ' . $order->get_billing_phone() . '<br>
                        ' . trim(($shipping['address_1'] ?? '') . ' ' . ($shipping['address_2'] ?? '')) . '<br>
                        ' . trim(($shipping['city'] ?? '') . ' / ' . ($shipping['state'] ?? '')) . '
                    </div>
                </div>
                
                <!-- Barcode Section -->
                <div class="barcode-section">
                    <canvas id="barcodeCanvas"></canvas>
                    <div class="cargo-code" style="font-size:15px;font-weight:700;">Kargo Kodu: ' . $barcode . '</div>
                </div>
                
                <!-- Note Section -->
                <div class="note-section">
                    <div class="note-title">Kargo Personeline Not:</div>
                    Pazaryeri - Gemen Bilgi Teknolojileri olarak işleme alınmalıdır!
                </div>
                
                <!-- Item Section -->
                <div class="item-section">
                    <div class="desi-kg">Desi / Kg: 1</div>
                    ' . $items_text . '
                </div>
                
                ' . $handler_logo . '
            </div>
            
            <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
            <script>
                window.onload = function() {
                    try {
                        var code = ' . json_encode($barcode) . ';
                        var canvas = document.getElementById("barcodeCanvas");
                        if (canvas && window.JsBarcode) {
                            JsBarcode(canvas, code, { format: "code128", width: 1.0, height: 88, displayValue: false, margin: 2, lineColor: "#000" });
                        } else {
                            var img = document.createElement("img");
                            img.src = "https://barcode.tec-it.com/barcode.ashx?data=" + encodeURIComponent(code) + "&code=Code128&dpi=300";
                            img.style.maxWidth = "82mm";
                            img.style.height = "22mm";
                            var cont = document.querySelector(".barcode-section");
                            if (cont) { cont.innerHTML = ""; cont.appendChild(img); }
                        }
                    } catch (e) { /* ignore */ }
                    setTimeout(function(){ window.print(); }, 500);
                 };
             </script>
         </body>
         </html>';
         
         echo $html;
         exit;
    }
}