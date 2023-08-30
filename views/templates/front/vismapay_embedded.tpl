{**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
<div class="clearfix">
    <form method="POST" action="{$action}" id="payment-form">
        {if !empty($paymentMethods)}
            {foreach from=$paymentMethods.banks key=index item=method}
                <div class="col-xs-12 col-md-6">
                    <div class="vismapay-payment-method">
                    <span class="custom-radio pull-xs-left vismapay-checkbox">
                        <input class="ps-shown-by-js " type="radio" required id="pm-{$method.value}-{$index}" name="selected" value="{$method.value}" />
                        <span></span>
                    </span>
                    <label for="pm-{$method.value}-{$index}">
                        <span><img class="vismapay-pm-logo-pointer" src="{$imgDir}{$method.value}.png" alt="{$method.name}"/></span>
                    </label>
                    </div>
                </div>
            {/foreach}
            {foreach from=$paymentMethods.creditcards key=index item=method}
                <div class="col-xs-12 col-md-6">
                    <div class="vismapay-payment-method">
                    <span class="custom-radio pull-xs-left vismapay-checkbox">
                        <input class="ps-shown-by-js " type="radio" required id="pm-{$method.value}-{$index}" name="selected" value="{$method.value}" />
                        <span></span>
                    </span>
                    <label for="pm-{$method.value}-{$index}">
                        <span><img class="vismapay-pm-logo-pointer" src="{$imgDir}{strtolower(str_replace(' ', '', $method.name))}.png" alt="{$method.name}"/></span>
                    </label>
                    </div>
                </div>
            {/foreach}
            {foreach from=$paymentMethods.wallets key=index item=method}
                <div class="col-xs-12 col-md-6">
                    <div class="vismapay-payment-method">
                    <span class="custom-radio pull-xs-left vismapay-checkbox">
                        <input class="ps-shown-by-js " type="radio" required id="pm-{$method.value}-{$index}" name="selected" value="{$method.value}" />
                        <span></span>
                    </span>
                    <label for="pm-{$method.value}-{$index}">
                        <span><img class="vismapay-pm-logo-pointer" src="{$imgDir}{$method.value}.png" alt="{$method.name}"/></span>
                    </label>
                    </div>
                </div>
            {/foreach}
            {foreach from=$paymentMethods.creditinvoices key=index item=method}
                <div class="col-xs-12 col-md-6">
                    <div class="vismapay-payment-method">
                    <span class="custom-radio pull-xs-left vismapay-checkbox">
                        <input class="ps-shown-by-js " type="radio" required id="pm-{$method.value}-{$index}" name="selected" value="{$method.value}" />
                        <span></span>
                    </span>
                    <label for="pm-{$method.value}-{$index}">
                        <span><img class="vismapay-pm-logo-pointer" src="{$imgDir}{$method.value}.png" alt="{$method.name}"/></span>
                    </label>
                    </div>
                </div>
            {/foreach}
        {/if}
        <div class="clearfix col-md-12"></div>
    </form>
</div>
