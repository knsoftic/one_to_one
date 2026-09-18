<?php

namespace App\Services;

use App\Exceptions\InsufficientCoinsException;
use App\Exceptions\WalletFrozenException;
use App\Models\AppSetting;
use App\Models\CoinTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of wallets (Y2). Every move locks the person's wallet row, checks the cause's
 * idempotency key inside that lock, writes one append-only ledger row and updates the wallet in
 * the same transaction. Balances are unsigned in the database, so nothing can go below zero even
 * if a race slipped past the checks here.
 */
class CoinService
{
    public function __construct(private readonly AdminAuditService $audit) {}

    /** The wallet for display — created on first use, no lock. */
    public function wallet(User $user): Wallet
    {
        return Wallet::query()->firstOrCreate(['user_id' => $user->getKey()], ['updated_at' => now()]);
    }

    /** What the Wallet screen shows. */
    public function summary(User $user): array
    {
        $wallet = $this->wallet($user);

        return [
            'balance' => $wallet->balance,
            'withdrawable' => $wallet->withdrawable,
            'purchased' => $wallet->purchased(),
            'earned_total' => $wallet->earned_total,
            'purchased_total' => $wallet->purchased_total,
            'spent_total' => $wallet->spent_total,
            'frozen' => $wallet->frozen,
            'withdraw_enabled' => (bool) AppSetting::get('wallet_withdraw_enabled'),
        ];
    }

    /**
     * Add coins. `$withdrawable` marks coins earned from referrals (the "Earned" bucket); anything
     * else counts as purchased. A repeated key returns the existing row and changes nothing.
     */
    public function credit(User $user, int $amount, string $type, ?Model $ref, string $key, ?string $note = null, bool $withdrawable = false, array $meta = [], ?User $by = null): CoinTransaction
    {
        $this->assertAmount($amount);

        return $this->move($user, $key, function (Wallet $wallet) use ($user, $amount, $type, $ref, $key, $note, $withdrawable, $meta, $by) {
            $wallet->balance += $amount;
            if ($withdrawable) {
                $wallet->withdrawable += $amount;
                $wallet->earned_total += $amount;
            } else {
                $wallet->purchased_total += $amount;
            }

            return $this->row($user, $wallet, $type, $amount, $withdrawable ? $amount : 0, $ref, $key, $note, $meta, $by);
        });
    }

    /**
     * Take coins. Purchased coins go first; only when they run out does the withdrawable bucket
     * shrink. Throws before writing anything when the wallet is frozen or too small.
     */
    public function debit(User $user, int $amount, string $type, ?Model $ref, string $key, ?string $note = null, array $meta = [], ?User $by = null): CoinTransaction
    {
        $this->assertAmount($amount);

        return $this->move($user, $key, function (Wallet $wallet) use ($user, $amount, $type, $ref, $key, $note, $meta, $by) {
            if ($wallet->frozen) {
                throw new WalletFrozenException;
            }
            if ($wallet->balance < $amount) {
                throw new InsufficientCoinsException($amount, $wallet->balance);
            }

            $fromWithdrawable = max(0, $amount - $wallet->purchased());
            $wallet->balance -= $amount;
            $wallet->withdrawable -= $fromWithdrawable;
            $wallet->spent_total += $amount;

            return $this->row($user, $wallet, $type, -$amount, -$fromWithdrawable, $ref, $key, $note, $meta, $by);
        });
    }

    /**
     * Give back part (or all) of an earlier debit, restoring the withdrawable share in the same
     * proportion the debit took it.
     */
    public function refund(User $user, int $amount, string $type, Model $ref, string $key, CoinTransaction $originalDebit, ?string $note = null): CoinTransaction
    {
        $this->assertAmount($amount);
        $takenFromWithdrawable = abs($originalDebit->withdrawable_delta);
        $share = $originalDebit->amount === 0 ? 0 : intdiv($takenFromWithdrawable * $amount, abs($originalDebit->amount));
        $share = max(0, min($amount, $share));

        return $this->move($user, $key, function (Wallet $wallet) use ($user, $amount, $type, $ref, $key, $note, $share) {
            $wallet->balance += $amount;
            $wallet->withdrawable += $share;
            $wallet->spent_total = max(0, $wallet->spent_total - $amount);

            return $this->row($user, $wallet, $type, $amount, $share, $ref, $key, $note, []);
        });
    }

    /**
     * Take back coins after a refund or a reversed referral. Never throws: takes what is there and
     * records the shortfall in the row's meta (callers copy it onto the payment or referral).
     */
    public function clawback(User $user, int $amount, string $type, ?Model $ref, string $key, string $note): CoinTransaction
    {
        $this->assertAmount($amount);

        return $this->move($user, $key, function (Wallet $wallet) use ($user, $amount, $type, $ref, $key, $note) {
            $taken = min($amount, $wallet->balance);
            $shortfall = $amount - $taken;

            if ($taken === 0) {
                // Nothing to take: report the shortfall without writing a zero-value ledger row.
                return new CoinTransaction([
                    'user_id' => $user->getKey(), 'type' => $type, 'amount' => 0, 'withdrawable_delta' => 0,
                    'balance_after' => $wallet->balance, 'withdrawable_after' => $wallet->withdrawable,
                    'idempotency_key' => $key, 'note' => $note, 'meta' => ['shortfall' => $shortfall],
                ]);
            }

            $fromWithdrawable = max(0, $taken - $wallet->purchased());
            $wallet->balance -= $taken;
            $wallet->withdrawable -= $fromWithdrawable;
            $wallet->spent_total += $taken;

            return $this->row($user, $wallet, $type, -$taken, -$fromWithdrawable, $ref, $key, $note, $shortfall > 0 ? ['shortfall' => $shortfall] : []);
        });
    }

    /** An admin adds or removes coins. Idempotent per form token; audited. */
    public function adjust(User $admin, User $user, int $signedAmount, string $note, string $token): CoinTransaction
    {
        if ($signedAmount === 0) {
            throw new InvalidArgumentException('The amount must not be zero.');
        }
        $key = 'admin:'.$token;
        $existing = $this->findByKey($key);
        if ($existing) {
            return $existing;
        }

        $row = $signedAmount > 0
            ? $this->credit($user, $signedAmount, 'admin_adjust', $user, $key, $note, false, [], $admin)
            : $this->debit($user, -$signedAmount, 'admin_adjust', $user, $key, $note, [], $admin);

        $this->audit->record($admin, 'coins.adjusted', $user, sprintf('%s %s coins for %s: %s', $signedAmount > 0 ? 'Added' : 'Removed', number_format(abs($signedAmount)), $user->name, $note), ['amount' => $signedAmount]);

        return $row;
    }

    /** Freeze (no debits, no promotions) or unfreeze a wallet; audited. */
    public function freeze(User $admin, User $user, bool $frozen): Wallet
    {
        $wallet = $this->wallet($user);
        $wallet->forceFill(['frozen' => $frozen, 'updated_at' => now()])->save();
        $this->audit->record($admin, 'wallet.frozen', $user, ($frozen ? 'Froze' : 'Unfroze')." the wallet of {$user->name}", ['frozen' => $frozen]);

        return $wallet;
    }

    public function history(User $user, int $perPage = 30): LengthAwarePaginator
    {
        return CoinTransaction::query()->where('user_id', $user->getKey())->orderByDesc('id')->paginate($perPage);
    }

    public function findByKey(string $key): ?CoinTransaction
    {
        return CoinTransaction::query()->where('idempotency_key', $key)->first();
    }

    /**
     * Lock the wallet, honour the idempotency key, apply the change and write the ledger row —
     * all in one transaction (a savepoint when the caller already has one).
     *
     * @param  callable(Wallet): CoinTransaction  $apply
     */
    private function move(User $user, string $key, callable $apply): CoinTransaction
    {
        return DB::transaction(function () use ($user, $key, $apply) {
            $wallet = Wallet::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if (! $wallet) {
                Wallet::query()->insertOrIgnore(['user_id' => $user->getKey(), 'updated_at' => now()]);
                $wallet = Wallet::query()->whereKey($user->getKey())->lockForUpdate()->first();
            }

            if ($existing = $this->findByKey($key)) {
                return $existing;
            }

            $row = $apply($wallet);

            if (! $row->exists) {
                // A clawback with nothing to take: no wallet change, no ledger row.
                return $row;
            }

            $wallet->updated_at = now();
            $wallet->save();

            return $row;
        }, attempts: 3);
    }

    private function row(User $user, Wallet $wallet, string $type, int $amount, int $withdrawableDelta, ?Model $ref, string $key, ?string $note, array $meta, ?User $by = null): CoinTransaction
    {
        $row = new CoinTransaction([
            'user_id' => $user->getKey(),
            'type' => $type,
            'amount' => $amount,
            'withdrawable_delta' => $withdrawableDelta,
            'balance_after' => $wallet->balance,
            'withdrawable_after' => $wallet->withdrawable,
            'reference_type' => $ref ? class_basename($ref) : null,
            'reference_id' => $ref?->getKey(),
            'idempotency_key' => $key,
            'note' => $note ? mb_substr($note, 0, 160) : null,
            'meta' => $meta ?: null,
            'created_by' => $by?->getKey(),
            'created_at' => now(),
        ]);

        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            // Cannot happen under the wallet lock, but never double-write if it somehow does.
            return $this->findByKey($key);
        }

        return $row;
    }

    private function assertAmount(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('The amount must be a positive number of coins.');
        }
    }
}
