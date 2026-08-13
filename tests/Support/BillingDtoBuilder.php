<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Support;

use PayplugUnifiedCore\Dto\AddressDto;
use PayplugUnifiedCore\Dto\BillingDto;
use PayplugUnifiedCore\Dto\ContactDto;

/**
 * Fluent test builder for a minimal-but-valid BillingDto, mirroring HostedFieldDtoBuilder's
 * pattern. Deliberately test-only (not shipped under src/) and deliberately NOT used by
 * BillingDtoTest itself, for the same reason HostedFieldDtoBuilder isn't used by
 * HostedFieldDtoTest — that file proves the real constructor contract this builder assumes.
 */
final class BillingDtoBuilder
{
    /** @var AddressDto|null */
    private $address;

    /** @var ContactDto|null */
    private $contact;

    /** @var string|null */
    private $title = 'MR';

    public static function valid(): self
    {
        $builder = new self();
        $builder->address = new AddressDto('1 rue de Rivoli', 'Paris', 'FR', 'IDF', '75001');
        $builder->contact = ContactDtoBuilder::valid()->build();

        return $builder;
    }

    public function withAddress(?AddressDto $address): self
    {
        $this->address = $address;

        return $this;
    }

    public function withContact(?ContactDto $contact): self
    {
        $this->contact = $contact;

        return $this;
    }

    public function withTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function build(): BillingDto
    {
        return new BillingDto($this->address, $this->contact, $this->title);
    }
}
