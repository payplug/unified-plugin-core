<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

/**
 * Applies a PaymentOutcome to the CMS-native order identified by $orderId. Takes the order by
 * ID rather than by CMS-native object — Sylius's OrderInterface and WooCommerce's WC_Order
 * share no common type UPC could hint against — so each implementation loads its own native
 * order internally before mutating it.
 *
 * Sylius implementation sketch:
 * <code>
 * final class SyliusOrderStateMutator implements IOrderStateMutator
 * {
 *     private $orderRepository;
 *     private $stateMachine;
 *
 *     public function apply(string $orderId, string $outcome): void
 *     {
 *         $order = $this->orderRepository->find($orderId);
 *         $this->stateMachine->apply($order, 'sylius_payment', $this->mapOutcomeToTransition($outcome));
 *     }
 * }
 * </code>
 *
 * WooCommerce implementation sketch:
 * <code>
 * final class WooCommerceOrderStateMutator implements IOrderStateMutator
 * {
 *     public function apply(string $orderId, string $outcome): void
 *     {
 *         $order = wc_get_order((int) $orderId);
 *         $order->update_status($this->mapOutcomeToStatus($outcome));
 *     }
 * }
 * </code>
 */
interface IOrderStateMutator
{
    /**
     * @param string $outcome one of the PaymentOutcome::* constants
     */
    public function apply(string $orderId, string $outcome): void;
}
