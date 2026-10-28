<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Services;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Mockery\MockInterface;
use PayplugUnifiedCore\Contracts\ILogger;
use PayplugUnifiedCore\Contracts\IUnifiedApiHttpClient;
use PayplugUnifiedCore\Output\RefreshResult;
use PayplugUnifiedCore\Services\DomainAssociationService;
use PayplugUnifiedCore\Tests\Support\DomainAssociationFixtures;
use PayplugUnifiedCore\Tests\Support\InMemoryDomainAssociationStorage;

final class DomainAssociationServiceTest extends MockeryTestCase
{
    /** @var InMemoryDomainAssociationStorage */
    private $storage;

    /** @var ILogger&MockInterface */
    private $logger;

    protected function setUp(): void
    {
        $this->storage = new InMemoryDomainAssociationStorage();
        $this->logger = Mockery::spy(ILogger::class);
    }

    public function testResolveReturnsStoredValidContentVerbatim(): void
    {
        $this->storage->content = DomainAssociationFixtures::valid();

        self::assertSame(DomainAssociationFixtures::valid(), $this->makeService($this->httpNeverCalled())->resolve());
    }

    public function testResolveReturnsNullWhenNothingStored(): void
    {
        self::assertNull($this->makeService($this->httpNeverCalled())->resolve());
    }

    /**
     * @dataProvider invalidStoredContentProvider
     */
    public function testResolveReturnsNullWhenStoredContentIsInvalid(string $stored): void
    {
        $this->storage->content = $stored;

        self::assertNull($this->makeService($this->httpNeverCalled())->resolve());
    }

    /**
     * @return array<string, array{string}>
     */
    public function invalidStoredContentProvider(): array
    {
        return [
            'HTML page' => [DomainAssociationFixtures::htmlChallengePage()],
            'recette pspId' => [DomainAssociationFixtures::recettePspId()],
            'empty' => [''],
        ];
    }

    public function testResolveReturnsNullAndLogsWhenStorageThrows(): void
    {
        $this->storage->throwOnReadContent = true;

        self::assertNull($this->makeService($this->httpNeverCalled())->resolve());
        $this->logger->shouldHaveReceived('error', ['apple_pay.well_known.resolve_failed', ['message' => 'RuntimeException: storage down']])->once();
    }

    public function testEnsureStoredDownloadsOnceWhenNothingStoredAndNotAgainAfterwards(): void
    {
        $service = $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::valid()]));

        $service->ensureStored();
        $service->ensureStored();

        self::assertSame(DomainAssociationFixtures::valid(), $this->storage->content);
        self::assertSame(1, $this->storage->writeContentCalls);
    }

    public function testEnsureStoredDoesNotDownloadWhenValidContentIsStored(): void
    {
        $this->storage->content = DomainAssociationFixtures::valid();

        $this->makeService($this->httpNeverCalled())->ensureStored();

        self::assertSame(0, $this->storage->writeContentCalls);
        self::assertSame(0, $this->storage->writeStatusCalls);
    }

    public function testEnsureStoredDownloadsWhenStoredContentIsInvalid(): void
    {
        $this->storage->content = DomainAssociationFixtures::recettePspId();

        $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::valid()]))->ensureStored();

        self::assertSame(DomainAssociationFixtures::valid(), $this->storage->content);
    }

    public function testEnsureStoredDoesNotThrowWhenTheSourceIsUnreachable(): void
    {
        $http = Mockery::mock(IUnifiedApiHttpClient::class);
        $http->shouldReceive('get')->once()->andThrow(new \RuntimeException('Could not resolve host'));

        $this->makeService($http)->ensureStored();

        self::assertNull($this->storage->content);
        self::assertSame('Source unreachable: RuntimeException: Could not resolve host', $this->storage->status['lastError']);
    }

    public function testRefreshStoresValidContentByteForByte(): void
    {
        $result = $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::valid()]))->refresh();

        self::assertSame(RefreshResult::STATUS_UPDATED, $result->status);
        self::assertNull($result->error);
        self::assertSame(DomainAssociationFixtures::valid(), $this->storage->content);
        $this->logger->shouldNotHaveReceived('error');
    }

    public function testRefreshKeepsACrlfTrailingNewline(): void
    {
        $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::validWithCrlf()]))->refresh();

        self::assertSame(DomainAssociationFixtures::validWithCrlf(), $this->storage->content);
    }

    public function testRefreshWritesNothingWhenContentIsIdentical(): void
    {
        $this->storage->content = DomainAssociationFixtures::valid();

        $result = $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::valid()]))->refresh();

        self::assertSame(RefreshResult::STATUS_UNCHANGED, $result->status);
        self::assertSame(0, $this->storage->writeContentCalls);
        self::assertSame(1, $this->storage->writeStatusCalls);
        self::assertNull($this->storage->status['lastError']);
    }

    public function testRefreshTreatsANewlineDifferenceAsAChange(): void
    {
        $this->storage->content = DomainAssociationFixtures::valid();

        $result = $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::validWithCrlf()]))->refresh();

        self::assertSame(RefreshResult::STATUS_UPDATED, $result->status);
        self::assertSame(DomainAssociationFixtures::validWithCrlf(), $this->storage->content);
    }

    public function testRefreshReplacesWhenContentIsValidAndDifferent(): void
    {
        $this->storage->content = DomainAssociationFixtures::valid();

        $result = $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::validCreatedLater()]))->refresh();

        self::assertSame(RefreshResult::STATUS_UPDATED, $result->status);
        self::assertSame(1, $this->storage->writeContentCalls);
        self::assertSame(DomainAssociationFixtures::validCreatedLater(), $this->storage->content);
    }

    public function testRefreshWritesStatusWithCurrentTimestamps(): void
    {
        $before = time();
        $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::valid()]))->refresh();
        $after = time();

        self::assertSame(['lastAttemptAt', 'lastSuccessAt', 'lastError'], array_keys($this->storage->status));
        self::assertIsInt($this->storage->status['lastAttemptAt']);
        self::assertGreaterThanOrEqual($before, $this->storage->status['lastAttemptAt']);
        self::assertLessThanOrEqual($after, $this->storage->status['lastAttemptAt']);
        self::assertSame($this->storage->status['lastAttemptAt'], $this->storage->status['lastSuccessAt']);
        self::assertNull($this->storage->status['lastError']);
    }

    public function testRefreshClearsAPreviousError(): void
    {
        $this->storage->status = ['lastAttemptAt' => 1600000000, 'lastSuccessAt' => null, 'lastError' => 'Source unreachable: RuntimeException: timeout'];

        $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::valid()]))->refresh();

        $status = $this->storage->readStatus();
        self::assertNull($status['lastError']);
        self::assertIsInt($status['lastSuccessAt']);
    }

    public function testRefreshKeepsItsResultAndLogsWhenOnlyTheStatusWriteThrows(): void
    {
        $this->storage->throwOnWriteStatus = true;

        $result = $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::valid()]))->refresh();

        self::assertSame(RefreshResult::STATUS_UPDATED, $result->status);
        self::assertSame(DomainAssociationFixtures::valid(), $this->storage->content);
        $this->logger->shouldHaveReceived('error', ['apple_pay.well_known.status_write_failed', ['message' => 'RuntimeException: storage down']])->once();
    }

    /**
     * @dataProvider rejectedResponseProvider
     *
     * @param array<mixed> $response
     */
    public function testRefreshFailsAndKeepsTheStoredFileOnARejectedResponse(array $response, string $expectedMessage): void
    {
        $this->givenAPreviousSuccessfulSync();

        $before = time();
        $result = $this->makeService($this->httpReturning($response))->refresh();

        $this->assertFailedAndPreviousSyncKept($result, $expectedMessage, $before);
    }

    /**
     * @return array<string, array{array<mixed>, string}>
     */
    public function rejectedResponseProvider(): array
    {
        $invalidContent = 'Invalid domain-association content received from secure.payplug.com.';
        $malformed = 'Source returned a malformed HTTP response.';

        return [
            'HTTP 503' => [['status' => 503, 'body' => DomainAssociationFixtures::valid()], 'Unexpected HTTP status 503 from secure.payplug.com.'],
            'HTTP 301' => [['status' => 301, 'body' => ''], 'Unexpected HTTP status 301 from secure.payplug.com.'],
            'HTTP 404' => [['status' => 404, 'body' => 'Not Found'], 'Unexpected HTTP status 404 from secure.payplug.com.'],
            '200 with an HTML page' => [['status' => 200, 'body' => DomainAssociationFixtures::htmlChallengePage()], $invalidContent],
            '200 with a truncated file' => [['status' => 200, 'body' => DomainAssociationFixtures::truncated()], $invalidContent],
            '200 with a BOM-prefixed file' => [['status' => 200, 'body' => DomainAssociationFixtures::bomPrefixed()], $invalidContent],
            '200 with the recette file' => [['status' => 200, 'body' => DomainAssociationFixtures::recettePspId()], $invalidContent],
            '200 with an odd-length body' => [['status' => 200, 'body' => DomainAssociationFixtures::oddLength()], $invalidContent],
            '200 with an empty body' => [['status' => 200, 'body' => ''], $invalidContent],
            'empty response array' => [[], $malformed],
            'missing status' => [['body' => DomainAssociationFixtures::valid()], $malformed],
            'missing body' => [['status' => 200], $malformed],
            'empty-string status' => [['status' => '', 'body' => ''], $malformed],
            'numeric-string status' => [['status' => '200', 'body' => DomainAssociationFixtures::valid()], $malformed],
            'null body' => [['status' => 200, 'body' => null], $malformed],
        ];
    }

    /**
     * @dataProvider clientThrowableProvider
     */
    public function testRefreshFailsAndKeepsTheStoredFileWhenTheClientThrows(\Throwable $thrown, string $expectedMessage): void
    {
        $this->givenAPreviousSuccessfulSync();
        $http = Mockery::mock(IUnifiedApiHttpClient::class);
        $http->shouldReceive('get')->once()->with(DomainAssociationService::SOURCE_URL)->andThrow($thrown);

        $before = time();
        $result = $this->makeService($http)->refresh();

        $this->assertFailedAndPreviousSyncKept($result, $expectedMessage, $before);
    }

    /**
     * @return array<string, array{\Throwable, string}>
     */
    public function clientThrowableProvider(): array
    {
        return [
            'RuntimeException' => [new \RuntimeException('Operation timed out'), 'Source unreachable: RuntimeException: Operation timed out'],
            'TypeError' => [new \TypeError('Argument 1 must be of type array'), 'Source unreachable: TypeError: Argument 1 must be of type array'],
        ];
    }

    public function testRefreshFailsAndKeepsTheStoredFileWhenWritingContentThrows(): void
    {
        $this->givenAPreviousSuccessfulSync();
        $this->storage->throwOnWriteContent = true;

        $before = time();
        $result = $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::validCreatedLater()]))->refresh();

        $this->assertFailedAndPreviousSyncKept($result, 'Could not store the file: RuntimeException: storage down', $before);
    }

    public function testRefreshFailsWhenReadingTheStoredContentThrows(): void
    {
        $this->givenAPreviousSuccessfulSync();
        $this->storage->throwOnReadContent = true;

        $before = time();
        $result = $this->makeService($this->httpReturning(['status' => 200, 'body' => DomainAssociationFixtures::validCreatedLater()]))->refresh();

        $this->assertFailedAndPreviousSyncKept($result, 'Could not store the file: RuntimeException: storage down', $before);
    }

    public function testAFailedRefreshWithNothingStoredLeavesResolveNull(): void
    {
        $service = $this->makeService($this->httpReturning(['status' => 503, 'body' => '']));

        $result = $service->refresh();

        self::assertTrue($result->isFailed());
        self::assertNull($service->resolve());
        self::assertNull($this->storage->status['lastSuccessAt']);
        self::assertSame('Unexpected HTTP status 503 from secure.payplug.com.', $this->storage->status['lastError']);
    }

    public function testAFailedRefreshKeepsServingTheStoredFile(): void
    {
        $this->storage->content = DomainAssociationFixtures::valid();
        $service = $this->makeService($this->httpReturning(['status' => 503, 'body' => '']));

        $service->refresh();

        self::assertSame(DomainAssociationFixtures::valid(), $service->resolve());
    }

    /**
     * @dataProvider previousStatusProvider
     *
     * @param array<mixed> $previousStatus
     */
    public function testAFailedRefreshOnlyKeepsAnIntegerLastSuccessAt(array $previousStatus, ?int $expectedLastSuccessAt): void
    {
        $this->storage->status = $previousStatus;

        $this->makeService($this->httpReturning(['status' => 503, 'body' => '']))->refresh();

        self::assertSame(['lastAttemptAt', 'lastSuccessAt', 'lastError'], array_keys($this->storage->status));
        self::assertSame($expectedLastSuccessAt, $this->storage->status['lastSuccessAt']);
    }

    /**
     * @return array<string, array{array<mixed>, int|null}>
     */
    public function previousStatusProvider(): array
    {
        return [
            'empty record' => [[], null],
            'string lastSuccessAt' => [['lastSuccessAt' => 'x'], null],
            'float lastSuccessAt' => [['lastSuccessAt' => 12.5], null],
            'null lastSuccessAt' => [['lastSuccessAt' => null], null],
            'integer lastSuccessAt' => [['lastSuccessAt' => 1600000000], 1600000000],
            'extra keys dropped' => [['lastSuccessAt' => 1600000000, 'foo' => 'bar'], 1600000000],
        ];
    }

    public function testAFailedRefreshStillWritesAStatusWhenReadingThePreviousOneThrows(): void
    {
        $this->storage->throwOnReadStatus = true;

        $result = $this->makeService($this->httpReturning(['status' => 503, 'body' => '']))->refresh();

        self::assertTrue($result->isFailed());
        self::assertNull($this->storage->status['lastSuccessAt']);
        self::assertSame('Unexpected HTTP status 503 from secure.payplug.com.', $this->storage->status['lastError']);
        $this->logger->shouldHaveReceived('error', ['apple_pay.well_known.status_read_failed', ['message' => 'RuntimeException: storage down']])->once();
    }

    public function testAFailedRefreshStillReturnsItsResultWhenWritingTheStatusThrows(): void
    {
        $this->storage->throwOnWriteStatus = true;

        $result = $this->makeService($this->httpReturning(['status' => 503, 'body' => '']))->refresh();

        self::assertSame(RefreshResult::STATUS_FAILED, $result->status);
        self::assertSame('Unexpected HTTP status 503 from secure.payplug.com.', $result->error);
        $this->logger->shouldHaveReceived('error', ['apple_pay.well_known.refresh_failed', ['message' => 'Unexpected HTTP status 503 from secure.payplug.com.']])->once();
        $this->logger->shouldHaveReceived('error', ['apple_pay.well_known.status_write_failed', ['message' => 'RuntimeException: storage down']])->once();
    }

    private function givenAPreviousSuccessfulSync(): void
    {
        $this->storage->content = DomainAssociationFixtures::valid();
        $this->storage->status = ['lastAttemptAt' => 1600000000, 'lastSuccessAt' => 1600000000, 'lastError' => null];
    }

    private function assertFailedAndPreviousSyncKept(RefreshResult $result, string $expectedMessage, int $before): void
    {
        self::assertSame(RefreshResult::STATUS_FAILED, $result->status);
        self::assertSame($expectedMessage, $result->error);
        self::assertSame(DomainAssociationFixtures::valid(), $this->storage->content);
        self::assertSame(0, $this->storage->writeContentCalls);
        self::assertSame($expectedMessage, $this->storage->status['lastError']);
        self::assertSame(1600000000, $this->storage->status['lastSuccessAt']);
        self::assertGreaterThanOrEqual($before, $this->storage->status['lastAttemptAt']);
        self::assertLessThanOrEqual(time(), $this->storage->status['lastAttemptAt']);
        $this->logger->shouldHaveReceived('error', ['apple_pay.well_known.refresh_failed', ['message' => $expectedMessage]])->once();
    }

    private function makeService(IUnifiedApiHttpClient $http): DomainAssociationService
    {
        return new DomainAssociationService($http, $this->storage, $this->logger);
    }

    /**
     * The source is fetched with its URL as the only argument: no headers, so no credentials.
     *
     * @param array<mixed> $response
     */
    private function httpReturning(array $response): IUnifiedApiHttpClient
    {
        $http = Mockery::mock(IUnifiedApiHttpClient::class);
        $http->shouldReceive('get')->once()->with(DomainAssociationService::SOURCE_URL)->andReturn($response);

        return $http;
    }

    private function httpNeverCalled(): IUnifiedApiHttpClient
    {
        $http = Mockery::mock(IUnifiedApiHttpClient::class);
        $http->shouldNotReceive('get');

        return $http;
    }
}
