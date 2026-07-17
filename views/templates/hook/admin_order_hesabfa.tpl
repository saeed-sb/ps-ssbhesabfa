{*
* SSB Hesabfa admin order card
*}
<div class="card panel ssbhesabfa-admin-order-card">
    <div class="card-header panel-heading d-flex justify-content-between align-items-center">
        <span>
            <i class="icon-file-text"></i> {l s='Hesabfa Invoice' mod='ssbhesabfa'}
        </span>
    </div>
    <div class="card-body panel-body">
        {if isset($ssbhesabfa_result) && $ssbhesabfa_result}
            <div class="alert alert-{if $ssbhesabfa_result.type == 'success'}success{else}danger{/if}">
                {$ssbhesabfa_result.message|escape:'html':'UTF-8'}
            </div>
        {/if}

        <div class="d-flex justify-content-between align-items-center ssbhesabfa-admin-order-row">
            <div>
                <strong>{l s='Hesabfa invoice number:' mod='ssbhesabfa'}</strong>
            </div>
            <div>
                {if $ssbhesabfa_invoice_number}
                    <span class="badge badge-success label label-success">{$ssbhesabfa_invoice_number|escape:'html':'UTF-8'}</span>
                {else}
                    <span class="badge badge-warning label label-warning">{l s='Not registered' mod='ssbhesabfa'}</span>
                {/if}
            </div>
        </div>

        {if $ssbhesabfa_invoice_number}
            <p class="text-muted help-block mb-0">
                {l s='This PrestaShop order is already registered in Hesabfa.' mod='ssbhesabfa'}
            </p>
        {else}
            {if $ssbhesabfa_is_connected}
                <form method="post" action="{$ssbhesabfa_action_url nofilter}" class="ssbhesabfa-admin-order-form mt-2" onsubmit="return confirm('{l s='Register this order in Hesabfa now?' mod='ssbhesabfa' js=1}');">
                    <input type="hidden" name="ssbhesabfa_id_order" value="{$ssbhesabfa_order_id|intval}" />
                    <input type="hidden" name="ssbhesabfa_order_token" value="{$ssbhesabfa_token|escape:'html':'UTF-8'}" />
                    <button type="submit" name="submitSsbhesabfaRegisterOrder" class="btn btn-primary btn-block">
                        <i class="icon-plus"></i> {l s='Register order in Hesabfa' mod='ssbhesabfa'}
                    </button>
                </form>
            {else}
                <div class="alert alert-warning mb-0">
                    {l s='The Hesabfa API is not connected. Please configure the API connection before registering this order.' mod='ssbhesabfa'}
                </div>
            {/if}
        {/if}
    </div>
</div>
