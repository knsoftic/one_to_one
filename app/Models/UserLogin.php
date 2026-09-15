<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-in, failed sign-in or sign-out of an account (admin panel → user → Devices).
 */
class UserLogin extends Model
{
    public const UPDATED_AT = null;

    public const EVENT_LOGIN = 'login';

    public const EVENT_FAILED = 'failed';

    public const EVENT_LOGOUT = 'logout';

    public const METHODS = [
        'password' => 'Password',
        'phone_code' => 'SMS code',
        'two_step' => 'Two-step PIN',
        'qr' => 'QR code',
        'register' => 'New account',
        'remembered' => 'Remembered device',
    ];

    /** Sign-in history is kept this long. */
    public const KEEP_DAYS = 180;

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? ucfirst((string) $this->method);
    }
}
