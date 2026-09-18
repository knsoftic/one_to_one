<?php

namespace App\Exceptions;

use RuntimeException;

/** Something a payment cannot do right now (Y2); carries a short code and the HTTP status to answer with. */
class PaymentException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function toArray(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage()];
    }
}
