<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Output;

/**
 * Output of DomainAssociationService::refresh(). Unvalidated and produced internally, like the
 * other Output classes, but with a private constructor: the three named constructors are the only
 * way to build one, which guarantees that error is non-null exactly when status is STATUS_FAILED.
 *
 * error may embed a raw exception message from the CMS HTTP client or storage, so it must be
 * escaped when displayed: Twig autoescape, esc_html() in WordPress, htmlspecialchars() otherwise.
 *
 * <code>
 * $result = $domainAssociationService->refresh();
 * if ($result->isFailed()) {
 *     echo '<p class="error">' . htmlspecialchars($result->error, ENT_QUOTES, 'UTF-8') . '</p>';
 * }
 * </code>
 */
final class RefreshResult
{
    public const STATUS_UPDATED = 'updated';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_FAILED = 'failed';

    /** @var string one of the STATUS_* constants */
    public $status;

    /**
     * @var string|null English sentence describing the failure; null unless status is
     *                  STATUS_FAILED. May contain a raw third-party exception message: escape it
     *                  when displayed.
     */
    public $error;

    private function __construct(string $status, ?string $error)
    {
        $this->status = $status;
        $this->error = $error;
    }

    /**
     * A valid file was fetched and differed from the stored copy, which it replaced.
     */
    public static function updated(): self
    {
        return new self(self::STATUS_UPDATED, null);
    }

    /**
     * A valid file was fetched and was identical to the stored copy; nothing was written.
     */
    public static function unchanged(): self
    {
        return new self(self::STATUS_UNCHANGED, null);
    }

    /**
     * The refresh failed; any previously stored file is left in place.
     */
    public static function failed(string $error): self
    {
        return new self(self::STATUS_FAILED, $error);
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
}
