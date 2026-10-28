<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

/**
 * Stores the Apple Pay domain-association file fetched by DomainAssociationService, plus a small
 * status record for the plugin's diagnostics screen. Backed by each CMS's own settings storage
 * (Sylius: a Doctrine-backed key/value table; WooCommerce: options), never by a real file under
 * the web root, which would bypass the plugin's route.
 *
 * Rules for implementers:
 * - readContent() returns exactly what writeContent() received: no trim, no re-encoding, no BOM,
 *   no newline normalisation, no slashing/unslashing; use a column type that holds the full
 *   content unchanged (TEXT/LONGTEXT).
 * - readStatus() may return an empty or partial array when nothing has been stored yet; UPC only
 *   relies on the keys it wrote itself.
 * - Any method may throw on a storage failure; DomainAssociationService catches and logs it.
 *
 * Status keys: lastAttemptAt and lastSuccessAt are unix timestamps or null, lastError is an
 * English sentence or null. lastError may contain a raw third-party exception message from the CMS
 * HTTP client or storage, so it must be escaped when displayed on the diagnostics screen.
 *
 * Sylius implementation sketch:
 * <code>
 * final class SyliusDomainAssociationStorage implements IDomainAssociationStorage
 * {
 *     private $settings;
 *
 *     public function readContent(): ?string
 *     {
 *         return $this->settings->get('apple_pay_domain_association');
 *     }
 *
 *     public function writeContent(string $content): void
 *     {
 *         $this->settings->set('apple_pay_domain_association', $content);
 *     }
 *
 *     public function readStatus(): array
 *     {
 *         return json_decode((string) $this->settings->get('apple_pay_domain_association_status'), true) ?: [];
 *     }
 *
 *     public function writeStatus(array $status): void
 *     {
 *         $this->settings->set('apple_pay_domain_association_status', json_encode($status));
 *     }
 * }
 * </code>
 *
 * WooCommerce implementation sketch:
 * <code>
 * final class WooCommerceDomainAssociationStorage implements IDomainAssociationStorage
 * {
 *     public function readContent(): ?string
 *     {
 *         $content = get_option('payplug_apple_pay_domain_association', null);
 *         return is_string($content) ? $content : null;
 *     }
 *
 *     public function writeContent(string $content): void
 *     {
 *         update_option('payplug_apple_pay_domain_association', $content, false);
 *     }
 *
 *     public function readStatus(): array
 *     {
 *         $status = get_option('payplug_apple_pay_domain_association_status', []);
 *         return is_array($status) ? $status : [];
 *     }
 *
 *     public function writeStatus(array $status): void
 *     {
 *         update_option('payplug_apple_pay_domain_association_status', $status, false);
 *     }
 * }
 * </code>
 */
interface IDomainAssociationStorage
{
    /**
     * The stored content exactly as received from the source, or null when nothing is stored.
     */
    public function readContent(): ?string;

    public function writeContent(string $content): void;

    /**
     * @return array{lastAttemptAt: ?int, lastSuccessAt: ?int, lastError: ?string}
     */
    public function readStatus(): array;

    /**
     * @param array{lastAttemptAt: ?int, lastSuccessAt: ?int, lastError: ?string} $status
     */
    public function writeStatus(array $status): void;
}
