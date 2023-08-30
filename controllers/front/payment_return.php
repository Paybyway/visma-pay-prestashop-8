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
 * Class for Visma Pay payment return front controller
 */
class VismaPayPayment_ReturnModuleFrontController extends ModuleFrontController
{
    /**
     * Method for processing Visma Pay payment request return
     */
    public function postProcess(): void
    {
        /* @var VismaPay $module */
        $this->module = $this->module;

        if ($this->module->paymentReturn->validateRequest()) {
            $url = $this->context->link->getPageLink(
                'cart',
                null,
                $this->context->language->id,
                ['action' => 'show']
            );
            $this->context->smarty->assign('vp_link', $url);
            $this->context->smarty->assign('vp_error', $this->module->l('Payment failed.', 'payment_return'));
            $this->setTemplate('module:vismapay/views/templates/front/payment_error.tpl');
        } else {
            $returnCode = (int) Tools::getValue('RETURN_CODE');
            $cartId = (int) Tools::getValue('id_cart');
            $isSettled = (bool) Tools::getValue('SETTLED');
            $moduleId = (int) $this->module->id;
            $cartSecureKey = Tools::getValue('key');

            if ($returnCode === 0) {
                if ($errorMessage = $this->module->paymentReturn->checkAuthcode()) {
                    $this->module->paymentReturn->updateOrderFailStatus($errorMessage);
                } elseif ($errorMessage = $this->module->paymentReturn->checkOrderNumber()) {
                    $this->module->paymentReturn->updateOrderFailStatus($errorMessage);
                } else {
                    $statusMessage = $this->module->paymentReturn->getPaymentStatusMessage(true);

                    if ($this->module->paymentReturn->isCorrectAmount() || $isSettled) {
                        $this->module->paymentReturn->updateOrderSuccessStatus($statusMessage);
                    } else {
                        $this->module->paymentReturn->updateOrderFailStatus($statusMessage);
                    }
                }

                Tools::redirectLink(__PS_BASE_URI__ . "order-confirmation.php?key=$cartSecureKey&id_cart=$cartId&id_module=$moduleId");
            } else {
                if ((bool) Configuration::get('VP_CLEAR_CART')) {
                    $this->module->paymentReturn->clearCart($cartId);
                }

                $returnFailedMessage = $this->module->paymentReturn->getFailedReturnMessage();
                PrestaShopLogger::addLog($returnFailedMessage, 3, null, null, null, true);

                $url = $this->context->link->getPageLink(
                    'cart',
                    null,
                    $this->context->language->id,
                    ['action' => 'show']
                );
                $this->context->smarty->assign('vp_link', $url);
                $this->context->smarty->assign('vp_error', $this->module->l('Payment failed.', 'payment_return'));
                $this->setTemplate('module:vismapay/views/templates/front/payment_error.tpl');
            }
        }
    }
}
