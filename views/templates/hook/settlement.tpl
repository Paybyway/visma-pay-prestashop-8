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

<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-header-title">
            <img src="{$logo_url}">
            <span>{l s='Visma Pay payment settlement' mod='vismapay'}</span>
        </h3>
    </div>
    <div class="card-body">
        {if $message}
            {$message}
        {/if}

        {if $show_button}
            <form action="" method="POST">
                <button type="submit" class="btn btn-primary" id="vismapay_settlement" name="vismapay_settlement">{l s='Settle payment' mod='vismapay'}</button>
            </form>
            <br />
        {/if}
    </div>
</div>
