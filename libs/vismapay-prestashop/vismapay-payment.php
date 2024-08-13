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
 * Class for handling module's payment.
 */
class VismaPayPayment
{
    /**
     * @var VismaPay Reference to the module instance
     */
    private $module;

    /**
     * @var Context Payment option settings
     */
    private $context;

    /**
     * Constructor for handler.
     *
     * @var VismaPay Reference to the module instance
     */
    public function __construct(VismaPay $module)
    {
        $this->module = $module;
        $this->context = Context::getContext();
    }

    /**
     * Method for creating payment.
     *
     * @var Cart Prestashop cart
     *
     * @return string|null Url where the payment can be paid or null if creating payment fails
     */
    public function createPayment(Cart $cart): ?string
    {
        $privatekey = Configuration::get('VP_PRIVATE_KEY');
        $apikey = Configuration::get('VP_API_KEY');
        $api = new Visma\VismaPay($apikey, $privatekey);

        $orderNumber = $this->saveVismaPayOrder($cart);

        $api->addCharge($this->getCharge($cart, $orderNumber));
        $api->addCustomer($this->getCustomer($cart));

        $sendItemsOption = Configuration::get('VP_SEND_ITEMS');

        if ($sendItemsOption === 'enabled' || $sendItemsOption === 'forced') {
            $products = $this->getProducts($cart);

            foreach ($products as $product) {
                $api->addProduct($product);
            }
        }

        $paymentMethod = $this->getPaymentMethod($cart);

        if (!$paymentMethod) {
            return null;
        }

        $api->addPaymentMethod($paymentMethod);

        try {
            $response = $api->createCharge();

            if ($response->result !== 0) {
                PrestashopLogger::addLog(json_encode($response), 3, null, null, null, true);

                return null;
            }

            return Visma\VismaPay::API_URL . "/token/$response->token";
        } catch (Visma\VismaPayException $e) {
            $result = $e->getCode();
            $errorMessage = $e->getMessage();
            PrestashopLogger::addLog("Visma Pay exception $result: $errorMessage, vismapay order: $orderNumber", 3, null, null, null, true);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Something went wrong in creating Visma Pay payment. Details: ' . $e->getMessage(), 3, null, null, null, true);
        }

        return null;
    }

    /**
     * Method for generating unique order number for payment.
     *
     * @var Cart Prestashop cart
     *
     * @return string Order number
     */
    private function generateOrderNumber(Cart $cart): string
    {
        $prefix = Configuration::get('VP_ORDER_PREFIX');

        if (!empty($prefix)) {
            return $prefix . '_' . date('YmdHis') . '_' . $cart->id;
        } else {
            return date('YmdHis') . '_' . $cart->id;
        }
    }

    /**
     * Method for saving Visma Pay order details.
     *
     * @var Cart Prestashop cart
     *
     * @return string Saved order number
     */
    private function saveVismaPayOrder(Cart $cart): string
    {
        $orderNumber = $this->generateOrderNumber($cart);
        $rawAmount = $cart->getOrderTotal();

        if (!Db::getInstance()->getRow('SELECT id FROM ' . _DB_PREFIX_ . "vismapay_order WHERE cart_id=$cart->id")) {
            Db::getInstance()->Execute('INSERT INTO ' . _DB_PREFIX_ . "vismapay_order (`cart_id`, `order_number`, `amount`) VALUES ($cart->id, '$orderNumber', $rawAmount)");
        } else {
            Db::getInstance()->Execute('UPDATE ' . _DB_PREFIX_ . "vismapay_order SET order_number='$orderNumber', amount=$rawAmount WHERE cart_id=$cart->id");
        }

        return $orderNumber;
    }

    /**
     * Method for getting Visma Pay products.
     * If the total amount calculation is incorrect the products
     * won't be returned unless sending information is forced in settings.
     *
     * @var Cart Prestashop cart
     *
     * @return array Visma Pay products
     */
    private function getProducts(Cart $cart): array
    {
        $products = [];
        $totalAmount = 0;

        foreach ($cart->getProducts(true) as $product) {
            $products[] = [
                'id' => $product['reference'],
                'title' => $product['name'],
                'count' => $product['cart_quantity'],
                'pretax_price' => $this->floatToInt($product['price']),
                'tax' => number_format($product['rate'], 2, '.', ''),
                'price' => $this->floatToInt($product['price_wt']),
                'type' => 1,
            ];

            $totalAmount += $this->floatToInt($product['price_wt']) * $product['cart_quantity'];
        }

        // Add shipping cost as it's own product if there is one
        $carrier = new Carrier($cart->id_carrier);
        $shipping_address = new Address($cart->id_address_delivery);

        $shippingCostInt = $this->floatToInt($cart->getPackageShippingCost($cart->id_carrier));

        if ($shippingCostInt > 0) {
            $shippingPretaxCostInt = $this->floatToInt($cart->getPackageShippingCost($cart->id_carrier, false));
            $shippingTax = $carrier->getTaxesRate($shipping_address);

            $products[] = [
                'id' => $carrier->id_reference,
                'title' => $carrier->name,
                'count' => 1,
                'pretax_price' => $shippingPretaxCostInt,
                'tax' => number_format($shippingTax, 2, '.', ''),
                'price' => $shippingCostInt,
                'type' => 2,
            ];

            $totalAmount += $shippingCostInt;
        }

        // Add discount as it's own product if there is one
        // Discounts are stored as a positive integer in Prestashop
        $summary = $cart->getSummaryDetails();
        $discountAmount = $this->floatToInt($summary['total_discounts']);

        if ($discountAmount > 0) {
            $discountPretaxAmount = $this->floatToInt($summary['total_discounts_tax_exc']);

            // Raw amount used to avoid rounding errors
            $discountPretaxAmountRaw = $this->getRawDiscountPretaxAmount($summary['discounts']);
            $discountTaxAmountRaw = $summary['total_discounts'] - $discountPretaxAmountRaw;
            $discountTax = $discountPretaxAmountRaw > 0 ? $discountTaxAmountRaw / $discountPretaxAmountRaw * 100 : 0;

            $products[] = [
                'id' => 1,
                'title' => $this->module->l('Total discounts', 'vismapay-payment'),
                'count' => 1,
                'pretax_price' => -$discountPretaxAmount,
                'tax' => number_format($discountTax, 2, '.', ''),
                'price' => -$discountAmount,
                'type' => 4,
            ];

            $totalAmount -= $discountAmount;
        }

        $amount = $this->floatToInt($cart->getOrderTotal());

        // Don't send invalid data if not forced
        if ($totalAmount !== $amount && Configuration::get('VP_SEND_ITEMS') !== 'forced') {
            return [];
        }

        return $products;
    }

    /**
     * Method for calculating unrounded total discount pretax amount
     *
     * @var array Collection of discount objects
     *
     * @return float Raw unrounded discount pretax amount
     */
    private function getRawDiscountPretaxAmount(array $discounts): float
    {
        $discountPretaxAmountRaw = 0;

        foreach ($discounts as $discount) {
            $discountPretaxAmountRaw += $discount['value_tax_exc'];
        }

        return $discountPretaxAmountRaw;
    }

    /**
     * Method for getting Visma Pay charge.
     *
     * @var Cart Prestashop cart
     * @var string Prestashop order number
     *
     * @return array Visma Pay charge
     */
    private function getCharge(Cart $cart, string $orderNumber): array
    {
        $amount = (int) round($cart->getOrderTotal() * 100, 0);
        $currency = (new Currency($cart->id_currency))->iso_code;
        $email = htmlspecialchars((new Customer((int) $cart->id_customer))->email);

        return [
            'order_number' => $orderNumber,
            'amount' => $amount,
            'currency' => $currency,
            'email' => (bool) Configuration::get('VP_SEND_CONFIRMATION') ? $email : null,
        ];
    }

    /**
     * Method for getting Visma Pay customer.
     *
     * @var Cart Prestashop cart
     *
     * @return array Visma Pay customer
     */
    private function getCustomer(Cart $cart): array
    {
        $address = new Address((int) $cart->id_address_invoice);
        $shippingAddress = new Address((int) $cart->id_address_delivery);
        $email = htmlspecialchars((new Customer((int) $cart->id_customer))->email);
        $phone = $address->phone ?? $address->phone_mobile ?? '';

        return [
            'firstname' => htmlspecialchars($address->firstname),
            'lastname' => htmlspecialchars($address->lastname),
            'email' => $email,
            'address_street' => htmlspecialchars($address->address1 . ' ' . $address->address2),
            'address_city' => htmlspecialchars($address->city),
            'address_zip' => htmlspecialchars($address->postcode),
            'address_country' => htmlspecialchars($address->country),
            'shipping_firstname' => htmlspecialchars($shippingAddress->firstname),
            'shipping_lastname' => htmlspecialchars($shippingAddress->lastname),
            'shipping_email' => $email,
            'shipping_address_street' => htmlspecialchars($shippingAddress->address1 . ' ' . $shippingAddress->address2),
            'shipping_address_city' => htmlspecialchars($shippingAddress->city),
            'shipping_address_zip' => htmlspecialchars($shippingAddress->postcode),
            'shipping_address_country' => htmlspecialchars($shippingAddress->country),
            'phone' => preg_replace('/[^0-9+ ]/', '', $phone),
        ];
    }

    /**
     * Method for getting payment method for payment.
     *
     * @var Cart Prestashop cart
     *
     * @return array|null Visma Pay payment method names or null if getting payment methods fails
     */
    private function getPaymentMethod(Cart $cart): ?array
    {
        $paymentMethods = [];
        $selectedPaymentMethod = Tools::getValue('selected', null);
        $supportedLangauges = array('fi', 'en', 'sv', 'ru');

        if ($selectedPaymentMethod) { // Use one selected payment method
            $paymentMethods[] = $selectedPaymentMethod;
        } else { // Display available payment methods on payment page
            foreach ($this->module->paymentOptions->getPaymentMethods() as $type => $gateways) {
                foreach ($gateways as $gateway) {
                    $paymentMethods[] = $gateway['value'];
                }
            }
        }

        if (empty($paymentMethods)) {
            return null;
        }

        $params = ['id_cart' => (int) $cart->id, 'key' => $cart->secure_key];
        $returnUrl = $this->context->link->getModuleLink($this->module->name, 'payment_return', $params, Configuration::get('PS_SSL_ENABLED'));
        
        $lang = Tools::strtolower($this->context->language->iso_code);
        if (!in_array($lang, $supportedLangauges)) {
            $lang = 'en';
        }

        return [
            'type' => 'e-payment',
            'return_url' => $returnUrl,
            'notify_url' => $returnUrl,
            'lang' => $lang,
            'selected' => $paymentMethods,
        ];
    }

    /**
     * Converts the passed floating point value with precision two to integer.
     *
     * @var float Value with precision of two
     *
     * @return int Value
     */
    private function floatToInt(float $value): int
    {
        return (int) round($value * 100, 0);
    }
}
