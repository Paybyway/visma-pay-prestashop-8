<?php
/**
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
 */

declare(strict_types=1);

/**
 * Class for Visma Pay payment front controller
 */
class VismaPayPaymentModuleFrontController extends ModuleFrontController
{
    /**
     * Method for processing Visma Pay payment request
     */
    public function postProcess(): void
    {
        // Check that module is registered as payment module
        if (array_search($this->module->name, array_column(Module::getPaymentModules(), 'name')) === false) {
            exit($this->module->getTranslator()->trans('Payment method is not available.', [], 'Modules.Vismapay.VismaPayPayment'));
        }

        $cart = $this->context->cart;

        // Redirect back to order if Prestashop data is invalid
        if (!isset($cart) || $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('order?step=1');
        }

        $paymentUrl = $this->module->payment->createPayment($cart);

        // If creating payment fails then show error
        if (!$paymentUrl) {
            $url = $this->context->link->getPageLink(
                'cart',
                null,
                $this->context->language->id,
                ['action' => 'show']
            );
            $this->context->smarty->assign('vp_link', $url);
            $this->context->smarty->assign('vp_error', $this->module->getTranslator()->trans('Payment failed, please try again.', [], 'Modules.Vismapay.VismaPayPayment'));
            $this->setTemplate('module:vismapay/views/templates/front/payment_error.tpl');
        } else {
            // Clear cart if setting is enabled
            if ((bool) Configuration::get('VP_CLEAR_CART')) {
                $cart = new Cart($cart->id);
                $this->context->cookie->__unset('id_cart');
                $this->context->cart = new Cart();
                $this->context->cookie->write();
            }

            $this->setRedirectAfter($paymentUrl);
        }
    }
}
