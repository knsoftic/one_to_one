<?php

namespace App\Exceptions;

use RuntimeException;

/** The admin froze this wallet: no debits and no promotions until it is unfrozen (Y2). */
class WalletFrozenException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This wallet is frozen.');
    }

    public function toArray(): array
    {
        return ['code' => 'wallet_frozen', 'message' => 'Your wallet is on hold. Contact support.'];
    }
}
