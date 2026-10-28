<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Contracts\IDomainAssociationStorage;
use PayplugUnifiedCore\Contracts\ILogger;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Output\RefreshResult;
use PayplugUnifiedCore\Validators\DomainAssociationValidator;

/**
 * Fetches the Apple Pay domain-association file from secure.payplug.com, validates it, keeps it in
 * the CMS's IDomainAssociationStorage and hands it back for the CMS route
 * (/.well-known/apple-developer-merchantid-domain-association) to serve as is.
 *
 * Unlike the other services, it calls a public static file: no OAuth token, no Authorization
 * header, so it does not extend AbstractUnifiedApiService. The injected IUnifiedApiHttpClient
 * must therefore not add credentials on its own, must verify TLS, and should time out after a few
 * seconds.
 *
 * None of the public methods throws: every failure from the HTTP client or the storage is logged
 * through ILogger and, in refresh(), reported through the returned RefreshResult and the stored
 * status. Those messages may embed raw exception text from the HTTP client or the storage and must
 * be escaped when displayed.
 *
 * <code>
 * // route
 * $content = $service->resolve();
 * return $content === null ? new Response('', 404) : new Response($content, 200, ['Content-Type' => 'text/plain']);
 *
 * // install / upgrade hook
 * $service->ensureStored();
 *
 * // "Resync" button or CLI command
 * $result = $service->refresh();
 * </code>
 */
final class DomainAssociationService
{
    public const SOURCE_URL = 'https://secure.payplug.com/.well-known/apple-developer-merchantid-domain-association';

    /** @var IUnifiedApiHttpClient */
    private $http;

    /** @var IDomainAssociationStorage */
    private $storage;

    /** @var ILogger */
    private $logger;

    public function __construct(IUnifiedApiHttpClient $http, IDomainAssociationStorage $storage, ILogger $logger)
    {
        $this->http = $http;
        $this->storage = $storage;
        $this->logger = $logger;
    }

    /**
     * The stored content, byte for byte, when it is valid; null otherwise (the route then answers
     * 404). Never calls the network.
     */
    public function resolve(): ?string
    {
        try {
            $stored = $this->storage->readContent();
        } catch (\Throwable $e) {
            $this->logger->error('apple_pay.well_known.resolve_failed', ['message' => self::describe($e)]);

            return null;
        }

        return $stored !== null && DomainAssociationValidator::isValid($stored) ? $stored : null;
    }

    /**
     * Downloads the file only when nothing valid is stored yet: called on plugin install and on
     * the first upgrade that ships the feature, a no-op on later upgrades.
     */
    public function ensureStored(): void
    {
        if ($this->resolve() === null) {
            $this->refresh();
        }
    }

    /**
     * The only network entry point. Replaces the stored copy only when the fetched content is
     * valid and differs from it; on any failure the stored copy is left in place and the error is
     * recorded in the status.
     */
    public function refresh(): RefreshResult
    {
        $now = time();
        try {
            /** @var mixed $response */
            $response = $this->http->get(self::SOURCE_URL);
        } catch (\Throwable $e) {
            return $this->fail($now, 'Source unreachable: ' . self::describe($e));
        }

        $error = self::responseError($response);
        if ($error !== null) {
            return $this->fail($now, $error);
        }

        return $this->store($now, $response['body']);
    }

    /**
     * @param mixed $response
     */
    private static function responseError($response): ?string
    {
        if (!\is_array($response)
            || !isset($response['status'], $response['body'])
            || !\is_int($response['status'])
            || !\is_string($response['body'])
        ) {
            return 'Source returned a malformed HTTP response.';
        }

        if ($response['status'] !== 200) {
            return \sprintf('Unexpected HTTP status %d from secure.payplug.com.', $response['status']);
        }

        return DomainAssociationValidator::isValid($response['body'])
            ? null
            : 'Invalid domain-association content received from secure.payplug.com.';
    }

    private function store(int $now, string $body): RefreshResult
    {
        try {
            $changed = $this->storage->readContent() !== $body;
            if ($changed) {
                $this->storage->writeContent($body);
            }
        } catch (\Throwable $e) {
            return $this->fail($now, 'Could not store the file: ' . self::describe($e));
        }

        $this->writeStatus($now, $now, null);

        return $changed ? RefreshResult::updated() : RefreshResult::unchanged();
    }

    private function fail(int $now, string $message): RefreshResult
    {
        $this->writeStatus($now, $this->previousSuccessAt(), $message);
        $this->logger->error('apple_pay.well_known.refresh_failed', ['message' => $message]);

        return RefreshResult::failed($message);
    }

    private function previousSuccessAt(): ?int
    {
        try {
            /** @var mixed $previous */
            $previous = $this->storage->readStatus();
        } catch (\Throwable $e) {
            $this->logger->error('apple_pay.well_known.status_read_failed', ['message' => self::describe($e)]);

            return null;
        }

        return \is_array($previous) && isset($previous['lastSuccessAt']) && \is_int($previous['lastSuccessAt'])
            ? $previous['lastSuccessAt']
            : null;
    }

    private function writeStatus(int $attemptAt, ?int $successAt, ?string $error): void
    {
        try {
            $this->storage->writeStatus(['lastAttemptAt' => $attemptAt, 'lastSuccessAt' => $successAt, 'lastError' => $error]);
        } catch (\Throwable $e) {
            $this->logger->error('apple_pay.well_known.status_write_failed', ['message' => self::describe($e)]);
        }
    }

    private static function describe(\Throwable $e): string
    {
        return \get_class($e) . ': ' . $e->getMessage();
    }
}
