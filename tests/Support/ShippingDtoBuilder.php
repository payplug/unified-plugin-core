<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Tests\Support;

use PayplugUnifiedCore\Dto\AddressDto;
use PayplugUnifiedCore\Dto\ContactDto;
use PayplugUnifiedCore\Dto\ShippingDto;
use PayplugUnifiedCore\Dto\ShippingScheduleDto;

/**
 * Fluent test builder for a minimal-but-valid ShippingDto, mirroring HostedFieldDtoBuilder's
 * pattern. Deliberately test-only (not shipped under src/) and deliberately NOT used by
 * ShippingDtoTest itself, for the same reason HostedFieldDtoBuilder isn't used by
 * HostedFieldDtoTest — that file proves the real constructor contract this builder assumes.
 */
final class ShippingDtoBuilder
{
    /** @var AddressDto|null */
    private $address;

    /** @var ContactDto|null */
    private $contact;

    /** @var string|null */
    private $email = 'john.snow@example.com';

    /** @var string|null */
    private $companyName;

    /** @var ShippingScheduleDto|null */
    private $schedule;

    public static function valid(): self
    {
        $builder = new self();
        $builder->address = new AddressDto('2 rue de Rivoli', 'Paris', 'FR', 'IDF', '75001');
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

    public function withEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function withCompanyName(?string $companyName): self
    {
        $this->companyName = $companyName;

        return $this;
    }

    public function withSchedule(?ShippingScheduleDto $schedule): self
    {
        $this->schedule = $schedule;

        return $this;
    }

    public function build(): ShippingDto
    {
        return new ShippingDto($this->address, $this->contact, $this->email, $this->companyName, $this->schedule);
    }
}
