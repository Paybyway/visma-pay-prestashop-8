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

use PrestaShop\PrestaShop\Adapter\Entity\PrestaShopLogger;

/**
 * Class for handling module's payment options.
 */
class VismaPayPaymentReturn
{
    /**
     * @var VismaPay Reference to the module instance
     */
    private VismaPay $module;

    /**
     * @var Context Prestashop context
     */
    private Context $context;

    /**
     * @var string Filename for translations
     */
    private string $filename;

    /**
     * Constructor for handler.
     *
     * @var VismaPay Reference to the module instance
     */
    public function __construct(VismaPay $module)
    {
        $this->module = $module;
        $this->context = Context::getContext();
        $this->filename = 'vismapay-payment-return';
    }

    /**
     * Validates payment return request.
     *
     * @return bool Whether or not the request is valid
     */
    public function validateRequest(): bool
    {
        $returnCode = Tools::getValue('RETURN_CODE');
        $orderNumber = Tools::getValue('ORDER_NUMBER');

        if (!isset($returnCode) || !isset($orderNumber)) {
            return true;
        }

        return false;
    }

    /**
     * Validates payment return request auth code.
     *
     * @return string|null Error message if validation fails and null on success
     */
    public function checkAuthcode(): ?string
    {
        $returnCode = Tools::getValue('RETURN_CODE');
        $orderNumber = Tools::getValue('ORDER_NUMBER');
        $settled = Tools::getValue('SETTLED');
        $contactId = Tools::getValue('CONTACT_ID');
        $incidentId = Tools::getValue('INCIDENT_ID');
        $authcode = Tools::getValue('AUTHCODE');

        $authcodeConfirm = "$returnCode|$orderNumber";

        if ($returnCode == 0) {
            $authcodeConfirm .= "|$settled";

            if (!empty($contactId)) {
                $authcodeConfirm .= "|$contactId";
            }
        } elseif (!empty($incidentId)) {
            $authcodeConfirm .= "|$incidentId";
        }

        $authcodeConfirm = Tools::strtoupper(hash_hmac('sha256', $authcodeConfirm, Configuration::get('VP_PRIVATE_KEY')));

        if ($authcode !== $authcodeConfirm) {
            $error = $this->module->l('Order number:', $this->filename) . " $orderNumber. ";
            $error .= $this->module->l('Authcode mismatch', $this->filename);
            $error .= PHP_EOL . $this->module->l('Check the status of the payment from Visma Pay merchant portal!', $this->filename);

            return $error;
        }

        return null;
    }

    /**
     * Validates payment return request order number.
     *
     * @return string|null Error message if validation fails and null on success
     */
    public function checkOrderNumber(): ?string
    {
        $cartId = (int) Tools::getValue('id_cart');
        $vismaPayOrderNumber = $this->getVismaPayOrderNumber($cartId);
        $orderNumber = Tools::getValue('ORDER_NUMBER');

        if ($vismaPayOrderNumber !== $orderNumber) {
            $error = $this->module->l('Order number:', $this->filename) . " $orderNumber. ";
            $error .= $this->module->l('Order number mismatch', $this->filename);
            $error .= PHP_EOL . $this->module->l('Check the status of the payment from Visma Pay merchant portal!', $this->filename);

            return $error;
        }

        return null;
    }

    /**
     * Sets Prestashop cart to empty cart
     *
     * @var int Cart id
     */
    public function clearCart(int $cartId): void
    {
        $cart = new Cart($cartId);
        $this->context->cart = $cart;
        $this->context->cookie->__set('id_cart', $cartId);
    }

    /**
     * Updates successful Prestashop order status.
     *
     * @var string Status message
     */
    public function updateOrderSuccessStatus(string $statusMessage): void
    {
        $status = (bool) Tools::getValue('SETTLED') ? Configuration::get('PS_OS_PAYMENT') : Configuration::get('VP_OS_AUTHORIZED');
        $cartId = (int) Tools::getValue('id_cart');
        $paidAmount = $this->getVismaPayOrderAmount($cartId);
        $currencyId = $this->context->currency->id;
        $cartSecureKey = Tools::getValue('key');

        if (Order::getIdByCartId($cartId) === false) { // Don't attempt to create order on double return or notify
            $this->module->validateOrder($cartId, $status, $paidAmount, $this->module->displayName, '', [], $currencyId, false, $cartSecureKey);
            $this->saveOrderMessage($cartId, $statusMessage); // Order message added here since validateOrder has a bug
        }
    }

    /**
     * Updates failed Prestashop order status.
     *
     * @var string Error message
     */
    public function updateOrderFailStatus(string $error): void
    {
        $cartId = (int) Tools::getValue('id_cart');
        $paidAmount = $this->getVismaPayOrderAmount($cartId);
        $currencyId = $this->context->currency->id;
        $cartSecureKey = Tools::getValue('key');

        if (Order::getIdByCartId($cartId) === false) { // Don't attempt to create order on double return or notify
            $this->module->validateOrder($cartId, Configuration::get('PS_OS_ERROR'), $paidAmount, $this->module->displayName, '', [], $currencyId, false, $cartSecureKey);
            $this->saveOrderMessage($cartId, $error); // Order message added here since validateOrder has a bug
        }
    }

    /**
     * Saves order Visma Pay message to DB (Prestashop bug workaround)
     *
     * @var int Id of the order cart
     * @var string Order message
     */
    public function saveOrderMessage(int $cartId, string $message): void
    {
        foreach (explode(PHP_EOL, $message) as $line) {
            if ($line !== '') {
                $date = date('Y-m-d H:i:s');
                Db::getInstance()->Execute('INSERT INTO ' . _DB_PREFIX_ . "vismapay_order_message (`cart_id`, `date`, `message`) VALUES ($cartId, '$date', '$line')");
            }
        }
    }

    /**
     * Returns saved Visma Pay order number for the cart.
     *
     * @var int Cart id
     *
     * @return string Visma Pay order number
     */
    public function getVismaPayOrderNumber(int $cardId): string
    {
        $query = Db::getInstance()->getRow('SELECT order_number FROM ' . _DB_PREFIX_ . "vismapay_order WHERE cart_id=$cardId");

        return $query['order_number'];
    }

    /**
     * Gets payment status message.
     *
     * @var bool Whether or not status message is translated
     *
     * @return string Payment status message
     */
    public function getPaymentStatusMessage(bool $translate = false): string
    {
        $returnCode = (int) Tools::getValue('RETURN_CODE');
        $settled = (bool) Tools::getValue('SETTLED');
        $orderNumber = Tools::getValue('ORDER_NUMBER');
        $message = '';

        if ($translate) {
            $message .= $this->module->l('Order number:', $this->filename) . " $orderNumber." . PHP_EOL;
        } else {
            $message .= "Order number: $orderNumber. ";
        }

        $privatekey = Configuration::get('VP_PRIVATE_KEY');
        $apikey = Configuration::get('VP_API_KEY');
        $api = new Visma\VismaPay($apikey, $privatekey);

        try {
            $response = $api->checkStatusWithOrderNumber($orderNumber);

            if (isset($response->source->object) && $response->source->object === 'card') {
                $message .= $this->getCardPaymentMessage($response->source, $translate);
            }

            if (isset($response->source->brand)) {
                if ($translate) {
                    $message .= $this->module->l('Payment method: ', $this->filename) . $response->source->brand . '.' . PHP_EOL;
                } else {
                    $message .= 'Payment method: ' . $response->source->brand . '. ';
                }
            }

            if ($returnCode === 0 && !$settled) {
                if ($translate) {
                    $message .= $this->module->l('Payment authorized.', $this->filename);
                } else {
                    $message .= 'Payment authorized.';
                }
            }

            if ($returnCode === 0 && $settled) {
                if ($translate) {
                    $message .= $this->module->l('Payment accepted.', $this->filename);
                } else {
                    $message .= 'Payment accepted.';
                }
            }

            if (!$this->isCorrectAmount()) {
                if ($translate) {
                    $message .= PHP_EOL . $this->module->l('NOTE !! Paid sum does not match order sum, verify order contents from the customer or Visma Pay merchant-portal.', $this->filename);
                } else {
                    $message .= ' NOTE !! Paid sum does not match order sum, verify order contents from the customer or Visma Pay merchant-portal.';
                }
            }
        } catch (Visma\VismaPayException $e) {
            PrestashopLogger::addLog("Visma Pay: Order Number: $orderNumber - Status check Exception: " . print_r($e, true), 1, null, null, null, true);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Something went wrong in checking Visma Pay payment status. Details: ' . $e->getMessage(), 3, null, null, null, true);
        }

        return $message;
    }

    /**
     * Gets payment return failed error message.
     *
     * @return string Error message of the return code
     */
    public function getFailedReturnMessage(): string
    {
        $returnCode = (int) Tools::getValue('RETURN_CODE');
        $orderNumber = Tools::getValue('ORDER_NUMBER');
        $error = "Visma Pay response: payment failed on order: $orderNumber. ";

        switch ($returnCode) {
            case 0:
                return '';
            case 4:
                $error .= 'Transaction status could not be updated after customer returned from the web page of a bank. Please use the merchant UI to resolve the payment status.';

                return $error;
            case 10:
                $error .= 'Maintenance break. The transaction is not created and the user has been notified and transferred back to the cancel address.';

                return $error;
            default:
                $error = 'Visma Pay response: payment failed. ';
                $error .= $this->getPaymentStatusMessage();

                return $error;
        }
    }

    /**
     * Validates payment return request amount.
     *
     * @return bool Whether or not amount is correct
     */
    public function isCorrectAmount(): bool
    {
        $cartId = (int) Tools::getValue('id_cart');
        $cart = new Cart((int) $cartId);
        $orderAmount = $cart->getOrderTotal();
        $paidAmount = $this->getVismaPayOrderAmount($cartId);

        if ($orderAmount !== $paidAmount) {
            return false;
        }

        return true;
    }

    /**
     * Returns saved Visma Pay order amount for the cart.
     *
     * @var int Cart id
     *
     * @return float Visma Pay order amount
     */
    private function getVismaPayOrderAmount(int $cardId): float
    {
        $query = Db::getInstance()->getRow('SELECT amount FROM ' . _DB_PREFIX_ . "vismapay_order WHERE cart_id=$cardId");

        return (float) $query['amount'];
    }

    /**
     * Gets card specific payment status information.
     *
     * @var object Visma Pay payment status object
     * @var bool Whether or not message is translated
     */
    private function getCardPaymentMessage(object $paymentStatus, bool $translate): string
    {
        $message = '';

        switch ($paymentStatus->card_verified) {
            case 'Y':
                if ($translate) {
                    $message .= PHP_EOL . $this->module->l('3-D Secure was used.', $this->filename) . PHP_EOL;
                } else {
                    $message .= '3-D Secure was used. ';
                }
                break;
            case 'N':
                if ($translate) {
                    $message .= PHP_EOL . $this->module->l('3-D Secure was not used.', $this->filename) . PHP_EOL;
                } else {
                    $message .= '3-D Secure was not used. ';
                }
                break;
            case 'A':
                if ($translate) {
                    $message .= PHP_EOL . $this->module->l('3-D Secure was attempted but not supported by the card issuer or the card holder is not participating.', $this->filename) . PHP_EOL;
                } else {
                    $message .= '3-D Secure was attempted but not supported by the card issuer or the card holder is not participating. ';
                }
                break;
            default:
                if ($translate) {
                    $message .= PHP_EOL . $this->module->l('3-D Secure: No connection to acquirer.', $this->filename) . PHP_EOL;
                } else {
                    $message .= '3-D Secure: No connection to acquirer. ';
                }
                break;
        }

        switch ($paymentStatus->error_code) {
            case '':
                // No error
                break;
            case '04':
                if ($translate) {
                    $message .= $this->module->l('The card is reported lost or stolen.', $this->filename) . PHP_EOL;
                } else {
                    $message .= 'The card is reported lost or stolen. ';
                }
                break;
            case '05':
                if ($translate) {
                    $message .= $this->module->l('General decline. The card holder should contact the issuer to find out why the payment failed.', $this->filename) . PHP_EOL;
                } else {
                    $message .= 'General decline. The card holder should contact the issuer to find out why the payment failed. ';
                }
                break;
            case '51':
                if ($translate) {
                    $message .= $this->module->l('Insufficient funds. The card holder should verify that there is balance on the account and the online payments are actived.', $this->filename) . PHP_EOL;
                } else {
                    $message .= 'Insufficient funds. The card holder should verify that there is balance on the account and the online payments are actived. ';
                }
                break;
            case '54':
                if ($translate) {
                    $message .= $this->module->l('Expired card.', $this->filename) . PHP_EOL;
                } else {
                    $message .= 'Expired card. ';
                }
                break;
            case '61':
                if ($translate) {
                    $message .= $this->module->l('Withdrawal amount limit exceeded.', $this->filename) . PHP_EOL;
                } else {
                    $message .= 'Withdrawal amount limit exceeded. ';
                }
                break;
            case '62':
                if ($translate) {
                    $message .= $this->module->l('Restricted card. The card holder should verify that the online payments are actived.', $this->filename) . PHP_EOL;
                } else {
                    $message .= 'Restricted card. The card holder should verify that the online payments are actived.';
                }
                break;
            case '1000':
                if ($translate) {
                    $message .= $this->module->l('Timeout communicating with the acquirer. The payment should be tried again later.', $this->filename) . PHP_EOL;
                } else {
                    $message .= 'Timeout communicating with the acquirer. The payment should be tried again later. ';
                }
                break;
            default:
                if ($translate) {
                    $message .= $this->module->l('No error for code', $this->filename) . ' \"' . $paymentStatus->error_code . '\"' . PHP_EOL;
                } else {
                    $message .= 'No error for code  \"' . $paymentStatus->error_code . '\" ';
                }
                break;
        }

        if (!empty($paymentStatus->card_country)) {
            if ($translate) {
                $message .= $this->module->l('Card ISO 3166-1 country code: ', $this->filename) . $paymentStatus->card_country . PHP_EOL;
            } else {
                $message .= 'Card ISO 3166-1 country code: ' . $paymentStatus->card_country . ' ';
            }
        }

        if (!empty($paymentStatus->client_ip_country)) {
            if ($translate) {
                $message .= $this->module->l('Client ISO 3166-1 country code: ', $this->filename) . $paymentStatus->client_ip_country . PHP_EOL;
            } else {
                $message .= 'Client ISO 3166-1 country code: ' . $paymentStatus->client_ip_country . ' ';
            }
        }

        return $message;
    }
}
