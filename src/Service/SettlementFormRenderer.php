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

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use VismaPayModule\Form\Type\SettlePaymentType;

class SettlementFormRenderer
{
    private $formFactory;
    private $router;
    private $twig;

    public function __construct(FormFactoryInterface $formFactory, RouterInterface $router, Environment $twig)
    {
        $this->formFactory = $formFactory;
        $this->router = $router;
        $this->twig = $twig;
    }

    public function render(int $orderId): string
    {
        if (!\Module::isEnabled('vismapay')) {
            return '';
        }

        $action = $this->router->generate('vismapay_settle', ['orderId' => $orderId]);

        $form = $this->formFactory->create(SettlePaymentType::class, null, [
            'order_id' => $orderId,
            'action' => $action,
        ]);

        return $this->twig->render('@Modules/vismapay/views/templates/admin/vismapay_settle_form.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
