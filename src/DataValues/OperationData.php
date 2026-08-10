<?php

declare(strict_types=1);

namespace PayplugUnifiedCore\DataValues;

use PayplugUnifiedCore\Exceptions\InvalidOperationDataException;
use PayplugUnifiedCore\Utilities\Helpers\Assert;

/**
 * Persistence value object returned by IPaymentRepository (see PRE-3467), with no
 * dependency on the payplug/payplug-php SDK. Construct this only from data that has
 * already crossed UPC's external boundary (a Payplug API response or webhook payload) —
 * the constructor validates the result, it does not sanitize raw untrusted input itself.
 *
 * Lives in DataValues/, not Output/, on purpose: WebhookNotificationHelper::parse() does produce
 * this from a parsed webhook payload, which reads as Output/-shaped — but that's not its whole
 * story. It's also exactly what IPaymentRepository::save()/getByOrderId()/getByOperationId()
 * persist and re-fetch — durable state with a life beyond any single call, which is DataValues/'s
 * defining trait, not Output/'s.
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
        Assert::notEmpty($operationId, 'operationId', InvalidOperationDataException::class);
        Assert::notEmpty($execCode, 'execCode', InvalidOperationDataException::class);

        if (!PaymentOutcome::isValid($outcome)) {
            throw new InvalidOperationDataException(\sprintf('"%s" is not a valid PaymentOutcome.', $outcome));
        }

        Assert::notNegative($amount, 'amount', InvalidOperationDataException::class);
        Assert::notEmpty($orderId, 'orderId', InvalidOperationDataException::class);

        $this->operationId = $operationId;
        $this->execCode = $execCode;
        $this->outcome = $outcome;
        $this->amount = $amount;
        $this->orderId = $orderId;
    }
}
