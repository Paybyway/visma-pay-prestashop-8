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

namespace VismaPayModule\Service;

use PrestaShop\PrestaShop\Adapter\Entity\PrestaShopLogger;
use Symfony\Contracts\Translation\TranslatorInterface;
use VismaPay\VismaPay as VismaPaySDK;
use VismaPay\VismaPayException;

class SettlementService
{
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Perform settlement for a given order ID.
     *
     * @return array{success: bool, message: string}
     */
    public function settle(int $orderId): array
    {
        if (!\Module::isEnabled('vismapay')) {
            return [
                'success' => false,
                'message' => $this->translator->trans('Visma Pay module is disabled.', [], 'Modules.Vismapay.VismaPayOrderController'),
            ];
        }

        $order = new \Order($orderId);

        if (!\Validate::isLoadedObject($order)) {
            return [
                'success' => false,
                'message' => $this->translator->trans('Order not found.', [], 'Modules.Vismapay.VismaPayOrderController'),
            ];
        }

        $cartId = (int) $order->id_cart;
        $query = \Db::getInstance()->getRow('SELECT order_number FROM ' . _DB_PREFIX_ . 'vismapay_order WHERE cart_id=' . $cartId);
        $orderNumber = is_array($query) ? ($query['order_number'] ?? null) : null;
        if (!$orderNumber) {
            return [
                'success' => false,
                'message' => $this->translator->trans('Order number not found for settlement.', [], 'Modules.Vismapay.VismaPayOrderController'),
            ];
        }

        $privatekey = (string) \Configuration::get('VP_PRIVATE_KEY');
        $apikey = (string) \Configuration::get('VP_API_KEY');
        $api = new VismaPaySDK($apikey, $privatekey);

        try {
            $settlement = $api->settlePayment($orderNumber);
            $returnCode = $settlement->result;

            switch ($returnCode) {
                case 0:
                    $date = date('Y-m-d H:i:s');
                    $message = $this->translator->trans('Payment settled.', [], 'Modules.Vismapay.VismaPayOrderController');
                    \Db::getInstance()->execute('INSERT INTO ' . _DB_PREFIX_ . 'vismapay_order_message (`cart_id`, `date`, `message`) VALUES (' . $cartId . ", '" . pSQL($date) . "', '" . pSQL($message) . "')");
                    $order->setCurrentState((int) \Configuration::get('PS_OS_PAYMENT'));

                    return ['success' => true, 'message' => $message];
                case 1:
                    return [
                        'success' => false,
                        'message' => $this->translator->trans('Request failed. Validation failed.', [], 'Modules.Vismapay.VismaPayOrderController'),
                    ];
                case 2:
                    return [
                        'success' => false,
                        'message' => $this->translator->trans('Payment cannot be settled. Either the payment has already been settled or the payment gateway refused to settle payment for given transaction.', [], 'Modules.Vismapay.VismaPayOrderController'),
                    ];
                case 3:
                    return [
                        'success' => false,
                        'message' => $this->translator->trans('Payment cannot be settled. Transaction for given order number was not found.', [], 'Modules.Vismapay.VismaPayOrderController'),
                    ];
                default:
                    return [
                        'success' => false,
                        'message' => $this->translator->trans('Unexpected error during the settlement.', [], 'Modules.Vismapay.VismaPayOrderController'),
                    ];
            }
        } catch (VismaPayException $e) {
            // SDK-provided messages are returned as-is since they are not part of our translation catalogue
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Error settling Visma Pay payment. Details: ' . $e->getMessage(), 3, null, null, null, true);

            return [
                'success' => false,
                'message' => $this->translator->trans('An unexpected error occurred.', [], 'Modules.Vismapay.VismaPayOrderController'),
            ];
        }
    }
}
