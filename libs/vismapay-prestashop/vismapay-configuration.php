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
 * Class for handling module's configuration.
 */
class VismaPayConfiguration
{
    /**
     * @var VismaPay Reference to the module instance
     */
    private $module;

    /**
     * @var array Configuration keys, default values, validation rules and form settings
     */
    private $configurations;

    /**
     * @var string Filename for translations
     */
    private $filename;

    /**
     * Constructor for handler.
     *
     * @var VismaPay Reference to the module instance
     */
    public function __construct(VismaPay $module)
    {
        $this->module = $module;
        $this->filename = 'vismapay-configuration';
        $this->configurations = $this->initConfigurations();
    }

    /**
     * Initializes configurations to Prestashop database.
     */
    public function initAllConfigurationValues(): void
    {
        foreach ($this->configurations as $key => $configuration) {
            Configuration::updateValue($key, $configuration['defaultValue']);
        }
    }

    /**
     * Removes configurations from Prestashop database.
     */
    public function removeAllConfigurationValues(): void
    {
        foreach ($this->configurations as $key => $configuration) {
            Configuration::deleteByName($key);
        }
    }

    /**
     * Validates Visma Pay configurations for Prestashop.
     *
     * @return string Validation errors HTML
     */
    public function validateAllConfigurationValues(): string
    {
        $validationErrors = '';

        foreach ($this->configurations as $key => $configuration) {
            $value = Tools::getValue($key);

            if (isset($configuration['validationRules'])) {
                foreach ($configuration['validationRules'] as $rule) {
                    $valid = $this->checkValidationRule($rule, $value);
                    if (!$valid) {
                        $label = isset($configuration['input']['label']) ? $configuration['input']['label'] . ' ' : '';
                        $validationErrors .= $this->module->displayError($label . $this->module->l('is invalid', $this->filename));
                    }
                }
            }
        }

        return $validationErrors;
    }

    /**
     * Updates Visma Pay configurations for Prestashop from configuration form.
     */
    public function updateAllConfigurationValues(): void
    {
        foreach ($this->configurations as $key => $configuration) {
            if (isset($configuration['input'])) { // If the configuration is included in the form
                Configuration::updateValue($key, Tools::getValue($key));
            }
        }
    }

    /**
     * Builds the configuration form.
     *
     * @return string Configuration form HTML
     */
    public function displayConfigurationForm(): string
    {
        $helper = new HelperForm();
        $helper->currentIndex = AdminController::$currentIndex . '&' . http_build_query(['configure' => $this->module->name]);
        $helper->default_form_language = Context::getContext()->language->id;
        $helper->name_controller = $this->module->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->submit_action = 'submit' . $this->module->name;
        $helper->module = $this->module;

        $formSettings = [
            'form' => [
                'legend' => [
                    'title' => $this->module->l('Settings', $this->filename),
                    'icon' => 'icon-cogs',
                ],
                'input' => [],
                'submit' => [
                    'title' => $this->module->l('Save', $this->filename),
                    'class' => 'button pull-right',
                ],
            ],
        ];

        foreach ($this->configurations as $key => $configuration) {
            if (isset($configuration['input'])) {
                $formSettings['form']['input'][] = $configuration['input'];
                $helper->fields_value[$key] = Configuration::get($key);
            }
        }

        return $helper->generateForm([$formSettings]);
    }

    /**
     * Checks if the value matches the rule.
     *
     * @return bool Whether or not validation succeeded
     */
    private function checkValidationRule($rule, $value): bool
    {
        switch ($rule) {
            case 'required':
                if (empty($value)) {
                    return false;
                }

                return true;
            case 'isConfigName':
                if (!empty($value) && !Validate::isConfigName($value)) {
                    return false;
                }

                return true;
            case 'isBool':
                if (!empty($value) && !Validate::isBool($value)) {
                    return false;
                }

                return true;
            case 'vpSendItems':
                if (!empty($value) && !in_array($value, ['forced', 'enabled', 'disabled'])) {
                    return false;
                }

                return true;
            case 'vpEmbedded':
                if (!empty($value) && !in_array($value, ['embed', 'separate', 'redirect'])) {
                    return false;
                }

                return true;
            default:
                return false;
        }
    }

    /**
     * Gets Visma Pay authorized order state id and creates the order state if it doesn't exist.
     *
     * @return int Visma Pay authorized order state id
     */
    private function getVismaPayAuthorizedOrderStateId(): int
    {
        $name = $this->module->name;
        $orderState = Db::getInstance()->getRow('SELECT * FROM ' . _DB_PREFIX_ . "order_state WHERE module_name='$name'");

        if ($orderState) {
            if ((bool) $orderState['deleted']) {
                Db::getInstance()->Execute('UPDATE ' . _DB_PREFIX_ . "order_state SET deleted=0 WHERE module_name='$name'");
            }

            return (int) $orderState['id_order_state'];
        }

        $orderState = $this->createVismaPayAuthorizedOrderState();

        return (int) $orderState->id;
    }

    /**
     * Creates Visma Pay authorized order state to Prestashop
     *
     * @return OrderState Prestashop order state
     */
    private function createVismaPayAuthorizedOrderState(): OrderState
    {
        $orderState = new OrderState();
        $orderState->module_name = $this->module->name;
        $orderState->send_email = false;
        $orderState->color = '#126cff';
        $orderState->hidden = false;
        $orderState->delivery = false;
        $orderState->logable = false;
        $orderState->invoice = false;

        foreach (Language::getLanguages() as $language) {
            if ($language['iso_code'] === 'fi') {
                $orderState->name[$language['id_lang']] = 'Maksu varmennettu';
            } else {
                $orderState->name[$language['id_lang']] = 'Payment authorized';
            }
        }

        $orderState->add();

        // Add order state logo
        $source = _PS_MODULE_DIR_ . 'vismapay/views/img/logo.gif';
        $destination = _PS_ORDER_STATE_IMG_DIR_ . (int) $orderState->id . '.gif';
        copy($source, $destination);

        return $orderState;
    }

    /**
     * Initializes module configurations.
     *
     * @return array Module configurations
     */
    private function initConfigurations(): array
    {
        return [
            /*
             * Custom Visma Pay order state for card payments that are authorized but not settled
             */
            'VP_OS_AUTHORIZED' => [
                'defaultValue' => $this->getVismaPayAuthorizedOrderStateId(),
            ],
            /*
             * Merchant's Visma Pay private key
             */
            'VP_PRIVATE_KEY' => [
                'defaultValue' => '',
                'validationRules' => [
                    'required',
                    'isConfigName',
                ],
                'input' => [
                    'type' => 'text',
                    'label' => $this->module->l('Private key', $this->filename),
                    'name' => 'VP_PRIVATE_KEY',
                    'class' => 'fixed-width-xxl',
                    'size' => 50,
                    'required' => true,
                ],
            ],
            /*
             * Merchant's Visma Pay api key
             */
            'VP_API_KEY' => [
                'defaultValue' => '',
                'validationRules' => [
                    'required',
                    'isConfigName',
                ],
                'input' => [
                    'type' => 'text',
                    'label' => $this->module->l('Api key', $this->filename),
                    'name' => 'VP_API_KEY',
                    'class' => 'fixed-width-xxl',
                    'size' => 50,
                    'required' => true,
                ],
            ],
            /*
             * Visma Pay order prefix
             */
            'VP_ORDER_PREFIX' => [
                'defaultValue' => '',
                'validationRules' => [
                    'isConfigName',
                ],
                'input' => [
                    'type' => 'text',
                    'label' => $this->module->l('Order number prefix', $this->filename),
                    'name' => 'VP_ORDER_PREFIX',
                    'class' => 'fixed-width-xxl',
                    'size' => 50,
                    'required' => false,
                ],
            ],
            /*
             * Option for using embedded Visma Pay payment instead of redirecting customer to Visma Pay payment page
             */
            'VP_EMBEDDED' => [
                'defaultValue' => 'embed',
                'validationRules' => [
                    'vpEmbedded',
                ],
                'input' => [
                    'type' => 'radio',
                    'label' => $this->module->l('Payment method display', $this->filename),
                    'desc' => $this->module->l('Choose how payment methods are displayed', $this->filename) . '. <br/>'
                    . ' - ' . $this->module->l('Embed: After choosing Visma Pay on the checkout-page, the payment methods and their logos are then shown.', $this->filename) . '<br/>'
                    . ' - ' . $this->module->l('Separate: All the payment methods on your Visma Pay merchant account are separated as their own payment method on the checkout-page.', $this->filename) . '<br/>'
                    . ' - ' . $this->module->l('Redirect: After choosing Visma Pay at the checkout-page the customer is redirected to the Visma Pay payment-page. ', $this->filename) . '<br/>',
                    'name' => 'VP_EMBEDDED',
                    'class' => 't',
                    'values' => [
                        [
                            'id' => 'active_maybe',
                            'value' => 'embed',
                            'label' => $this->module->l('Embed', $this->filename),
                        ],
                        [
                            'id' => 'active_on',
                            'value' => 'separate',
                            'label' => $this->module->l('Separate', $this->filename),
                        ],
                        [
                            'id' => 'active_off',
                            'value' => 'redirect',
                            'label' => $this->module->l('Redirect', $this->filename),
                        ],
                    ],
                ],
            ],
            /*
             * Option for enabling wallet selection
             */
            'VP_SELECT_WALLETS' => [
                'defaultValue' => true,
                'validationRules' => [
                    'isBool',
                ],
                'input' => [
                    'type' => 'radio',
                    'is_bool' => true,
                    'label' => $this->module->l('Wallets', $this->filename),
                    'desc' => $this->module->l('Enable wallet services in the Visma Pay payment page.', $this->filename),
                    'name' => 'VP_SELECT_WALLETS',
                    'class' => 't',
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => true,
                            'label' => $this->module->l('Enabled', $this->filename),
                        ],
                        [
                            'id' => 'active_off',
                            'value' => false,
                            'label' => $this->module->l('Disabled', $this->filename),
                        ],
                    ],
                ],
            ],
            /*
             * Option for enabling bank selection
             */
            'VP_SELECT_BANKS' => [
                'defaultValue' => true,
                'validationRules' => [
                    'isBool',
                ],
                'input' => [
                    'type' => 'radio',
                    'is_bool' => true,
                    'label' => $this->module->l('Banks', $this->filename),
                    'desc' => $this->module->l('Enable bank payments in the Visma Pay payment page.', $this->filename),
                    'name' => 'VP_SELECT_BANKS',
                    'class' => 't',
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => true,
                            'label' => $this->module->l('Enabled', $this->filename),
                        ],
                        [
                            'id' => 'active_off',
                            'value' => false,
                            'label' => $this->module->l('Disabled', $this->filename),
                        ],
                    ],
                ],
            ],
            /*
             * Option for enabling credit card selection
             */
            'VP_SELECT_CCARDS' => [
                'defaultValue' => true,
                'validationRules' => [
                    'isBool',
                ],
                'input' => [
                    'type' => 'radio',
                    'is_bool' => true,
                    'label' => $this->module->l('Credit cards', $this->filename),
                    'desc' => $this->module->l('Enable credit cards in the Visma Pay payment page.', $this->filename),
                    'name' => 'VP_SELECT_CCARDS',
                    'class' => 't',
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => true,
                            'label' => $this->module->l('Enabled', $this->filename),
                        ],
                        [
                            'id' => 'active_off',
                            'value' => false,
                            'label' => $this->module->l('Disabled', $this->filename),
                        ],
                    ],
                ],
            ],
            /*
             * Option for enabling credit invoices selection
             */
            'VP_SELECT_CINVOICES' => [
                'defaultValue' => true,
                'validationRules' => [
                    'isBool',
                ],
                'input' => [
                    'type' => 'radio',
                    'is_bool' => true,
                    'label' => $this->module->l('Credit invoices', $this->filename),
                    'desc' => $this->module->l('Enable credit invoices in the Visma Pay payment page.', $this->filename),
                    'name' => 'VP_SELECT_CINVOICES',
                    'class' => 't',
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => true,
                            'label' => $this->module->l('Enabled', $this->filename),
                        ],
                        [
                            'id' => 'active_off',
                            'value' => false,
                            'label' => $this->module->l('Disabled', $this->filename),
                        ],
                    ],
                ],
            ],
            /*
             * Option for enabling Alisa Yrityslasku selection
             */
            'VP_SELECT_LASKUYRITYKSELLE' => [
                'defaultValue' => true,
                'validationRules' => [
                    'isBool',
                ],
                'input' => [
                    'type' => 'radio',
                    'is_bool' => true,
                    'label' => $this->module->l('Alisa Yrityslasku', $this->filename),
                    'desc' => $this->module->l('Enable Alisa Yrityslasku in the Visma Pay payment page.', $this->filename),
                    'name' => 'VP_SELECT_LASKUYRITYKSELLE',
                    'class' => 't',
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => true,
                            'label' => $this->module->l('Enabled', $this->filename),
                        ],
                        [
                            'id' => 'active_off',
                            'value' => false,
                            'label' => $this->module->l('Disabled', $this->filename),
                        ],
                    ],
                ],
            ],
            /*
             * Option for enabling sending product information to Visma Pay
             */
            'VP_SEND_ITEMS' => [
                'defaultValue' => 'enabled',
                'validationRules' => [
                    'vpSendItems',
                ],
                'input' => [
                    'type' => 'radio',
                    'is_bool' => false,
                    'label' => $this->module->l('Send product details to Visma Pay', $this->filename),
                    'desc' => $this->module->l('Enable, disable or force sending product details to Visma Pay, should normally be Enable.', $this->filename),
                    'name' => 'VP_SEND_ITEMS',
                    'class' => 't',
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => 'forced',
                            'label' => $this->module->l('Forced', $this->filename),
                        ],
                        [
                            'id' => 'active_maybe',
                            'value' => 'enabled',
                            'label' => $this->module->l('Enabled', $this->filename),
                        ],
                        [
                            'id' => 'active_off',
                            'value' => 'disabled',
                            'label' => $this->module->l('Disabled', $this->filename),
                        ],
                    ],
                ],
            ],
            /*
             * Option for enabling sending payment confirmation email to the customer from Visma Pay
             */
            'VP_SEND_CONFIRMATION' => [
                'defaultValue' => true,
                'validationRules' => [
                    'isBool',
                ],
                'input' => [
                    'type' => 'radio',
                    'is_bool' => true,
                    'label' => $this->module->l('Send Visma Pay payment confirmation', $this->filename),
                    'desc' => $this->module->l('Send a payment confirmation to the customer\'s email from Visma Pay.', $this->filename),
                    'name' => 'VP_SEND_CONFIRMATION',
                    'class' => 't',
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => true,
                            'label' => $this->module->l('Enabled', $this->filename),
                        ],
                        [
                            'id' => 'active_off',
                            'value' => false,
                            'label' => $this->module->l('Disabled', $this->filename),
                        ],
                    ],
                ],
            ],
            /*
             * Option for enabling clearing customer cart when they are redirected to Visma Pay
             */
            'VP_CLEAR_CART' => [
                'defaultValue' => false,
                'validationRules' => [
                    'isBool',
                ],
                'input' => [
                    'type' => 'radio',
                    'is_bool' => true,
                    'label' => $this->module->l('Clear customer\'s cart when they are redirected to pay', $this->filename),
                    'desc' => $this->module->l('When this option is enabled, the customer\'s shopping cart will be emptied when they are redirected to pay for their order. The cart will be restored if the customer cancels their payment or the payment fails.', $this->filename),
                    'name' => 'VP_CLEAR_CART',
                    'class' => 't',
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => true,
                            'label' => $this->module->l('Enabled', $this->filename),
                        ],
                        [
                            'id' => 'active_off',
                            'value' => false,
                            'label' => $this->module->l('Disabled', $this->filename),
                        ],
                    ],
                ],
            ],
        ];
    }
}
