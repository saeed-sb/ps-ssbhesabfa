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
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2020 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}

<div class="alert alert-info" role="alert">
    <i class="material-icons">help</i>
    <p class="alert-text">{l s='If this product is already defined in the Hesabfa, enter the accounting code in the field below.' mod='ssbhesabfa'}</p>
</div>

<div class="row">
    <div class="col-xl-4 col-lg-4">
        <fieldset class="form-group">
            <label class="form-control-label" for="ssbhesabfa_hesabfa_item_code_0">{l s='Base Hesabfa item code' mod='ssbhesabfa'}</label>
            <input id="ssbhesabfa_hesabfa_item_code_0" name="ssbhesabfa_hesabfa_item_code_0" type="text" class="form-control" value="{$hesabfa_item_code|escape:'htmlall':'UTF-8'}"/>
        </fieldset>
    </div>
    {if $combinations != false}
    <div class="col-xl-8 col-lg-8">
        <table class="table">
            <thead class="thead-default">
                <tr>
                    <th>#</th>
                    <th>{l s='Combination' mod='ssbhesabfa'}</th>
                    <th>{l s='Combination Hesabfa item code' mod='ssbhesabfa'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$combinations item=item}
                <tr>
                    <th scope="row">1</th>
                    <td>{$item['name']|escape:'htmlall':'UTF-8'}</td>
                    <td><input id="{$item['id_hesabfa_item_code']|escape:'htmlall':'UTF-8'}" name="{$item['id_hesabfa_item_code']|escape:'htmlall':'UTF-8'}" type="text" class="form-control" value="{$item['hesabfa_item_code']|escape:'htmlall':'UTF-8'}"/></td>
                </tr>
                {/foreach}
            </tbody>
        </table>
    </div>
    {/if}
</div>

