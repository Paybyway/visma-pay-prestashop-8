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
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

/**
 * Class for handling module's payment options.
 */
class VismaPayPaymentOptions
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
        $this->filename = 'vismapay-payment-options';
        $this->context = Context::getContext();
    }

    /**
     * Gets Visma Pay payment methods as payment options for Prestashop.
     *
     * @return array|null Prestashop payment options or null if payment options not available
     */
    public function getPaymentOptions(): ?array
    {
        $paymentMethodDisplayOption = Configuration::get('VP_EMBEDDED');

        switch ($paymentMethodDisplayOption) {
            case 'separate':
                return $this->getPaymentOptionForEachGateway();
            case 'embed':
                return $this->getVismaPayPaymentOption();
            case 'redirect':
                return $this->getVismaPayRedirectPaymentOption();
            default:
                return null;
        }
    }

    /**
     * Gets merchant's Visma Pay payment methods.
     * Updates payment method images in the process.
     *
     * @return array Visma Pay payment method names (gateways) by type
     */
    public function getPaymentMethods(): array
    {
        $paymentMethods = [
            'creditcards' => [],
            'wallets' => [],
            'banks' => [],
            'creditinvoices' => [],
        ];

        $merchantPaymentMethods = $this->fetchMerchantPaymentMethods();

        foreach ($merchantPaymentMethods as $paymentMethod) {
            $type = $paymentMethod->group;

            $item = [
                'name' => $paymentMethod->name,
                'value' => $paymentMethod->selected_value,
            ];

            switch ($type) {
                case 'creditcards':
                    if ((bool) Configuration::get('VP_SELECT_CCARDS')) {
                        $paymentMethods[$type][] = $item;
                    }
                    break;
                case 'wallets':
                    if ((bool) Configuration::get('VP_SELECT_WALLETS')) {
                        $paymentMethods[$type][] = $item;
                    }
                    break;
                case 'banks':
                    if ((bool) Configuration::get('VP_SELECT_BANKS')) {
                        $paymentMethods[$type][] = $item;
                    }
                    break;
                case 'creditinvoices':
                    if ($item['value'] == 'laskuyritykselle' && (bool) Configuration::get('VP_SELECT_LASKUYRITYKSELLE')) {
                        $paymentMethods[$type][] = $item;
                    } elseif ($item['value'] != 'laskuyritykselle' && (bool) Configuration::get('VP_SELECT_CINVOICES')) {
                        $amount = (int) round($this->context->cart->getOrderTotal() * 100, 0);

                        if ($amount >= $paymentMethod->min_amount && $amount <= $paymentMethod->max_amount) {
                            $paymentMethods[$type][] = $item;
                        }
                    }
                    break;
                default:
                    break;
            }

            $this->updatePaymentMethodImage($paymentMethod);
        }

        return $paymentMethods;
    }

    /**
     * Fetches merchant payment methods from Visma Pay API.
     *
     * @return array Visma Pay API payment methods
     */
    private function fetchMerchantPaymentMethods(): array
    {
        $privatekey = Configuration::get('VP_PRIVATE_KEY');
        $apikey = Configuration::get('VP_API_KEY');
        $api = new Visma\VismaPay($apikey, $privatekey);
        $currencyCode = (new Currency($this->context->cart->id_currency))->iso_code;

        try {
            $response = $api->getMerchantPaymentMethods($currencyCode);

            if ($response->result !== 0 || !isset($response->payment_methods)) {
                PrestashopLogger::addLog(json_encode($response), 3, null, null, null, true);

                return [];
            }

            return $response->payment_methods;
        } catch (Visma\VismaPayException $e) {
            $errorMessage = $e->getMessage();
            $logMessage = "Visma Pay getMerchantPaymentMethods exception: $errorMessage , Check your credentials in the module settings.";
            PrestashopLogger::addLog($logMessage, 3, null, null, null, true);
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('Something went wrong in fetching Visma Pay merchant payment methods. Details: ' . $e->getMessage(), 3, null, null, null, true);
        }

        return [];
    }

    /**
     * Updates Visma Pay payment method image to Prestashop if the image has been changed in the API.
     *
     * @var object Visma Pay payment method
     */
    private function updatePaymentMethodImage(object $paymentMethod): void
    {
        $url = $paymentMethod->img;
        $timestamp = $paymentMethod->img_timestamp;
        // 'visa' or 'mastercard' as the name for credit cards instead of 'creditcards'
        $name = $paymentMethod->group === 'creditcards' ? strtolower(str_replace(' ', '', $paymentMethod->name)) : $paymentMethod->selected_value;
        $img = _PS_MODULE_DIR_ . "vismapay/views/img/$name.png";

        if ((!file_exists($img) || $timestamp > (int) filemtime($img)) && $file = @fopen($url, 'r')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $fileContent = stream_get_contents($file);

            if (strpos($finfo->buffer($fileContent), 'image') !== false) { // Make sure the file is an image
                @file_put_contents($img, $fileContent);
                touch($img, $timestamp);
            }

            @fclose($file);
        }
    }

    /**
     * Gets Prestashop payment options separately for each Visma Pay gateway.
     *
     * @return array<PaymentOption>|null Prestashop payment options or null if payment options not available
     */
    private function getPaymentOptionForEachGateway(): ?array
    {
        $paymentOptions = [];
        $paymentMethods = $this->getPaymentMethods();

        if (
            empty($paymentMethods['creditcards']) &&
            empty($paymentMethods['wallets']) &&
            empty($paymentMethods['banks']) &&
            empty($paymentMethods['creditinvoices'])
        ) {
            return null;
        }

        foreach ($paymentMethods as $type => $gateways) {
            foreach ($gateways as $gateway) {
                $paymentOption = new PaymentOption();
                $paymentOption->setCallToActionText($gateway['name'])
                    ->setAction($this->context->link->getModuleLink($this->module->name, 'payment', [], true))
                    ->setInputs([
                        'selected' => [
                            'name' => 'selected',
                            'type' => 'hidden',
                            'value' => $gateway['value'],
                        ],
                    ]);

                $paymentOptions[] = $paymentOption;
            }
        }

        return $paymentOptions;
    }

    /**
     * Gets a single Prestashop Visma Pay payment option which allows choosing a gateway from embedded list.
     *
     * @return array<PaymentOption>|null Prestashop payment option in an array or null if payment options not available
     */
    private function getVismaPayPaymentOption(): ?array
    {
        $paymentMethods = $this->getPaymentMethods();

        if (
            empty($paymentMethods['creditcards']) &&
            empty($paymentMethods['wallets']) &&
            empty($paymentMethods['banks']) &&
            empty($paymentMethods['creditinvoices'])
        ) {
            return null;
        }

        $paymentOption = new PaymentOption();
        $paymentOption->setCallToActionText($this->module->l('Visma Pay - internet banking, credit cards, credit invoices and wallet services', $this->filename))
            ->setForm($this->getVismaPayEmbeddedForm($paymentMethods));

        return [$paymentOption];
    }

    /**
     * Gets a single Prestashop Visma Pay payment option which redirects to the gateway selection.
     *
     * @return array<PaymentOption>|null Prestashop payment option in an array or null if payment options not available
     */
    private function getVismaPayRedirectPaymentOption(): ?array
    {
        // Should still check if there are any payment methods available since otherwise there is no point trying to show the gateway selection
        $paymentMethods = $this->getPaymentMethods();

        if (
            empty($paymentMethods['creditcards']) &&
            empty($paymentMethods['wallets']) &&
            empty($paymentMethods['banks']) &&
            empty($paymentMethods['creditinvoices'])
        ) {
            return null;
        }

        $paymentOption = new PaymentOption();
        $paymentOption->setCallToActionText($this->module->l('Visma Pay - internet banking, credit cards, credit invoices and wallet services', $this->filename))
            ->setForm($this->getVismaPayEmbeddedForm())
            ->setAdditionalInformation($this->context->smarty->fetch('module:vismapay/views/templates/front/redirect_info.tpl'));

        return [$paymentOption];
    }

    /**
     * Gets Visma Pay payment option embedded form HTML.
     *
     * @var array Visma Pay payment methods
     *
     * @return string Visma Pay payment option embedded form HTML
     */
    private function getVismaPayEmbeddedForm(array $paymentMethods = []): string
    {
        $this->context->smarty->assign([
            'action' => $this->context->link->getModuleLink($this->module->name, 'payment', [], true),
            'paymentMethods' => $paymentMethods,
            'imgDir' => __PS_BASE_URI__ . 'modules/vismapay/views/img/',
        ]);

        return $this->context->smarty->fetch('module:vismapay/views/templates/front/vismapay_embedded.tpl');
    }
}
