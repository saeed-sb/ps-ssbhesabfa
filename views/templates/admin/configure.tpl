

{assign var=active_tab value=$current_form_tab}
{if $active_tab == null || $active_tab == ''}{assign var=active_tab value='Dashboard'}{/if}

<div class="ssb-admin-wrap {if $is_rtl}ssb-admin-rtl{/if}" dir="{if $is_rtl}rtl{else}ltr{/if}" data-delete-warning="{l s='Warning: if you uninstall the module later, all Hesabfa module tables, logs and saved settings will be permanently deleted. Continue with this setting?' mod='ssbhesabfa'}">
    <div class="ssb-hero">
        <h2>
            <i class="icon-credit-card"></i> {l s='Hesabfa Online Accounting' mod='ssbhesabfa'}
            <span class="ssb-badge {if $live_mode}ssb-badge-ok{else}ssb-badge-bad{/if}">{if $live_mode}{l s='Connected' mod='ssbhesabfa'}{else}{l s='Not connected' mod='ssbhesabfa'}{/if}</span>
            {if $debug_mode}<span class="ssb-badge ssb-badge-bad">{l s='Debug enabled' mod='ssbhesabfa'}</span>{/if}
        </h2>
        <div class="ssb-business-header ssb-module-header"><span class="ssb-business-pill">{l s='Module Version:' mod='ssbhesabfa'} <strong>{$module_version_info|escape:'htmlall':'UTF-8'}</strong></span></div>
        {if $live_mode && isset($hesabfa_business_info.name) && $hesabfa_business_info.name}
            <div class="ssb-business-header">
                <span class="ssb-business-pill"><i class="icon-building"></i> <strong>{$hesabfa_business_info.name|escape:'htmlall':'UTF-8'}</strong></span>
                {if isset($hesabfa_business_info.legal_name) && $hesabfa_business_info.legal_name}<span class="ssb-business-pill">{l s='Legal name:' mod='ssbhesabfa'} <strong>{$hesabfa_business_info.legal_name|escape:'htmlall':'UTF-8'}</strong></span>{/if}
                {if isset($hesabfa_business_info.currency) && $hesabfa_business_info.currency}<span class="ssb-business-pill">{l s='Currency:' mod='ssbhesabfa'} <strong>{$hesabfa_business_info.currency|escape:'htmlall':'UTF-8'}</strong></span>{/if}
                {if isset($hesabfa_business_info.subscription) && $hesabfa_business_info.subscription}<span class="ssb-business-pill">{l s='Plan:' mod='ssbhesabfa'} <strong>{$hesabfa_business_info.subscription|escape:'htmlall':'UTF-8'}</strong></span>{/if}
                {if isset($hesabfa_business_info.expire_date) && $hesabfa_business_info.expire_date}<span class="ssb-business-pill">{l s='Expires:' mod='ssbhesabfa'} <strong>{$hesabfa_business_info.expire_date|escape:'htmlall':'UTF-8'}</strong></span>{/if}
            </div>
        {/if}
    </div>


    {if isset($queue_alert_html) && $queue_alert_html}
        {$queue_alert_html}
    {/if}

    <div class="ssb-layout">
        <div class="ssb-sidebar">
            <ul class="ssb-nav">
                {if $section_allowed.Dashboard}<li class="{if $active_tab == 'Dashboard'}active{/if}"><a href="{$section_urls.Dashboard|escape:'htmlall':'UTF-8'}"><i class="icon-dashboard"></i> {l s='Overview' mod='ssbhesabfa'}</a></li>{/if}
                {if $section_allowed.Settings}<li class="{if $active_tab == 'Settings'}active{/if}"><a href="{$section_urls.Settings|escape:'htmlall':'UTF-8'}"><i class="icon-cogs"></i> {l s='Settings' mod='ssbhesabfa'}</a></li>{/if}
                {if $section_allowed.Payments}<li class="{if $active_tab == 'Payments'}active{/if}"><a href="{$section_urls.Payments|escape:'htmlall':'UTF-8'}"><i class="icon-money"></i> {l s='Payment Methods' mod='ssbhesabfa'}</a></li>{/if}
                {if $section_allowed.ManualPayment}<li class="{if $active_tab == 'ManualPayment'}active{/if}"><a href="{$section_urls.ManualPayment|escape:'htmlall':'UTF-8'}"><i class="icon-credit-card"></i> {l s='Manual Gateway Payment' mod='ssbhesabfa'}</a></li>{/if}
                {if $section_allowed.Sync}<li class="{if $active_tab == 'Sync'}active{/if}"><a href="{$section_urls.Sync|escape:'htmlall':'UTF-8'}"><i class="icon-refresh"></i> {l s='Sync / Repair' mod='ssbhesabfa'}</a></li>{/if}
                {if $section_allowed.Queue}<li class="{if $active_tab == 'Queue'}active{/if}"><a href="{$section_urls.Queue|escape:'htmlall':'UTF-8'}"><i class="icon-tasks"></i> {l s='Request Queue' mod='ssbhesabfa'}</a></li>{/if}
                {if $section_allowed.InternalApi}<li class="{if $active_tab == 'InternalApi'}active{/if}"><a href="{$section_urls.InternalApi|escape:'htmlall':'UTF-8'}"><i class="icon-exchange"></i> {l s='Internal API' mod='ssbhesabfa'}</a></li>{/if}
                {if $section_allowed.Logs}<li class="{if $active_tab == 'Logs'}active{/if}"><a href="{$section_urls.Logs|escape:'htmlall':'UTF-8'}"><i class="icon-list"></i> {l s='Logs / Issues' mod='ssbhesabfa'}</a></li>{/if}
            </ul>
        </div>

        <div class="ssb-content">
            {if $active_tab == 'Dashboard'}
                <div class="ssb-card">
                    <div class="ssb-card-header">
                        <div>
                            <h3>{l s='Operational overview' mod='ssbhesabfa'}</h3>
                            <p>{l s='Use this dashboard to understand the module state and jump to the right workflow.' mod='ssbhesabfa'}</p>
                        </div>
                    </div>
                    <div class="ssb-card-body">
                        <div class="ssb-status-row">
                            <div class="ssb-status-box"><strong>{l s='API status' mod='ssbhesabfa'}</strong>{if $live_mode}{l s='Connected and ready' mod='ssbhesabfa'}{else}{l s='Not connected. Configure API first.' mod='ssbhesabfa'}{/if}</div>
                            <div class="ssb-status-box"><strong>{l s='Payment mapping' mod='ssbhesabfa'}</strong>{l s='Map each payment method to a Hesabfa bank and fee policy.' mod='ssbhesabfa'}</div>
                            <div class="ssb-status-box"><strong>{l s='Internal logs' mod='ssbhesabfa'}</strong>{l s='Logs and sync issues are available in the dedicated controller.' mod='ssbhesabfa'}</div>
                        </div>
                    </div>
                </div>
            {/if}

            {if $active_tab == 'Settings'}
                <div class="ssb-card"><div class="ssb-card-header"><div><h3>{l s='API Connection' mod='ssbhesabfa'}</h3><p>{l s='Enter Hesabfa credentials. Debug logs mask sensitive values.' mod='ssbhesabfa'}</p></div></div><div class="ssb-card-body ssb-form-box">{$Config}</div></div>
                {if $live_mode == true}
                    <div class="ssb-card"><div class="ssb-card-header"><div><h3>{l s='Catalog Settings' mod='ssbhesabfa'}</h3><p>{l s='Configure barcode, price and stock sync behavior.' mod='ssbhesabfa'}</p></div></div><div class="ssb-card-body ssb-form-box">{$Item}</div></div>
                    <div class="ssb-card"><div class="ssb-card-header"><div><h3>{l s='Customer Settings' mod='ssbhesabfa'}</h3><p>{l s='Configure customer/contact synchronization.' mod='ssbhesabfa'}</p></div></div><div class="ssb-card-body ssb-form-box">{$Contact}</div></div>
                    <div class="ssb-card"><div class="ssb-card-header"><div><h3>{l s='Invoice Settings' mod='ssbhesabfa'}</h3><p>{l s='Configure invoice references, salesman, project and return invoice behavior.' mod='ssbhesabfa'}</p></div></div><div class="ssb-card-body ssb-form-box">{$Invoice}</div></div>
                    <div class="ssb-card"><div class="ssb-card-header"><div><h3>{l s='Accounting Texts' mod='ssbhesabfa'}</h3><p>{l s='Editable descriptions used in Hesabfa receipts and accounting documents.' mod='ssbhesabfa'}</p></div></div><div class="ssb-card-body ssb-form-box">{$AccountingText}</div></div>
                {/if}
            {/if}

            {if $active_tab == 'Payments'}
                {if $live_mode == true}
                    <div class="ssb-card"><div class="ssb-card-header"><div><h3>{l s='Payment Methods / Banks' mod='ssbhesabfa'}</h3><p>{l s='Each gateway has its own Hesabfa bank, fee policy, income account path and optional contact code.' mod='ssbhesabfa'}</p></div></div><div class="ssb-card-body ssb-form-box">{$Bank}</div></div>
                {else}
                    <div class="ssb-card"><div class="ssb-card-body"><div class="alert alert-info">{l s='Payment mapping is disabled until the API connection is configured.' mod='ssbhesabfa'}</div></div></div>
                {/if}
            {/if}

            {if $active_tab == 'ManualPayment'}
                {if $live_mode == true}
                    <div class="ssb-card"><div class="ssb-card-header"><div><h3>{l s='Manual Gateway Payment' mod='ssbhesabfa'}</h3><p>{l s='Register gateway payments and related income documents manually when needed.' mod='ssbhesabfa'}</p></div></div><div class="ssb-card-body ssb-form-box">{$ManualGatewayPayment}</div></div>
                {else}
                    <div class="ssb-card"><div class="ssb-card-body"><div class="alert alert-info">{l s='Manual gateway payment is disabled until the API connection is configured.' mod='ssbhesabfa'}</div></div></div>
                {/if}
            {/if}

            {if $active_tab == 'Sync'}
                <div class="ssb-card">
                    <div class="ssb-card-header"><div><h3>{l s='Sync / Repair Workflow' mod='ssbhesabfa'}</h3><p>{l s='Sync price and stock safely. Item code remapping is handled by a separate repair action.' mod='ssbhesabfa'}</p></div></div>
                    <div class="ssb-card-body">
                        <div class="ssb-info-note">{l s='Price/stock sync no longer changes Hesabfa item code mappings automatically. If a code mismatch is detected, use Repair Hesabfa Item Codes after reviewing the result.' mod='ssbhesabfa'}</div>
                        <div class="ssb-grid">
                            <div class="ssb-action-card"><h4>{l s='Sync changes' mod='ssbhesabfa'}</h4><p>{l s='Pull Hesabfa changes through the webhook workflow.' mod='ssbhesabfa'}</p><form action="{$sync_action_url|escape:'htmlall':'UTF-8'}" method="post" class="ssb-confirm-loader-form" data-confirm="{l s='Sync Hesabfa changes now?' mod='ssbhesabfa'}"><button type="submit" class="btn btn-primary" name="submitSsbhesabfaSyncChanges"><i class="icon-refresh"></i> {l s='Sync changes' mod='ssbhesabfa'}</button></form></div>
                            <div class="ssb-action-card"><h4>{l s='Sync price and stock' mod='ssbhesabfa'}</h4><p>{l s='Update PrestaShop product price and quantity from Hesabfa without remapping item codes.' mod='ssbhesabfa'}</p><form action="{$sync_action_url|escape:'htmlall':'UTF-8'}" method="post" class="ssb-confirm-loader-form" data-confirm="{l s='Sync product price and stock now?' mod='ssbhesabfa'}"><button type="submit" class="btn btn-primary" name="submitSsbhesabfaSyncProducts"><i class="icon-refresh"></i> {l s='Sync price/stock' mod='ssbhesabfa'}</button></form></div>
                            <div class="ssb-action-card ssb-warning-card"><h4>{l s='Scan item code mismatches' mod='ssbhesabfa'}</h4><p>{l s='List Hesabfa and PrestaShop code differences so each mapping can be approved manually.' mod='ssbhesabfa'}</p><form action="{$sync_action_url|escape:'htmlall':'UTF-8'}" method="post" class="ssb-confirm-loader-form" data-confirm="{l s='Scan item code mismatches now?' mod='ssbhesabfa'}"><button type="submit" class="btn btn-warning" name="submitSsbhesabfaRepairItemCodes"><i class="icon-search"></i> {l s='Scan mismatches' mod='ssbhesabfa'}</button></form></div>
                            <div class="ssb-action-card"><h4>{l s='Export products' mod='ssbhesabfa'}</h4><p>{l s='Create or update store products in Hesabfa in batches of 100.' mod='ssbhesabfa'}</p><button type="button" class="btn btn-primary ssb-ajax-export-btn" data-export-type="products" data-confirm="{l s='Export all products to Hesabfa?' mod='ssbhesabfa'}"><i class="icon-upload"></i> {l s='Export products' mod='ssbhesabfa'}</button></div>
                            <div class="ssb-action-card ssb-warning-card"><h4>{l s='Export opening quantity' mod='ssbhesabfa'}</h4><p>{l s='Use only at the beginning of the fiscal year after products are exported.' mod='ssbhesabfa'}</p><form action="{$export_action_url|escape:'htmlall':'UTF-8'}" method="post" class="ssb-confirm-loader-form" data-confirm="{l s='Export opening quantity to Hesabfa?' mod='ssbhesabfa'}"><button type="submit" class="btn btn-warning" name="submitSsbhesabfaSetOpeningQuantity"><i class="icon-upload"></i> {l s='Export opening quantity' mod='ssbhesabfa'}</button></form></div>
                            <div class="ssb-action-card"><h4>{l s='Export customers' mod='ssbhesabfa'}</h4><p>{l s='Create or update store customers in Hesabfa in batches of 100.' mod='ssbhesabfa'}</p><button type="button" class="btn btn-primary ssb-ajax-export-btn" data-export-type="customers" data-confirm="{l s='Export all customers to Hesabfa?' mod='ssbhesabfa'}"><i class="icon-upload"></i> {l s='Export customers' mod='ssbhesabfa'}</button></div>
                        </div>
                        <p id="sync_loader" class="ssb-sync-loader"><img src="../img/loader.gif" alt="" /></p>
                        <div id="ssb_ajax_export_progress" class="ssb-ajax-export-progress" data-ajax-url="{$export_action_url|escape:'htmlall':'UTF-8'}" data-msg-invalid-response="{l s='Invalid server response.' mod='ssbhesabfa'}" data-msg-export-completed="{l s='Export completed.' mod='ssbhesabfa'}" data-msg-ajax-failed="{l s='Ajax request failed. The export can be started again and will continue from the last stored position.' mod='ssbhesabfa'}" data-msg-export-products="{l s='Export products' mod='ssbhesabfa'}" data-msg-export-customers="{l s='Export customers' mod='ssbhesabfa'}" data-msg-starting-export="{l s='Starting export...' mod='ssbhesabfa'}">
                            <strong id="ssb_ajax_export_title">{l s='Export progress' mod='ssbhesabfa'}</strong>
                            <div class="progress"><div id="ssb_ajax_export_bar" class="progress-bar progress-bar-success ssb-progress-bar-empty" role="progressbar">0%</div></div>
                            <div id="ssb_ajax_export_status" class="text-muted"></div>
                            <div id="ssb_ajax_export_log" class="ssb-ajax-export-log"></div>
                        </div>

                    </div>
                </div>
                {$repair_mismatches_html}
            {/if}

            {if $active_tab == 'Queue'}
                {$job_queue_html}
            {/if}

            {if $active_tab == 'InternalApi'}
                {$internal_api_html}
            {/if}

            {if $active_tab == 'Logs'}
                {$module_logs_html}
            {/if}
        </div>
    </div>
</div>


