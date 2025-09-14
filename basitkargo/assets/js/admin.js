/**
 * Basit Kargo Admin JavaScript
 * Handles admin panel interactions
 */

jQuery(document).ready(function($) {
    function goToOrderEdit(orderId, delay){
        var base = (window.ajaxurl || (window.basitKargo && basitKargo.ajaxUrl) || '/wp-admin/admin-ajax.php').replace('admin-ajax.php','');
        var isClassic = /\/post\.php$/i.test(window.location.pathname) || /[?&]post=/.test(window.location.search);
        var url = base + (isClassic
            ? ('post.php?post=' + encodeURIComponent(orderId) + '&action=edit')
            : ('admin.php?page=wc-orders&action=edit&id=' + encodeURIComponent(orderId)))
            + '&r=' + Date.now();
        setTimeout(function(){
            try { if (window.top && window.top.location) { window.top.location.assign(url); return; } } catch(e) {}
            try { if (window.parent && window.parent.location) { window.parent.location.assign(url); return; } } catch(e) {}
            window.location.assign(url);
        }, delay || 400);
    }

    function hardReload(delay){
        setTimeout(function(){
            try {
                var topLoc = (window.top && window.top.location) ? window.top.location : window.location;
                var u = new URL(topLoc.href);
                u.searchParams.set('_bk_refresh', Date.now());
                topLoc.replace(u.toString());
            } catch(e) {
                try { window.top.location.reload(); } catch(_) { location.reload(); }
            }
        }, delay || 300);
    }
    
    // Handle button clicks
    $(document).on('click', '.basit-kargo-button', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        if ($button.hasClass('loading') || $button.prop('disabled')) return; // prevent double submit
        var action = $button.data('action');
        var orderId = $button.data('order-id');
        
        if (!action || !orderId) return;

        // Open direct windows for print actions
        if (action === 'basit_kargo_print_pdf') {
            var url = basitKargo.ajaxUrl + '?action=basit_kargo_print_pdf&order_id=' + encodeURIComponent(orderId) + '&_ajax_nonce=' + encodeURIComponent(basitKargo.nonce);
            window.open(url, '_blank');
            return;
        }
        if (action === 'basit_kargo_print_barcode') {
            var url2 = basitKargo.ajaxUrl + '?action=basit_kargo_print_pdf&format=html&order_id=' + encodeURIComponent(orderId) + '&_ajax_nonce=' + encodeURIComponent(basitKargo.nonce);
            window.open(url2, '_blank');
            return;
        }
        
        // Show loading state
        $button.addClass('loading').prop('disabled', true).text(basitKargo.strings.loading);
        
        // Special case: open BK tracking (ensure server resolves correct slug)
        if (action === 'basit_kargo_open_tracking') {
            $.post(basitKargo.ajaxUrl, {
                action: 'basit_kargo_open_tracking',
                order_id: orderId,
                nonce: basitKargo.nonce
            }, function(resp){
                $button.removeClass('loading').prop('disabled', false).text($button.data('original-text'));
                if (resp && resp.success && resp.data && resp.data.url) {
                    window.open(resp.data.url, '_blank');
                } else {
                    alert((resp && resp.data) ? resp.data : 'Takip verisi bulunamadı');
                }
            }).fail(function(){
                $button.removeClass('loading').prop('disabled', false).text($button.data('original-text'));
                alert('İstek başarısız oldu');
            });
            return;
        }

        // Prevent force-create when manual enabled
        if (action === 'basit_kargo_force_create_barcode') {
            try {
                var manualEnabled = $('#bk-manual-enabled').is(':checked');
                if (manualEnabled) {
                    if (!confirm('Manuel kargo aktif. Zorla Barkod manuel kargoyu devre dışı bırakacak. Devam edilsin mi?')) {
                        $button.removeClass('loading').prop('disabled', false).text($button.data('original-text'));
                        return;
                    }
                }
            } catch(e) {}
        }
        // Make AJAX request
        $.post(basitKargo.ajaxUrl, {
            action: action,
            order_id: orderId,
            nonce: basitKargo.nonce
        }, function(response) {
            if (response.success) {
                // For metabox buttons: prefer server redirect if provided; otherwise hard reload current page
                $button.removeClass('loading').text(basitKargo.strings.success);
                try { if (response && response.redirect) { window.top.location.assign(response.redirect); return; } } catch(e) {}
                hardReload(250);
                // Fallback: if reload doesn't happen, prompt the user
                setTimeout(function(){
                    var msg = 'İşlem başarılı. Sayfa yenilenmezse lütfen sayfayı yenileyin.';
                    try { if (!document.hidden) { alert(msg); } else { alert(msg); } }
                    catch(_) { alert(msg); }
                }, 1500);
            } else {
                $button.removeClass('loading').text(basitKargo.strings.error);
                alert(response.data || 'Bir hata oluştu');
                setTimeout(function() {
                    $button.text($button.data('original-text'));
                    $button.prop('disabled', false);
                }, 2000);
            }
        });
    });
    
    // Store original button text
    $('.basit-kargo-button').each(function() {
        $(this).data('original-text', $(this).text());
    });
    
    // Handle order actions
    $(document).on('click', '.woocommerce-order-actions .button, .woocommerce-order-actions a.button, .wc-action-button', function(e) {
        var $button = $(this);
        // Detect action from data-action or class list
        var action = $button.data('action');
        if (!action) {
            var cls = ($button.attr('class') || '');
            var m = cls.match(/basit_kargo_[a-zA-Z0-9_]+/);
            if (m) action = m[0];
        }

        if (action && action.indexOf('basit_kargo_') === 0) {
            e.preventDefault();

            function extractId(str){
                if (!str) return null;
                var m = String(str).match(/(?:post|order)[-_]?(\d+)/i);
                return m ? parseInt(m[1], 10) : null;
            }

            var orderId = $button.data('order-id') || null;
            if (!orderId) {
                var $row = $button.closest('tr, .wc-orders-list__order, .type-shop_order');
                orderId = $row.data('order-id') || $row.data('id') || $row.attr('data-order-id') || $row.attr('data-id') || extractId($row.attr('id'));
            }
            if (!orderId) {
                var href = $button.attr('href');
                if (href) {
                    var url = new URL(href, window.location.origin);
                    orderId = parseInt(url.searchParams.get('post') || url.searchParams.get('order_id') || url.searchParams.get('id')); 
                }
            }
            if (!orderId) return;

            // Open PDF directly in a new tab
            if (action === 'basit_kargo_print_pdf') {
                var url = basitKargo.ajaxUrl + '?action=basit_kargo_print_pdf&order_id=' + encodeURIComponent(orderId) + '&_ajax_nonce=' + encodeURIComponent(basitKargo.nonce);
                window.open(url, '_blank');
                return;
            }

            // For sync/create actions (list page buttons), run AJAX but DO NOT redirect
            if (action === 'basit_kargo_barcode_sync' || action === 'basit_kargo_manual_trigger' || action === 'basit_kargo_force_create_barcode') {
                $button.addClass('basit_kargo_loading').text('⏳ İşleniyor...');
                $.post(basitKargo.ajaxUrl, {
                    action: action,
                    order_id: orderId,
                    nonce: basitKargo.nonce
                }, function(response) {
                    if (response.success) {
                        $button.removeClass('basit_kargo_loading').text('✅ Tamam');
                    } else {
                        $button.removeClass('basit_kargo_loading').text('❌ Hata');
                        alert(response.data || 'Bir hata oluştu');
                    }
                });
                return;
            }

            // Fallback: other actions via AJAX (if any) on list page — do not redirect
            $button.addClass('basit_kargo_loading').text('⏳ İşleniyor...');
            $.post(basitKargo.ajaxUrl, {
                action: action,
                order_id: orderId,
                nonce: basitKargo.nonce
            }, function(response) {
                if (response.success) {
                    $button.removeClass('basit_kargo_loading').text('✅ Başarılı!');
                } else {
                    $button.removeClass('basit_kargo_loading').text('❌ Hata!');
                    alert(response.data || 'Bir hata oluştu');
                }
            });
        }
    });
    
    // Force button text visibility
    function forceButtonTextVisibility() {
        $('.woocommerce-order-actions .button.basit_kargo_manual_trigger, ' +
          '.woocommerce-order-actions .button.basit_kargo_send_mail, ' +
          '.woocommerce-order-actions .button.basit_kargo_print_barcode, ' +
          '.woocommerce-order-actions .button.basit_kargo_print_pdf, ' +
          '.woocommerce-order-actions .button.basit_kargo_barcode_sync, ' +
          '.woocommerce-order-actions .button.basit_kargo_manual_tracking_sync').each(function() {
            var $btn = $(this);
            $btn.css({
                'text-indent': '0',
                'font-size': '11px',
                'padding': '4px 8px',
                'overflow': 'visible',
                'white-space': 'nowrap',
                'background-image': 'none',
                'background-size': '0'
            });
        });
    }
    
    // Run on page load
    forceButtonTextVisibility();
    
    // Run when new content is loaded
    $(document).on('DOMNodeInserted', function() {
        setTimeout(forceButtonTextVisibility, 100);
    });
    
    // Run periodically
    setInterval(forceButtonTextVisibility, 1000);
    
    // Handle sync button
    $(document).on('click', '[data-action="basit_kargo_sync_cancelled_failed_orders"]', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        $button.addClass('loading').text('🔄 Senkronize ediliyor...');
        
        $.post(basitKargo.ajaxUrl, {
            action: 'basit_kargo_sync_cancelled_failed_orders',
            nonce: basitKargo.nonce
        }, function(response) {
            if (response.success) {
                $button.removeClass('loading').text('✅ ' + response.data.message);
                setTimeout(function() {
                    location.reload();
                }, 3000);
            } else {
                $button.removeClass('loading').text('❌ Hata!');
                alert(response.data || 'Senkronizasyon başarısız');
            }
        });
    });
    
    // Robust handler for manual barcode/tracking save from UI metabox
    $(document).on('click', '#bk-ui-manual-save', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var orderId = $btn.data('order-id');
        var barcode = $('#bk_ui_manual_barcode').val();
        var tracking = $('#bk_ui_manual_tracking').val();
        var nonce = (typeof basitKargo !== 'undefined' && basitKargo.nonce) ? basitKargo.nonce : ($('#bk_ui_manual_nonce').val() || '');
        var handler = $('#bk_ui_manual_handler').val();
        var aj = (typeof basitKargo !== 'undefined' && basitKargo.ajaxUrl) ? basitKargo.ajaxUrl : (typeof ajaxurl !== 'undefined' && ajaxurl ? ajaxurl : (window.location.origin + '/wp-admin/admin-ajax.php'));

        if (!orderId) return;

        var savingText = (basitKargo && basitKargo.strings && basitKargo.strings.loading) ? basitKargo.strings.loading : 'Kaydediliyor...';
        var successText = (basitKargo && basitKargo.strings && basitKargo.strings.success) ? basitKargo.strings.success : 'Başarılı!';
        var errorText = (basitKargo && basitKargo.strings && basitKargo.strings.error) ? basitKargo.strings.error : 'Hata!';

        $btn.addClass('loading').prop('disabled', true).text(savingText);

        $.post(aj, {
            action: 'basit_kargo_save_manual_map',
            order_id: orderId,
            barcode: barcode,
            tracking: tracking,
            nonce: nonce,
            handler: handler
        }, function(response) {
            if (response && response.success) {
                $('#bk-ui-manual-result').text(successText).css('color', 'green');
                setTimeout(function(){ location.reload(); }, 600);
            } else {
                $('#bk-ui-manual-result').text(errorText).css('color', 'red');
                alert((response && response.data) ? response.data : 'Bir hata oluştu');
                $btn.prop('disabled', false).removeClass('loading').text('Kaydet');
            }
        }).fail(function(){
            $('#bk-ui-manual-result').text(errorText).css('color', 'red');
            alert('İstek başarısız oldu');
            $btn.prop('disabled', false).removeClass('loading').text('Kaydet');
        });
    });

    // Enable/disable manual fields when the manual toggle changes
    function applyManualToggle() {
        try {
            var enabled = $('#bk-manual-enabled').is(':checked') || $('#bk-manual-enabled-inline').is(':checked');
            // Side metabox fields
            $('#bk-manual-handler').prop('disabled', !enabled);
            $('#bk-manual-tracking').prop('disabled', !enabled);
            // Inline fallback fields (when no barcode)
            $('#bk_inline_handler').prop('disabled', !enabled);
            $('#bk_inline_shipment_code').prop('disabled', !enabled);
        } catch (e) {}
    }
    // Bind change event and apply on load
    $(document).on('change', '#bk-manual-enabled, #bk-manual-enabled-inline', applyManualToggle);
    applyManualToggle();
    // Re-apply after dynamic DOM updates (HPOS editor)
    $(document).on('DOMNodeInserted', function(){ setTimeout(applyManualToggle, 100); });

});

// Global fallback for manual save (called via inline onclick)
window.BK_manualSave = function(orderId){
  try {
    var $ = window.jQuery;
    if (!$) return;
    var $btn = jQuery('#bk-ui-manual-save');
    var barcode = jQuery('#bk_ui_manual_barcode').val();
    var tracking = jQuery('#bk_ui_manual_tracking').val();
    var nonce = (typeof basitKargo !== 'undefined' && basitKargo.nonce) ? basitKargo.nonce : (jQuery('#bk_ui_manual_nonce').val() || '');
    var aj = (typeof basitKargo !== 'undefined' && basitKargo.ajaxUrl) ? basitKargo.ajaxUrl : (typeof ajaxurl !== 'undefined' && ajaxurl ? ajaxurl : (window.location.origin + '/wp-admin/admin-ajax.php'));
    if (!orderId) { orderId = $btn.data('order-id'); }
    if (!orderId) return;
    $btn.addClass('loading').prop('disabled', true).text((basitKargo && basitKargo.strings && basitKargo.strings.loading) ? basitKargo.strings.loading : 'Kaydediliyor...');
    var handler = jQuery('#bk_ui_manual_handler').val();
    jQuery.post(aj, { action: 'basit_kargo_save_manual_map', order_id: orderId, barcode: barcode, tracking: tracking, handler: handler, nonce: nonce }, function(resp){
      if (resp && resp.success) {
        jQuery('#bk-ui-manual-result').text((basitKargo && basitKargo.strings && basitKargo.strings.success) ? basitKargo.strings.success : 'Başarılı!').css('color','green');
        setTimeout(function(){ location.reload(); }, 600);
      } else {
        jQuery('#bk-ui-manual-result').text((basitKargo && basitKargo.strings && basitKargo.strings.error) ? basitKargo.strings.error : 'Hata!').css('color','red');
        alert((resp && resp.data) ? resp.data : 'Bir hata oluştu');
        $btn.prop('disabled', false).removeClass('loading').text('Kaydet');
      }
    }).fail(function(){
      jQuery('#bk-ui-manual-result').text('Hata!').css('color','red');
      alert('İstek başarısız oldu');
      $btn.prop('disabled', false).removeClass('loading').text('Kaydet');
    });
  } catch(e) { console.error(e); }
};

// Client-side error logger (admin UI)
(function(){
  if (typeof window === 'undefined') return;
  var queued = [];
  var sending = false;
  var $ = window.jQuery; // Ensure jQuery reference in this IIFE
  function getAjaxUrl(){
    if (typeof basitKargo !== 'undefined' && basitKargo.ajaxUrl) return basitKargo.ajaxUrl;
    if (typeof ajaxurl !== 'undefined' && ajaxurl) return ajaxurl;
    return window.location.origin + '/wp-admin/admin-ajax.php';
  }
  function getNonce(){
    if (typeof basitKargo !== 'undefined' && basitKargo.nonce) return basitKargo.nonce;
    var el = document.getElementById('bk_ui_manual_nonce');
    return el ? el.value : '';
  }
  function flush(){
    if (sending || queued.length === 0) return;
    sending = true;
    var payload = queued.slice(0);
    queued = [];
    var fd = new FormData();
    fd.append('action', 'basit_kargo_log_client_error');
    fd.append('nonce', getNonce());
    fd.append('entries', JSON.stringify(payload));
    fetch(getAjaxUrl(), { method: 'POST', body: fd }).finally(function(){ sending = false; });
  }
  function scheduleFlush(){ setTimeout(flush, 3000); }

  window.addEventListener('error', function(ev){
    try {
      queued.push({
        message: (ev && ev.message) || 'Unknown error',
        source: (ev && ev.filename) || '',
        lineno: (ev && ev.lineno) || 0,
        colno: (ev && ev.colno) || 0,
        stack: ev && ev.error && ev.error.stack ? String(ev.error.stack).slice(0, 1000) : '',
        url: window.location.href,
        userAgent: navigator.userAgent,
        time: new Date().toISOString()
      });
      scheduleFlush();
    } catch(e) {}
  });

  // Inline manual save (independent manual carrier + code)
  if ($ && $(document).on) $(document).on('click', '#bk-inline-manual-save', function(e){
    e.preventDefault();
    var $btn = $(this);
    var orderId = $btn.data('order-id');
    var code = $('#bk_inline_shipment_code').val() || $('#bk_inline_shipment_code').val();
    var handler = $('#bk_inline_handler').val();
    var manualEnabled = ($('#bk-manual-enabled').is(':checked') || $('#bk-manual-enabled-inline').is(':checked')) ? 1 : 0;
    var aj = (typeof basitKargo !== 'undefined' && basitKargo.ajaxUrl) ? basitKargo.ajaxUrl : (typeof ajaxurl !== 'undefined' ? ajaxurl : (window.location.origin + '/wp-admin/admin-ajax.php'));
    if (!orderId) return;
    $btn.prop('disabled', true).text('Kaydediliyor...');
    $.post(aj, { action: 'basit_kargo_save_inline_manual', order_id: orderId, tracking: code, handler: handler, manual_enabled: manualEnabled, nonce: (basitKargo && basitKargo.nonce) || '' }, function(resp){
      if (resp && resp.success) {
        $('#bk-inline-manual-result').text('Kaydedildi').css('color','green');
        setTimeout(function(){ location.reload(); }, 500);
      } else {
        $('#bk-inline-manual-result').text('Hata').css('color','red');
        alert((resp && resp.data) ? resp.data : 'Bir hata oluştu');
        $btn.prop('disabled', false).text('Manuel Kaydet');
      }
    }).fail(function(){
      $('#bk-inline-manual-result').text('Hata').css('color','red');
      alert('İstek başarısız oldu');
      $btn.prop('disabled', false).text('Manuel Kaydet');
    });
  });

  window.addEventListener('unhandledrejection', function(ev){
    try {
      var reason = ev && ev.reason ? (ev.reason.stack || ev.reason.message || String(ev.reason)) : 'unhandledrejection';
      queued.push({
        message: 'Unhandled Rejection: ' + reason,
        source: '',
        lineno: 0,
        colno: 0,
        stack: String(reason).slice(0, 1000),
        url: window.location.href,
        userAgent: navigator.userAgent,
        time: new Date().toISOString()
      });
      scheduleFlush();
    } catch(e) {}
  });
})();
