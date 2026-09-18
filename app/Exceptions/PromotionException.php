<?php

namespace App\Exceptions;

use RuntimeException;

/** A promotion that cannot be created or changed (Y2); carries a short code for the app. */
class PromotionException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function toArray(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage()];
    }
}
