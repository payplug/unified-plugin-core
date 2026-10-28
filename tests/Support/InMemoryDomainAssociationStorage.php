<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Support;

use PayplugUnifiedCore\Contracts\IDomainAssociationStorage;

/**
 * IDomainAssociationStorage kept in memory, counting writes and able to fail on demand. $status is
 * deliberately loosely typed so a test can plant the partial or corrupted record a real CMS store
 * may hand back.
 */
final class InMemoryDomainAssociationStorage implements IDomainAssociationStorage
{
    /** @var string|null */
    public $content;

    /** @var array<mixed> */
    public $status = ['lastAttemptAt' => null, 'lastSuccessAt' => null, 'lastError' => null];

    /** @var int */
    public $writeContentCalls = 0;

    /** @var int */
    public $writeStatusCalls = 0;

    /** @var bool */
    public $throwOnReadContent = false;

    /** @var bool */
    public $throwOnWriteContent = false;

    /** @var bool */
    public $throwOnReadStatus = false;

    /** @var bool */
    public $throwOnWriteStatus = false;

    public function readContent(): ?string
    {
        if ($this->throwOnReadContent) {
            throw new \RuntimeException('storage down');
        }

        return $this->content;
    }

    public function writeContent(string $content): void
    {
        if ($this->throwOnWriteContent) {
            throw new \RuntimeException('storage down');
        }

        ++$this->writeContentCalls;
        $this->content = $content;
    }

    public function readStatus(): array
    {
        if ($this->throwOnReadStatus) {
            throw new \RuntimeException('storage down');
        }

        // @phpstan-ignore-next-line return.type (a test can plant a partial or corrupted status record, as a real CMS store may return one)
        return $this->status;
    }

    public function writeStatus(array $status): void
    {
        if ($this->throwOnWriteStatus) {
            throw new \RuntimeException('storage down');
        }

        ++$this->writeStatusCalls;
        $this->status = $status;
    }
}
