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

namespace VismaPayModule\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use PrestaShopBundle\Security\Annotation\AdminSecurity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use VismaPayModule\Form\Type\SettlePaymentType;
use VismaPayModule\Service\SettlementService;

class VismaPayOrderController extends FrameworkBundleAdminController
{
    /**
     * @AdminSecurity("is_granted('update', request.get('_legacy_controller'))", redirectRoute="admin_orders_index")
     */
    public function settleAction(int $orderId, Request $request, SettlementService $settlementService, TranslatorInterface $translator)
    {
        if (!\Module::isEnabled('vismapay')) {
            $this->addFlash('error', $translator->trans('Visma Pay module is disabled.', [], 'Modules.Vismapay.VismaPayOrderController'));

            return $this->redirectToRoute('admin_orders_view', [
                'orderId' => (int) $orderId,
            ]);
        }

        $form = $this->createForm(SettlePaymentType::class, null, [
            'order_id' => (int) $orderId,
        ]);

        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', $translator->trans('Invalid or expired form token.', [], 'Modules.Vismapay.VismaPayOrderController'));

            return $this->redirectToRoute('admin_orders_view', [
                'orderId' => (int) $orderId,
            ]);
        }

        $result = $settlementService->settle($orderId);

        if ($result['success']) {
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('error', $result['message']);
        }

        return $this->redirectToRoute('admin_orders_view', [
            'orderId' => (int) $orderId,
        ]);
    }
}
