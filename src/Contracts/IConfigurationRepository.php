<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

/**
 * OAuth2 client credentials and Hosted Fields public key material, sourced from each CMS's own
 * settings storage (Sylius configuration entity, WooCommerce options table) — UPC never
 * persists credentials itself.
 *
 * Sylius implementation sketch:
 * <code>
 * final class SyliusConfigurationRepository implements IConfigurationRepository
 * {
 *     private $settingsRepository;
 *
 *     public function get(string $key): ?string
 *     {
 *         $setting = $this->settingsRepository->findOneBy(['key' => $key]);
 *         return $setting === null ? null : $setting->getValue();
 *     }
 *
 *     public function set(string $key, string $value): void
 *     {
 *         // persist via Doctrine
 *     }
 *
 *     public function getClientId(): string { return $this->get('payplug_client_id'); }
 *     // getClientSecret(), getPublicKeyId(), getPublicKeyValue() follow the same pattern
 * }
 * </code>
 *
 * WooCommerce implementation sketch:
 * <code>
 * final class WooCommerceConfigurationRepository implements IConfigurationRepository
 * {
 *     public function get(string $key): ?string
 *     {
 *         $value = get_option($key, false);
 *         return $value === false ? null : $value;
 *     }
 *
 *     public function set(string $key, string $value): void
 *     {
 *         update_option($key, $value);
 *     }
 *     // getClientId(), getClientSecret(), getPublicKeyId(), getPublicKeyValue() call get() with a fixed key
 * }
 * </code>
 */
interface IConfigurationRepository
{
    public function get(string $key): ?string;

    public function set(string $key, string $value): void;

    public function getClientId(): string;

    public function getClientSecret(): string;

    public function getPublicKeyId(): string;

    public function getPublicKeyValue(): string;
}
