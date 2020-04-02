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
        <li class="nav-item {if $current_form_tab == null}active{/if}">
            <a class="nav-link active" id="home-tab" data-toggle="tab" href="#home" role="tab" aria-controls="home" aria-selected="true">Home</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Item'}active{/if}">
            <a class="nav-link" id="catalog-tab" data-toggle="tab" href="#catalog" role="tab" aria-controls="catalog" aria-selected="false">Catalog</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Invoice'}active{/if}">
            <a class="nav-link" id="orders-tab" data-toggle="tab" href="#orders" role="tab" aria-controls="orders" aria-selected="false">Orders</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Contact'}active{/if}">
            <a class="nav-link" id="customers-tab" data-toggle="tab" href="#customers" role="tab" aria-controls="customers" aria-selected="false">Customers</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Bank'}active{/if}">
            <a class="nav-link" id="payment-tab" data-toggle="tab" href="#payment" role="tab" aria-controls="payment" aria-selected="false">Payment Methods</a>
        </li>
        <li class="nav-item {if $current_form_tab == 'Config' || $current_form_tab == 'Test'}active{/if}">
            <a class="nav-link" id="api-tab" data-toggle="tab" href="#api" role="tab" aria-controls="api" aria-selected="false">API</a>
        </li>
    </ul>
    <!-- Tab panes -->
    <div class="tab-content">
        <div class="tab-pane {if $current_form_tab == null}active{/if}" id="home" role="tabpanel" aria-labelledby="home-tab">
            <div class="panel" style="border-top-left-radius: 0px;">
                <h1>Hesabfa Accounting</h1>
                <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Sed sed risus pretium quam. Tellus elementum sagittis vitae et leo duis ut diam. Posuere lorem ipsum dolor sit amet. Scelerisque felis imperdiet proin fermentum leo vel. Ornare suspendisse sed nisi lacus sed viverra tellus in. Elit scelerisque mauris pellentesque pulvinar. Cursus risus at ultrices mi. Scelerisque viverra mauris in aliquam. Sed euismod nisi porta lorem mollis aliquam ut porttitor leo. Ullamcorper morbi tincidunt ornare massa. Arcu cursus euismod quis viverra nibh. Pellentesque habitant morbi tristique senectus et netus et malesuada fames. Neque convallis a cras semper auctor neque vitae. Erat pellentesque adipiscing commodo elit at imperdiet dui accumsan. Pharetra magna ac placerat vestibulum lectus mauris ultrices eros in. Mattis ullamcorper velit sed ullamcorper morbi tincidunt.</p>
            </div>
        </div>
        <div class="tab-pane {if $current_form_tab == 'Item'}active{/if}" id="catalog" role="tabpanel" aria-labelledby="catalog-tab">{$Item}</div>
        <div class="tab-pane {if $current_form_tab == 'Invoice'}active{/if}" id="orders" role="tabpanel" aria-labelledby="orders-tab">{$Invoice}</div>
        <div class="tab-pane {if $current_form_tab == 'Contact'}active{/if}" id="customers" role="tabpanel" aria-labelledby="customers-tab">{$Contact}</div>
        <div class="tab-pane {if $current_form_tab == 'Bank'}active{/if}" id="payment" role="tabpanel" aria-labelledby="payment-tab">{$Bank}</div>
        <div class="tab-pane {if $current_form_tab == 'Config' || $current_form_tab == 'Test'}active{/if}" id="api" role="tabpanel" aria-labelledby="api-tab">{$Config}</div>
    </div>
</div>