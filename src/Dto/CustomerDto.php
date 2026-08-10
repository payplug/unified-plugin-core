<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Dto;

/**
 * End-user identity for a payment-creation call. Both fields are required together by the
 * Unified API schema whenever customer data is sent at all — same "required constructor
 * parameters make a partial object impossible" reasoning as BrowserDto.
 */
final class CustomerDto
{
    /** @var string */
    public $id;

    /** @var string */
    public $email;

    public function __construct(string $id, string $email)
    {
        $this->id = $id;
        $this->email = $email;
    }

    /**
     * @return array{id: string, email: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
        ];
    }
}
