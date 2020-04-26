{*
* 2007-2020 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2020 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}

<div class="panel">
    <h3><i class="icon icon-credit-card"></i> {l s='Settings' mod='ssbhesabfa'}</h3>
    <!-- Nav tabs -->
    <ul class="nav nav-tabs" id="myTab" role="tablist">
        <li class="nav-item {if $current_form_tab == null || $current_form_tab == 'Home'}active{/if}">
            <a class="nav-link active" id="home-tab" data-toggle="tab" href="#home" role="tab" aria-controls="home" aria-selected="true">{l s='Home' mod='ssbhesabfa'}</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Item'}active{/if}">
            <a class="nav-link" id="catalog-tab" data-toggle="tab" href="#catalog" role="tab" aria-controls="catalog" aria-selected="false">{l s='Catalog' mod='ssbhesabfa'}</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Contact'}active{/if}">
            <a class="nav-link" id="customers-tab" data-toggle="tab" href="#customers" role="tab" aria-controls="customers" aria-selected="false">{l s='Customers' mod='ssbhesabfa'}</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Bank'}active{/if}">
            <a class="nav-link" id="payment-tab" data-toggle="tab" href="#payment" role="tab" aria-controls="payment" aria-selected="false">{l s='Payment Methods' mod='ssbhesabfa'}</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Config' || $current_form_tab == 'Test'}active{/if}">
            <a class="nav-link" id="api-tab" data-toggle="tab" href="#api" role="tab" aria-controls="api" aria-selected="false">{l s='API' mod='ssbhesabfa'}</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Export'}active{/if}">
            <a class="nav-link" id="export-tab" data-toggle="tab" href="#export" role="tab" aria-controls="export" aria-selected="false">{l s='Export' mod='ssbhesabfa'}</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Sync'}active{/if}">
            <a class="nav-link" id="sync-tab" data-toggle="tab" href="#sync" role="tab" aria-controls="sync" aria-selected="false">{l s='Sync' mod='ssbhesabfa'}</a>
        </li>

    </ul>
    <!-- Tab panes -->
    <div class="tab-content">
        <div class="tab-pane {if $current_form_tab == null || $current_form_tab == 'Home'}active{/if}" id="home" role="tabpanel" aria-labelledby="home-tab">
            <div class="panel" style="border-top-left-radius: 0px;">
                <h1>{l s='Hesabfa Accounting' mod='ssbhesabfa'}</h1>
                <p>{l s='This module helps connect your (online) store to Hesabfa online accounting software. By using this module, saving products, contacts, and orders in your store will also save them automatically in your Hesabfa account. Besides that, just after a client pays a bill, the receipt document will be stored in Hesabfa as well. Of course, you have to register your account in Hesabfa first. To do so, visit Hesabfa at the link here www.hesabfa.com and sign up for free. After you signed up and entered your account, choose your business, then in the settings menu/API, you can find the API keys for the business and import them to the module’s settings. Now your module is ready to use.' mod='ssbhesabfa'}</p>
                <p>{l s='For more information and a full guide to how to use Hesabfa and PerstaShop module, visit Hesabfa’s website and go to the “Accounting School” menu.' mod='ssbhesabfa'}</p>

                <div class="row">
                    <div class="col-lg-4">
                        <form action="{$update_action_url|escape:'htmlall':'UTF-8'}" method="post">
                            <button {if isset($need_update)}style="display: none" {/if}type="submit" class="btn btn-primary btn-md" id="submitSsbhesabfaModuleUpdate" name="submitSsbhesabfaModuleUpdate" onclick="">{l s='Check Update' mod='ssbhesabfa'}</button>
                            <button {if !isset($need_update) || $need_update == false || is_null($need_update)}style="display: none;" {/if}type="submit" class="btn btn-primary btn-md" id="submitSsbhesabfaModuleUpgrade" name="submitSsbhesabfaModuleUpgrade" onclick="">{l s='Upgrade Module' mod='ssbhesabfa'}</button>
                            {l s='Module Version: v'}{$module_ver}
                        </form>
                        <br>
                        {if isset($notices)}{$notices}{/if}
                        {if isset($upgrade) && $upgrade == true}
                            {$upgrade}
                        {/if}
                    </div>
                </div>
            </div>
        </div>
        <div class="tab-pane {if $current_form_tab == 'Item'}active{/if}" id="catalog" role="tabpanel" aria-labelledby="catalog-tab">{$Item}</div>
        <div class="tab-pane {if $current_form_tab == 'Contact'}active{/if}" id="customers" role="tabpanel" aria-labelledby="customers-tab">{$Contact}</div>
        <div class="tab-pane {if $current_form_tab == 'Bank'}active{/if}" id="payment" role="tabpanel" aria-labelledby="payment-tab">
            {if $live_mode == true}
                {$Bank}
            {else}
            <div class="panel">
                <br>
                <div class="alert alert-info" role="alert">
                    <p>{l s='Bank Maping Disabled, Please set API First.' mod='ssbhesabfa'}</p>
                </div>
            </div>
            {/if}
        </div>
        <div class="tab-pane {if $current_form_tab == 'Config' || $current_form_tab == 'Test'}active{/if}" id="api" role="tabpanel" aria-labelledby="api-tab">{$Config}</div>
        <div class="tab-pane {if $current_form_tab == 'Export'}active{/if}" id="export" role="tabpanel" aria-labelledby="export-tab">
            <div class="panel">
                <div class="alert alert-info" role="alert">
                    {l s='Export/Sync can take several minutes.' mod='ssbhesabfa'}</p>
                </div>
                <div class="margin-form" style="clear: both;">
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#exportProducts">
                        {l s='Export products' mod='ssbhesabfa'}
                    </button>
                    <div class="modal fade" id="exportProducts" tabindex="-1" role="dialog" aria-labelledby="exportProductsLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-body">
                                    <p>{l s='Are you sure you want to add/update all Products into Hesabfa?'}</p>
                                </div>
                                <div class="modal-footer">
                                    <form action="{$export_action_url|escape:'htmlall':'UTF-8'}" method="post">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{l s='Close'}</button>
                                        <button type="submit" class="btn btn-primary btn-md" id="submitSsbhesabfaExportProducts" name="submitSsbhesabfaExportProducts" onclick="$('#export_loader').show();">{l s='Export products' mod='ssbhesabfa'}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!--
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#exportProductsWithQuantity">
                        {l s='Export products with Quantity' mod='ssbhesabfa'}
                    </button>
                    <div class="modal fade" id="exportProductsWithQuantity" tabindex="-1" role="dialog" aria-labelledby="exportProductsWithQuantityLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-body">
                                    <p>{l s='Are you sure you want to add/update all Products into Hesabfa with Quantity?'}</p>
                                </div>
                                <div class="modal-footer">
                                    <form action="{$export_action_url|escape:'htmlall':'UTF-8'}" method="post">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{l s='Close'}</button>
                                        <button type="submit" class="btn btn-primary btn-md" id="submitSsbhesabfaExportProductsWithQuantity" name="submitSsbhesabfaExportProductsWithQuantity" onclick="$('#export_loader').show();">{l s='Export products with Quantity' mod='ssbhesabfa'}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    -->
                    <p>{l s='Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor' mod='ssbhesabfa'}<br></p>
                    <br>
                </div>
                <div class="margin-form" style="clear: both;">
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#exportCustomers">
                        {l s='Export Customers' mod='ssbhesabfa'}
                    </button>

                    <p>{l s='Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor' mod='ssbhesabfa'}<br></p>
                    <br>
                    <div class="modal fade" id="exportCustomers" tabindex="-1" role="dialog" aria-labelledby="exportCustomersLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-body">
                                    <p>{l s='Are you sure you want to add/update all Customers into Hesabfa?'}</p>
                                </div>
                                <div class="modal-footer">
                                    <form action="{$export_action_url|escape:'htmlall':'UTF-8'}" method="post">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{l s='Close'}</button>
                                        <button type="submit" class="btn btn-primary btn-md" id="submitSsbhesabfaExportCustomers" name="submitSsbhesabfaExportCustomers" onclick="$('#export_loader').show();">{l s='Export customers' mod='ssbhesabfa'}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
                <p id="export_loader" style="text-align: center; display: none;"><img src="../img/loader.gif" alt=""/></p>
            </div>
        </div>
        <div class="tab-pane {if $current_form_tab == 'Sync'}active{/if}" id="sync" role="tabpanel" aria-labelledby="sync-tab">
            <div class="panel">
                <div class="alert alert-info" role="alert">
                    {l s='Export/Sync can take several minutes.' mod='ssbhesabfa'}</p>
                </div>
                <div class="margin-form" style="clear: both;">
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#syncChanges" onclick="$('#sync_loader').show();">
                        {l s='Sync Changes' mod='ssbhesabfa'}
                    </button>
                    <p>{l s='Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor' mod='ssbhesabfa'}<br></p>
                    <br>
                    <div class="modal fade" id="syncChanges" tabindex="-1" role="dialog" aria-labelledby="syncChangesLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-body">
                                    <p>{l s='Are you sure you want to Sync all changes with Hesabfa?'}</p>
                                </div>
                                <div class="modal-footer">
                                    <form action="{$sync_action_url|escape:'htmlall':'UTF-8'}" method="post">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{l s='Close'}</button>
                                        <button type="submit" class="btn btn-primary btn-md" id="submitSsbhesabfaSyncChanges" name="submitSsbhesabfaSyncChanges" onclick="$('#sync_loader').show();">{l s='Sync Changes' mod='ssbhesabfa'}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="margin-form" style="clear: both;">
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#syncProducts" onclick="$('#sync_loader').show();">
                        {l s='Sync Products Quantity and Price' mod='ssbhesabfa'}
                    </button>
                    <p>{l s='Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor' mod='ssbhesabfa'}<br></p>
                    <br>
                    <div class="modal fade" id="syncProducts" tabindex="-1" role="dialog" aria-labelledby="syncProductsLabel" aria-hidden="true">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-body">
                                    <p>{l s='Are you sure you want to Sync all Products price and quantity with Hesabfa?'}</p>
                                </div>
                                <div class="modal-footer">
                                    <form action="{$sync_action_url|escape:'htmlall':'UTF-8'}" method="post">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{l s='Close'}</button>
                                        <button type="submit" class="btn btn-primary btn-md" id="submitSsbhesabfaSyncProducts" name="submitSsbhesabfaSyncProducts" onclick="$('#sync_loader').show();">{l s='Sync Products' mod='ssbhesabfa'}</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="margin-form" style="clear: both;">
                    <form action="{$sync_action_url|escape:'htmlall':'UTF-8'}" method="post">
                        <div class="col-lg-3">
                            <div class="row">
                                <div class="input-group">
                                    <input class="datetimepicker" type="text" id="SSBHESABFA_SYNC_ORDER_FROM" name="SSBHESABFA_SYNC_ORDER_FROM">
                                    <script type="text/javascript">
                                        $(document).ready(function(){
                                            $(".datetimepicker").datepicker({
                                                prevText: '',
                                                nextText: '',
                                                dateFormat: 'yy-mm-dd'
                                            });
                                        });
                                    </script>
                                <span class="input-group-addon">
                                    <i class="icon-calendar-empty"></i>
                                </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-9">

                            <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#exportInvoices">
                                {l s='Sync Orders' mod='ssbhesabfa'}
                            </button>
                        </div>
                        <p>{l s='Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor' mod='ssbhesabfa'}<br></p>
                        <br>
                        <div class="modal fade" id="exportInvoices" tabindex="-1" role="dialog" aria-labelledby="exportInvoicesLabel" aria-hidden="true">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <div class="modal-body">
                                        <p>{l s='Are you sure you want to Sync all orders with Hesabfa?'}</p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">{l s='Close'}</button>
                                        <button type="submit" class="btn btn-primary btn-md" id="submitSsbhesabfaExportInvoices" name="submitSsbhesabfaExportInvoices" onclick="$('#export_loader').show();">{l s='Sync Orders' mod='ssbhesabfa'}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                <p id="sync_loader" style="text-align: center; display: none;"><img src="../img/loader.gif" alt=""/></p>
            </div>
        </div>
    </div>
</div>