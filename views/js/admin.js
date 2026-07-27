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
    function ssbhesabfaTogglePaymentFeeBlock(bankSelectElement) {
        var bankSelectName = $(bankSelectElement).attr('name');
        var bankValue = $(bankSelectElement).val();
        if (!bankSelectName) { return; }
        var names = {
            feeType: bankSelectName + '_FEE_TYPE', feePayer: bankSelectName + '_FEE_PAYER', percent: bankSelectName + '_FEE_PERCENT', fixed: bankSelectName + '_FEE_FIXED', customerCharge: bankSelectName + '_CUSTOMER_CHARGE_PERCENT', incomeAccount: bankSelectName + '_INCOME_ACCOUNT_PATH', incomeContact: bankSelectName + '_FEE_INCOME_CONTACT_CODE'
        };
        var feeTypeRow = $('[name="' + names.feeType + '"]').closest('.form-group');
        var feePayerRow = $('[name="' + names.feePayer + '"]').closest('.form-group');
        var percentRow = $('[name="' + names.percent + '"]').closest('.form-group');
        var fixedRow = $('[name="' + names.fixed + '"]').closest('.form-group');
        var customerChargeRow = $('[name="' + names.customerCharge + '"]').closest('.form-group');
        var incomeAccountRow = $('[name="' + names.incomeAccount + '"]').closest('.form-group');
        var incomeContactRow = $('[name="' + names.incomeContact + '"]').closest('.form-group');
        if (bankValue === '-1' || bankValue === '' || bankValue === '0') { feeTypeRow.hide(); feePayerRow.hide(); percentRow.hide(); fixedRow.hide(); customerChargeRow.hide(); incomeAccountRow.hide(); incomeContactRow.hide(); return; }
        feeTypeRow.show(); feePayerRow.show(); ssbhesabfaToggleFeeFields($('[name="' + names.feeType + '"]')); ssbhesabfaToggleFeePayerFields($('[name="' + names.feePayer + '"]'));
    }
    function ssbhesabfaToggleFeeFields(selectElement) {
        var selectName = $(selectElement).attr('name'); var feeType = $(selectElement).val(); if (!selectName) { return; }
        var baseName = selectName.replace('_FEE_TYPE', ''); var percentRow = $('[name="' + baseName + '_FEE_PERCENT"]').closest('.form-group'); var fixedRow = $('[name="' + baseName + '_FEE_FIXED"]').closest('.form-group');
        percentRow.hide(); fixedRow.hide(); if (feeType === 'percent') { percentRow.show(); } if (feeType === 'fixed') { fixedRow.show(); }
    }
    function ssbhesabfaToggleFeePayerFields(selectElement) {
        var selectName = $(selectElement).attr('name'); var feePayer = $(selectElement).val(); if (!selectName) { return; }
        var baseName = selectName.replace('_FEE_PAYER', ''); var customerChargeRow = $('[name="' + baseName + '_CUSTOMER_CHARGE_PERCENT"]').closest('.form-group'); var incomeAccountRow = $('[name="' + baseName + '_INCOME_ACCOUNT_PATH"]').closest('.form-group'); var incomeContactRow = $('[name="' + baseName + '_FEE_INCOME_CONTACT_CODE"]').closest('.form-group');
        customerChargeRow.hide(); incomeAccountRow.hide(); incomeContactRow.hide(); if (feePayer === 'customer') { customerChargeRow.show(); incomeAccountRow.show(); incomeContactRow.show(); }
    }
    $("select[name^='SSBHESABFA_PAYMENT_METHOD_']:not([name$='_FEE_TYPE']):not([name$='_FEE_PAYER'])").each(function () { ssbhesabfaTogglePaymentFeeBlock(this); });
    $(document).on('change', "select[name^='SSBHESABFA_PAYMENT_METHOD_']:not([name$='_FEE_TYPE']):not([name$='_FEE_PAYER'])", function () { ssbhesabfaTogglePaymentFeeBlock(this); });
    $(document).on('change', "select[name$='_FEE_TYPE']", function () { ssbhesabfaToggleFeeFields(this); });
    $(document).on('change', "select[name$='_FEE_PAYER']", function () { ssbhesabfaToggleFeePayerFields(this); });
    $(document).on('submit', 'form', function () { var deleteDataInput = $(this).find("input[name='SSBHESABFA_DELETE_DATA_ON_UNINSTALL']:checked"); var warning = $('.ssb-admin-wrap').data('delete-warning'); if (deleteDataInput.length && deleteDataInput.val() == '1' && warning) { return confirm(warning); } return true; });
});
