<?php

namespace App\Exceptions;

use RuntimeException;

/** A debit that the wallet cannot cover (Y2). */
class InsufficientCoinsException extends RuntimeException
{
    public function __construct(public readonly int $needed, public readonly int $balance)
    {
        parent::__construct("Not enough coins: {$needed} needed, {$balance} available.");
    }

    public function toArray(): array
    {
        return ['code' => 'insufficient_coins', 'message' => 'Not enough coins.', 'needed' => $this->needed, 'balance' => $this->balance];
    }
}
