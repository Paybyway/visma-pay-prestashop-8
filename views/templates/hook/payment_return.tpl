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
{if $status == 'ok'}
    <p>{l s='Your order is complete.' d='Modules.Vismapay.VismaPayPaymentReturn'}
        <br /><br /><span class="font-weight-bold">{l s='Your order will be shipped as soon as possible.' d='Modules.Vismapay.VismaPayPaymentReturn'}</span>
        <br /><br />{l s='For any questions or for further information, please contact our' d='Modules.Vismapay.VismaPayPaymentReturn'} <a href="{$link->getPageLink('contact', true)}">{l s='customer support' d='Modules.Vismapay.VismaPayPaymentReturn'}</a>.
    </p>
{else}
    <p class="alert alert-warning warning">{l s='Your payment was not accepted. If you think this is an error, contact our support' d='Modules.Vismapay.VismaPayPaymentReturn'}</p>
{/if}
