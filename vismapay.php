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

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use VismaPayModule\Helper\VismaPayConfiguration;
use VismaPayModule\Helper\VismaPayPayment;
use VismaPayModule\Helper\VismaPayPaymentOptions;
use VismaPayModule\Helper\VismaPayPaymentReturn;

/**
 * Class for module instance.
 */
class VismaPay extends PaymentModule
{
    /**
     * @var VismaPayConfiguration Class that handles module configurations
     */
    public $configuration;

    /**
     * @var VismaPayPaymentOptions Class that handles module payment options
     */
    public $paymentOptions;

    /**
     * @var VismaPayPayment Class that handles module payment
     */
    public $payment;

    /**
     * @var VismaPayPaymentReturn Class that handles module payment return
     */
    public $paymentReturn;

    /**
     * Constructor for module instance.
     */
    public function __construct()
    {
        $this->name = 'vismapay';
        $this->tab = 'payments_gateways';
        $this->version = '8.1.0';
        $this->author = 'Visma';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->ps_versions_compliancy = ['min' => '8.0.1', 'max' => _PS_VERSION_];

        parent::__construct(); // Must be called before using translations

        $this->displayName = $this->trans('Visma Pay', [], 'Modules.Vismapay.Vismapay');
        $this->description = $this->trans('Accept e-payments with Visma Pay payment gateway.', [], 'Modules.Vismapay.Vismapay');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall?', [], 'Modules.Vismapay.Vismapay');

        // Services
        $this->configuration = new VismaPayConfiguration($this);
        $this->paymentOptions = new VismaPayPaymentOptions($this);
        $this->payment = new VismaPayPayment($this);
        $this->paymentReturn = new VismaPayPaymentReturn($this);
    }

    /**
     * Method for installing module.
     *
     * @return bool Whether or not installation succeeded
     */
    public function install(): bool
    {
        if (!(parent::install()
            && $this->registerHook('displayAdminOrderMain')
            && $this->registerHook('displayAdminOrderSide')
            && $this->registerHook('displayHeader')
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && include_once ($this->getLocalPath() . 'sql/install.php'))
        ) {
            return false;
        }

        $this->configuration->initAllConfigurationValues();

        Tools::clearSf2Cache();

        return true;
    }

    /**
     * Method for uninstalling module.
     *
     * @return bool Whether or not uninstallation succeeded
     */
    public function uninstall(): bool
    {
        if (!(parent::uninstall() && include_once ($this->getLocalPath() . 'sql/uninstall.php'))) {
            return false;
        }

        $this->configuration->removeAllConfigurationValues();

        return true;
    }

    /**
     * Method for handling module's configuration page.
     *
     * @return string Configuration page HTML
     */
    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submit' . $this->name)) {
            $validationErrors = $this->configuration->validateAllConfigurationValues();

            if (!$validationErrors) {
                $this->configuration->updateAllConfigurationValues();
                $output = $this->displayConfirmation($this->trans('Settings updated', [], 'Modules.Vismapay.Vismapay'));
            } else {
                $output = $validationErrors;
            }
        }

        return $output . $this->configuration->displayConfigurationForm();
    }

    /**
     * Method for display header hook.
     * Injects module's custom CSS.
     */
    public function hookDisplayHeader(): void
    {
        $this->context->controller->registerStylesheet($this->name . '_stylesheet', $this->_path . 'views/css/vismapay.css', ['media' => 'all']);
    }

    /**
     * Method for payment options hook.
     *
     * @return array|null Prestahop payment options or null if payment options not available
     */
    public function hookPaymentOptions(): ?array
    {
        if (!$this->active) {
            return null;
        }

        return $this->paymentOptions->getPaymentOptions();
    }

    /**
     * Method for payment return hook.
     *
     * @var array Hook parameters
     *
     * @return string|null Payment return info HTML
     */
    public function hookPaymentReturn(array $params): ?string
    {
        if (!$this->active) {
            return null;
        }

        $order = $params['order'];

        if (
            $order->valid
            || $order->current_state == Configuration::get('VP_OS_AUTHORIZED')
            || $order->current_state == Configuration::get('PS_OS_OUTOFSTOCK')
        ) {
            $status = 'ok';
        } else {
            $status = 'failed';
        }

        $oldCart = new Cart(Order::getCartIdStatic((int) $order->id));
        $cartId = (int) $oldCart->id;
        $query = Db::getInstance()->getRow('SELECT order_number FROM ' . _DB_PREFIX_ . 'vismapay_order WHERE cart_id=' . $cartId);
        $orderNumber = is_array($query) ? ($query['order_number'] ?? '') : '';

        $upOrder = new Order($order->id);
        $payments = $upOrder->getOrderPaymentCollection();

        foreach ($payments as $payment) {
            /** @var OrderPayment $payment */
            if ($payment->payment_method == 'Visma Pay') {
                $payment->transaction_id = $orderNumber;
                $payment->update();
            }
        }

        $this->context->smarty->assign('status', $status);

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    /**
     * Method for display admin order main hook.
     *
     * @var array Hook parameters
     *
     * @return string|null Admin order main HTML
     */
    public function hookDisplayAdminOrderMain(array $params): ?string
    {
        $order = new Order((int) $params['id_order']);
        $cartId = (int) $order->id_cart;
        $rows = Db::getInstance()->executeS('SELECT `date`, `message` FROM ' . _DB_PREFIX_ . 'vismapay_order_message WHERE cart_id=' . $cartId);

        if (!is_array($rows)) {
            return null;
        }

        $messages = [];

        foreach ($rows as $row) {
            $message = new stdClass();
            $message->date = $row['date'];
            $message->content = $row['message'];
            $messages[] = $message;
        }

        $logoUrl = __PS_BASE_URI__ . 'modules/vismapay/views/img/logo.gif';
        $this->context->smarty->assign('logo_url', $logoUrl);
        $this->context->smarty->assign('messages', $messages);

        return $this->display(__FILE__, 'order_messages.tpl');
    }

    /**
     * Method for display admin order side hook.
     *
     * @var array Hook parameters
     *
     * @return string|null Admin order side HTML
     */
    public function hookDisplayAdminOrderSide(array $params): ?string
    {
        if (!$this->active) {
            return null;
        }

        $order = new Order((int) $params['id_order']);
        $currentState = $order->getCurrentState();

        if ((int) $currentState !== (int) Configuration::get('VP_OS_AUTHORIZED')) {
            return null;
        }

        $twig = method_exists($this, 'getTwig') ? $this->getTwig() : $this->get('twig');

        return $twig->render('@Modules/vismapay/views/templates/admin/vismapay_settle_base.html.twig', [
            'orderId' => $order->id,
            'logoUrl' => __PS_BASE_URI__ . 'modules/vismapay/views/img/logo.gif',
        ]);
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }
}
