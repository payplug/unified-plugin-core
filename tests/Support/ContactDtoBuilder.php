<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Support;

use PayplugUnifiedCore\Dto\ContactDto;

/**
 * Fluent test builder for a minimal-but-valid ContactDto, mirroring HostedFieldDtoBuilder's
 * pattern. Deliberately test-only (not shipped under src/) and deliberately NOT used by
 * ContactDtoTest itself, for the same reason HostedFieldDtoBuilder isn't used by
 * HostedFieldDtoTest — that file proves the real constructor contract this builder assumes.
 */
final class ContactDtoBuilder
{
    /** @var string|null */
    private $firstName = 'John';

    /** @var string|null */
    private $lastName = 'Snow';

    /** @var string|null */
    private $phone = '+33100000000';

    /** @var string|null */
    private $mobilePhone = '+33600000000';

    public static function valid(): self
    {
        return new self();
    }

    public function withFirstName(?string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function withLastName(?string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function withPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function withMobilePhone(?string $mobilePhone): self
    {
        $this->mobilePhone = $mobilePhone;

        return $this;
    }

    public function build(): ContactDto
    {
        return new ContactDto($this->firstName, $this->lastName, $this->phone, $this->mobilePhone);
    }
}
