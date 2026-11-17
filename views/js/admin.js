(function ($) {
    var $progress = $('#ssb_ajax_export_progress');
    var baseUrl = $progress.data('ajax-url') || '';
    var ssbExportAjaxUrl = baseUrl + (baseUrl.indexOf('?') === -1 ? '?' : '&') + 'ajax=1&action=ssbhesabfaAjaxExport';
    var ssbExportRunning = false;

    function ssbMessage(key, fallback) {
        return $progress.data(key) || fallback;
    }

    function ssbExportLog(message, type) {
        var cls = type === 'error' ? 'text-danger' : (type === 'warning' ? 'text-warning' : 'text-muted');
        $('#ssb_ajax_export_log').append('<div class="' + cls + '">' + $('<div/>').text(message).html() + '</div>');
        var box = $('#ssb_ajax_export_log');
        if (box.length) {
            box.scrollTop(box[0].scrollHeight);
        }
    }

    function ssbSetExportProgress(response) {
        var percent = response && response.percent !== undefined ? parseInt(response.percent, 10) : 0;
        if (isNaN(percent)) { percent = 0; }
        percent = Math.max(0, Math.min(100, percent));
        $('#ssb_ajax_export_bar').css('width', percent + '%').text(percent + '%');
        $('#ssb_ajax_export_status').text((response.processed || 0) + ' / ' + (response.total || 0));
    }

    function ssbRunExportBatch(type, reset) {
        $.ajax({
            type: 'POST',
            url: ssbExportAjaxUrl,
            dataType: 'json',
            data: {
                export_type: type,
                reset: reset ? 1 : 0
            }
        }).done(function (response) {
            if (!response) {
                ssbExportRunning = false;
                ssbExportLog(ssbMessage('msg-invalid-response', 'Invalid server response.'), 'error');
                return;
            }
            ssbSetExportProgress(response);
            if (response.message) {
                ssbExportLog(response.message, response.status || (response.success ? 'success' : 'warning'));
            }
            if (response.paused) {
                ssbExportRunning = false;
                $('.ssb-ajax-export-btn').prop('disabled', false);
                ssbExportLog(ssbMessage('msg-ajax-failed', 'Export paused. Click the same export button to resume from the saved position.'), 'error');
                return;
            }
            if (response.fatal) {
                ssbExportRunning = false;
                $('.ssb-ajax-export-btn').prop('disabled', false);
                return;
            }
            if (response.done) {
                ssbExportRunning = false;
                $('.ssb-ajax-export-btn').prop('disabled', false);
                ssbExportLog(ssbMessage('msg-export-completed', 'Export completed.'), 'success');
                return;
            }
            window.setTimeout(function () {
                ssbRunExportBatch(type, false);
            }, 1000);
        }).fail(function () {
            ssbExportRunning = false;
            $('.ssb-ajax-export-btn').prop('disabled', false);
            ssbExportLog(ssbMessage('msg-ajax-failed', 'Ajax request failed. The export can be started again and will continue from the last stored position.'), 'error');
        });
    }

    $(document).on('submit', '.ssb-confirm-loader-form', function () {
        var confirmText = $(this).data('confirm');
        $('#sync_loader').show();
        return confirmText ? confirm(confirmText) : true;
    });

    $(document).on('click', '.ssb-ajax-export-btn', function () {
        if (ssbExportRunning) {
            return false;
        }
        var type = $(this).data('export-type');
        var confirmText = $(this).data('confirm');
        if (confirmText && !confirm(confirmText)) {
            return false;
        }
        ssbExportRunning = true;
        $('.ssb-ajax-export-btn').prop('disabled', true);
        $('#ssb_ajax_export_progress').show();
        $('#ssb_ajax_export_log').empty();
        $('#ssb_ajax_export_title').text(type === 'products' ? ssbMessage('msg-export-products', 'Export products') : ssbMessage('msg-export-customers', 'Export customers'));
        ssbSetExportProgress({processed: 0, total: 0, percent: 0});
        ssbExportLog(ssbMessage('msg-starting-export', 'Starting export / resume...'), 'success');
        ssbRunExportBatch(type, false);
        return false;
    });
})(jQuery);
(function ($) {
    var confirmFormSelector = '.ssb-confirm-form, .ssb-inline-action-form, .ssb-inline-action-form-last';
    $(document)
        .off('submit.ssbhesabfaConfirm', confirmFormSelector)
        .on('submit.ssbhesabfaConfirm', confirmFormSelector, function () {
            var confirmText = $(this).data('confirm');
            return confirmText ? confirm(confirmText) : true;
        });
})(jQuery);

$(document).ready(function () {
    var bankSelector = '.ssbhesabfa-payment-bank-row select';
    var paymentSelector = bankSelector + ', .ssbhesabfa-payment-mode-row select, .ssbhesabfa-fee-type-row select, .ssbhesabfa-fee-payer-row select';

    function updatePaymentFields(select) {
        var form = $(select.form);
        var baseName = select.name.replace(/(_QESTYAR_RECEIVABLE_FROM_PAYMENT|_FEE_TYPE|_FEE_PAYER)$/, '');

        function field(suffix) {
            return form.find('[name="' + baseName + suffix + '"]');
        }

        function show(suffix, visible) {
            var input = field(suffix);
            input.closest('.form-group').toggleClass('hide', !visible);
            input.prop('disabled', !visible);
        }

        var receivable = field('_QESTYAR_RECEIVABLE_FROM_PAYMENT').val() === '1';
        var bank = field('').val();
        var hasBank = !!bank && bank !== '0' && bank !== '-1';
        var feeType = field('_FEE_TYPE').val();
        var showFees = receivable || hasBank;
        var customerPays = !receivable && hasBank && field('_FEE_PAYER').val() === 'customer';
        var hasFee = ['percent', 'fixed', 'shaparak_purchase'].indexOf(feeType) !== -1;

        show('', !receivable);
        show('_RECEIVABLE_ACCOUNT_PATH', receivable);
        show('_FEE_TYPE', showFees);
        show('_FEE_PERCENT', showFees && feeType === 'percent');
        show('_FEE_FIXED', showFees && feeType === 'fixed');
        show('_FEE_PAYER', !receivable && hasBank);
        show('_CUSTOMER_CHARGE_PERCENT', customerPays);
        show('_INCOME_ACCOUNT_PATH', customerPays);
        show('_FEE_INCOME_CONTACT_CODE', customerPays);
        show('_EXPENSE_ACCOUNT_PATH', receivable ? hasFee : customerPays);
    }

    $(bankSelector).each(function () { updatePaymentFields(this); });
    $(document).on('change', paymentSelector, function () { updatePaymentFields(this); });
    $(document).on('submit', 'form', function () { var deleteDataInput = $(this).find("input[name='SSBHESABFA_DELETE_DATA_ON_UNINSTALL']:checked"); var warning = $('.ssb-admin-wrap').data('delete-warning'); if (deleteDataInput.length && deleteDataInput.val() == '1' && warning) { return confirm(warning); } return true; });
});
