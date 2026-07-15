<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

/**
 * Per-operation mutex preventing a webhook retried by Payplug from being processed
 * concurrently with itself. Backed by each CMS's own locking primitive (Sylius: Symfony's
 * Lock component; WooCommerce: a transient used as a simple mutex) — UPC has no lock store of
 * its own.
 *
 * Sylius implementation sketch:
 * <code>
 * final class SyliusLock implements ILock
 * {
 *     private $lockFactory;
 *     private $locks = [];
 *
 *     public function acquire(string $key, int $ttlSeconds): bool
 *     {
 *         $lock = $this->lockFactory->createLock($key, $ttlSeconds);
 *         if (!$lock->acquire()) {
 *             return false;
 *         }
 *         $this->locks[$key] = $lock;
 *         return true;
 *     }
 *
 *     public function release(string $key): void
 *     {
 *         if (isset($this->locks[$key])) {
 *             $this->locks[$key]->release();
 *             unset($this->locks[$key]);
 *         }
 *     }
 * }
 * </code>
 *
 * WooCommerce implementation sketch:
 * <code>
 * final class WooCommerceLock implements ILock
 * {
 *     public function acquire(string $key, int $ttlSeconds): bool
 *     {
 *         if (get_transient('payplug_lock_' . $key) !== false) {
 *             return false;
 *         }
 *         return set_transient('payplug_lock_' . $key, '1', $ttlSeconds);
 *     }
 *
 *     public function release(string $key): void
 *     {
 *         delete_transient('payplug_lock_' . $key);
 *     }
 * }
 * </code>
 */
interface ILock
{
    public function acquire(string $key, int $ttlSeconds): bool;

    public function release(string $key): void;
}
