<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Contracts;

/**
 * Structured logging sink for UPC's internal diagnostics, decoupled from any CMS's native
 * logging system — UPC code depends only on this contract and never calls a CMS logger
 * directly.
 *
 * Sylius implementation sketch:
 * <code>
 * final class SyliusLogger implements ILogger
 * {
 *     private $psrLogger;
 *
 *     public function __construct(LoggerInterface $psrLogger)
 *     {
 *         $this->psrLogger = $psrLogger;
 *     }
 *
 *     public function debug(string $message, array $context = []): void
 *     {
 *         $this->psrLogger->debug($message, $context);
 *     }
 *     // info(), error() delegate the same way
 * }
 * </code>
 *
 * WooCommerce implementation sketch:
 * <code>
 * final class WooCommerceLogger implements ILogger
 * {
 *     public function debug(string $message, array $context = []): void
 *     {
 *         wc_get_logger()->debug($message, array_merge($context, ['source' => 'payplug']));
 *     }
 *     // info(), error() delegate the same way
 * }
 * </code>
 */
interface ILogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;
}
