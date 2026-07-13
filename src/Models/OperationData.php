<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\Models;

use PayplugUnifiedCore\Exceptions\InvalidOperationDataException;

/**
 * Persistence value object returned by IPaymentRepository (see PRE-3467), with no
 * dependency on the payplug/payplug-php SDK. Construct this only from data that has
 * already crossed UPC's external boundary (a Payplug API response or webhook payload) —
 * the constructor validates the result, it does not sanitize raw untrusted input itself.
 */
final class OperationData
{
    /** @var string */
    public $operationId;

    /** @var string */
    public $execCode;

    /** @var string */
    public $outcome;

    /** @var int */
    public $amount;

    /** @var string */
    public $orderId;

    public function __construct(string $operationId, string $execCode, string $outcome, int $amount, string $orderId)
    {
        if ($operationId === '') {
            throw new InvalidOperationDataException('operationId must not be empty.');
        }

        if ($execCode === '') {
            throw new InvalidOperationDataException('execCode must not be empty.');
        }

        if (!PaymentOutcome::isValid($outcome)) {
            throw new InvalidOperationDataException(\sprintf('"%s" is not a valid PaymentOutcome.', $outcome));
        }

        if ($amount < 0) {
            throw new InvalidOperationDataException('amount must not be negative.');
        }

        if ($orderId === '') {
            throw new InvalidOperationDataException('orderId must not be empty.');
        }

        $this->operationId = $operationId;
        $this->execCode = $execCode;
        $this->outcome = $outcome;
        $this->amount = $amount;
        $this->orderId = $orderId;
    }
}
