<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

use PayplugUnifiedCore\Exceptions\PaymentNotFoundException;
use PayplugUnifiedCore\Models\OperationData;

/**
 * Persists and retrieves OperationData, and tracks webhook processing state so a webhook
 * retried by Payplug isn't treated twice. Backed by each CMS's own persistence layer (Sylius:
 * a Doctrine entity/repository; WooCommerce: order meta or a custom table) — UPC has no
 * database of its own.
 *
 * Sylius implementation sketch:
 * <code>
 * final class SyliusPaymentRepository implements IPaymentRepository
 * {
 *     private $entityRepository;
 *
 *     public function getByOrderId(string $orderId): OperationData
 *     {
 *         $entity = $this->entityRepository->findOneBy(['orderId' => $orderId]);
 *         if ($entity === null) {
 *             throw new PaymentNotFoundException(sprintf('No operation for order "%s".', $orderId));
 *         }
 *         return $entity->toOperationData();
 *     }
 *     // getByOperationId(), save(), markTreated(), isTreated() follow the same Doctrine pattern
 * }
 * </code>
 *
 * WooCommerce implementation sketch:
 * <code>
 * final class WooCommercePaymentRepository implements IPaymentRepository
 * {
 *     public function getByOrderId(string $orderId): OperationData
 *     {
 *         $order = wc_get_order((int) $orderId);
 *         if ($order === false || $order->get_meta('_payplug_operation_id') === '') {
 *             throw new PaymentNotFoundException(sprintf('No operation for order "%s".', $orderId));
 *         }
 *         return $this->fromOrderMeta($order);
 *     }
 *     // getByOperationId(), save(), markTreated(), isTreated() read/write order meta similarly
 * }
 * </code>
 */
interface IPaymentRepository
{
    /**
     * @throws PaymentNotFoundException if no operation is stored for $orderId
     */
    public function getByOrderId(string $orderId): OperationData;

    /**
     * @throws PaymentNotFoundException if no operation is stored for $operationId
     */
    public function getByOperationId(string $operationId): OperationData;

    public function save(OperationData $operationData): void;

    public function markTreated(string $operationId): void;

    public function isTreated(string $operationId): bool;
}
