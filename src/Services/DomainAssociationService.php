<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Services;

use PayplugUnifiedCore\Contracts\IDomainAssociationStorage;
use PayplugUnifiedCore\Contracts\ILogger;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\DataValues\DomainAssociationEnvironment;
use PayplugUnifiedCore\Output\DomainAssociationRefreshResult;
use PayplugUnifiedCore\Validators\DomainAssociationValidator;

/**
 * Fetches the Apple Pay domain-association file of a Payplug environment (SOURCE_URL in
 * production, QA_SOURCE_URL in QA; see DomainAssociationEnvironment), validates it for that
 * environment, keeps it in the CMS's IDomainAssociationStorage and hands it back for the CMS route
 * (/.well-known/apple-developer-merchantid-domain-association) to serve as is.
 *
 * Unlike the other services, it calls a public static file: no OAuth token, no Authorization
 * header, so it does not extend AbstractUnifiedApiService. The injected IUnifiedApiHttpClient
 * must therefore not add credentials on its own, must verify TLS, and should time out after a few
 * seconds.
 *
 * None of the public methods throws: every failure from the HTTP client or the storage is logged
 * through ILogger and, in refresh(), reported through the returned DomainAssociationRefreshResult
 * and the stored status. Those messages may embed raw exception text from the HTTP client or the
 * storage and must be escaped when displayed.
 *
 * <code>
 * // production by default; pass DomainAssociationEnvironment::QA on Payplug's QA platform
 * $service = new DomainAssociationService($http, $storage, $logger, DomainAssociationEnvironment::QA);
 *
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
    public const QA_SOURCE_URL = 'https://secure-qa.payplug.com/.well-known/apple-developer-merchantid-domain-association';

    private const SOURCE_URLS = [
        DomainAssociationEnvironment::PRODUCTION => self::SOURCE_URL,
        DomainAssociationEnvironment::QA => self::QA_SOURCE_URL,
    ];

    /**
     * Cap, in bytes, on the exception message embedded in an error, so a huge message from a CMS
     * HTTP client cannot bloat the status column or the logs.
     */
    private const MAX_EXCEPTION_MESSAGE_BYTES = 500;

    /** @var IUnifiedApiHttpClient */
    private $http;

    /** @var IDomainAssociationStorage */
    private $storage;

    /** @var ILogger */
    private $logger;

    /** @var string one of the DomainAssociationEnvironment constants */
    private $environment;

    /** @var string */
    private $sourceUrl;

    /** @var string host named in the error messages */
    private $sourceHost;

    /**
     * @throws \InvalidArgumentException when $environment is not a DomainAssociationEnvironment constant
     *                                   (a wiring mistake, caught at construction rather than on a call)
     */
    public function __construct(
        IUnifiedApiHttpClient $http,
        IDomainAssociationStorage $storage,
        ILogger $logger,
        string $environment = DomainAssociationEnvironment::PRODUCTION
    ) {
        if (!DomainAssociationEnvironment::isValid($environment)) {
            throw new \InvalidArgumentException(\sprintf(
                "Unknown domain-association environment '%s'; use a DomainAssociationEnvironment constant.",
                $environment
            ));
        }

        $this->http = $http;
        $this->storage = $storage;
        $this->logger = $logger;
        $this->environment = $environment;
        $this->sourceUrl = self::SOURCE_URLS[$environment];
        $this->sourceHost = (string) parse_url($this->sourceUrl, PHP_URL_HOST);
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

        return $stored !== null && DomainAssociationValidator::isValid($stored, $this->environment) ? $stored : null;
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
    public function refresh(): DomainAssociationRefreshResult
    {
        $now = time();
        try {
            /** @var mixed $response */
            $response = $this->http->get($this->sourceUrl);
        } catch (\Throwable $e) {
            return $this->fail($now, 'Source unreachable: ' . self::describe($e));
        }

        $error = $this->responseError($response);
        if ($error !== null) {
            return $this->fail($now, $error);
        }

        return $this->store($now, $response['body']);
    }

    /**
     * @param mixed $response
     */
    private function responseError($response): ?string
    {
        if (!\is_array($response)
            || !isset($response['status'], $response['body'])
            || !\is_int($response['status'])
            || !\is_string($response['body'])
        ) {
            return 'Source returned a malformed HTTP response.';
        }

        if ($response['status'] !== 200) {
            return \sprintf('Unexpected HTTP status %d from %s.', $response['status'], $this->sourceHost);
        }

        return DomainAssociationValidator::isValid($response['body'], $this->environment)
            ? null
            : \sprintf('Invalid domain-association content received from %s.', $this->sourceHost);
    }

    private function store(int $now, string $body): DomainAssociationRefreshResult
    {
        try {
            $changed = $this->storage->readContent() !== $body;
            if ($changed) {
                $this->storage->writeContent($body);
            }
        } catch (\Throwable $e) {
            return $this->fail($now, 'Could not store the file: ' . self::describe($e));
        }

        // A failing status write is only logged: the file itself is stored and correct at this point,
        // so the result stays updated()/unchanged() and only the diagnostics date is stale.
        $this->writeStatus($now, $now, null);

        return $changed ? DomainAssociationRefreshResult::updated() : DomainAssociationRefreshResult::unchanged();
    }

    private function fail(int $now, string $message): DomainAssociationRefreshResult
    {
        $this->writeStatus($now, $this->previousSuccessAt(), $message);
        $this->logger->error('apple_pay.well_known.refresh_failed', ['message' => $message]);

        return DomainAssociationRefreshResult::failed($message);
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

        $lastSuccessAt = \is_array($previous) && isset($previous['lastSuccessAt']) ? $previous['lastSuccessAt'] : null;

        // A string-typed CMS store (one setting per key) hands the timestamp back as a digit string.
        if (\is_string($lastSuccessAt) && preg_match('/^\d+$/D', $lastSuccessAt) === 1) {
            $lastSuccessAt = (int) $lastSuccessAt;
        }

        return \is_int($lastSuccessAt) ? $lastSuccessAt : null;
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
        $message = $e->getMessage();
        if (\strlen($message) > self::MAX_EXCEPTION_MESSAGE_BYTES) {
            $message = self::cutUtf8(substr($message, 0, self::MAX_EXCEPTION_MESSAGE_BYTES)) . '...';
        }

        return \get_class($e) . ': ' . $message;
    }

    /**
     * Drops a multibyte character left incomplete by a byte cut, so the error stays valid UTF-8
     * (json_encode() in a CMS store would otherwise fail on it). Uses pcre only: mbstring is not
     * guaranteed on a merchant host.
     */
    private static function cutUtf8(string $cut): string
    {
        if (preg_match('//u', $cut) === 1) {
            return $cut;
        }

        return (string) preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', $cut);
    }
}
