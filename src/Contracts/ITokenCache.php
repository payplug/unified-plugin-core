<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

/**
 * Caches the OAuth2 JWT UPC uses to authenticate against the (future) Unified API, keyed by a
 * cache key the caller controls. Backed by each CMS's own cache (Sylius: a Symfony Cache
 * PSR-16 adapter; WooCommerce: transients). The 298s token TTL / 240s renewal threshold are the
 * caller's concern (an Unified API client, not yet implemented) — this contract only stores a
 * value for whatever TTL it's given.
 *
 * Sylius implementation sketch:
 * <code>
 * final class SyliusTokenCache implements ITokenCache
 * {
 *     private $cache;
 *
 *     public function get(string $key): ?string
 *     {
 *         return $this->cache->getItem($key)->get();
 *     }
 *
 *     public function set(string $key, string $value, int $ttlSeconds): void
 *     {
 *         $item = $this->cache->getItem($key);
 *         $item->set($value);
 *         $item->expiresAfter($ttlSeconds);
 *         $this->cache->save($item);
 *     }
 *
 *     public function delete(string $key): void
 *     {
 *         $this->cache->deleteItem($key);
 *     }
 * }
 * </code>
 *
 * WooCommerce implementation sketch:
 * <code>
 * final class WooCommerceTokenCache implements ITokenCache
 * {
 *     public function get(string $key): ?string
 *     {
 *         $value = get_transient('payplug_token_' . $key);
 *         return $value === false ? null : $value;
 *     }
 *
 *     public function set(string $key, string $value, int $ttlSeconds): void
 *     {
 *         set_transient('payplug_token_' . $key, $value, $ttlSeconds);
 *     }
 *
 *     public function delete(string $key): void
 *     {
 *         delete_transient('payplug_token_' . $key);
 *     }
 * }
 * </code>
 */
interface ITokenCache
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
