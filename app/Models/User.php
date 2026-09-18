<?php

namespace App\Models;

use App\Services\ReferralService;
use App\Support\Phone;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_USER = 'user';

    public const ROLE_ADMIN = 'admin';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_SUSPENDED = 'suspended';

    /** Banned from the admin panel, for a while or for good (see banned_until). */
    public const STATUS_BANNED = 'banned';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SUSPENDED, self::STATUS_BANNED];

    public const THEMES = ['light', 'dark', 'system'];

    /**
     * In-memory defaults (mirror the database column defaults).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => self::ROLE_USER,
        'status' => self::STATUS_ACTIVE,
        'theme' => 'system',
        'is_online' => false,
        'notifications_enabled' => true,
        'notification_sound' => true,
        'wallpaper_dim' => 0,
        'font_size' => 'medium',
        'locale' => 'en',
        'notification_tone' => 'default',
        'notification_vibrate' => 'default',
        'last_seen_privacy' => 'everyone',
        'online_privacy' => 'everyone',
        'photo_privacy' => 'everyone',
        'about_privacy' => 'everyone',
        'read_receipts' => true,
    ];

    /**
     * Role, status and presence columns are intentionally NOT mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'phone',
        'gender',
        'birth_date',
        'profile_image',
        'password',
        'theme',
        'notifications_enabled',
        'notification_sound',
        // Phase 8: font size, notification tone and vibration, auto-download (wallpaper has its own service).
        'font_size',
        'notification_tone',
        'notification_vibrate',
        'auto_download',
        // Phase 6: About and privacy.
        'about',
        'last_seen_privacy',
        'online_privacy',
        'photo_privacy',
        'about_privacy',
        'read_receipts',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'chat_lock_pin',
        'two_step_pin',
    ];

    protected static function booted(): void
    {
        // Y2 — a referral is rewarded exactly once, when the number is verified for the first
        // time (phone-OTP sign-in, change number, or an admin edit all pass through here).
        static::updated(function (User $user) {
            if ($user->wasChanged('phone_verified_at') && $user->phone_verified_at !== null && $user->getOriginal('phone_verified_at') === null) {
                app(ReferralService::class)->rewardIfEligible($user);
            }
        });

        // Keep the indexed phone suffix in sync for phone-book contact matching.
        static::saving(function (User $user) {
            if (array_key_exists('phone', $user->getAttributes())
                && ($user->isDirty('phone') || $user->getAttribute('phone_suffix') === null)) {
                $user->phone_suffix = Phone::suffix((string) $user->phone);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_seen' => 'datetime',
            'is_online' => 'boolean',
            'notifications_enabled' => 'boolean',
            'notification_sound' => 'boolean',
            'wallpaper_dim' => 'integer',
            'auto_download' => 'array',
            'read_receipts' => 'boolean',
            'two_step_enabled_at' => 'datetime',
            'banned_at' => 'datetime',
            'banned_until' => 'datetime',
            // Profile details (Y1) and the paid-feature columns (Y2).
            'birth_date' => 'date',
            'plan_until' => 'datetime',
            'verified_until' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /* -----------------------------------------------------------------
     |  Relationships
     | -----------------------------------------------------------------
     */

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    /** Conversations where this user is the lower-id participant. */
    public function conversationsAsUserOne(): HasMany
    {
        return $this->hasMany(Conversation::class, 'user_one_id');
    }

    /** Conversations where this user is the higher-id participant. */
    public function conversationsAsUserTwo(): HasMany
    {
        return $this->hasMany(Conversation::class, 'user_two_id');
    }

    /** Phone-book contacts of this user who are registered on the app. */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class, 'user_id');
    }

    /** The administrator who banned this account. */
    public function bannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'banned_by');
    }

    /** Browsers that passed two-step verification (P7). */
    public function trustedDevices(): HasMany
    {
        return $this->hasMany(TrustedDevice::class);
    }

    public function stickers(): HasMany
    {
        return $this->hasMany(Sticker::class);
    }

    public function chatLists(): HasMany
    {
        return $this->hasMany(ChatList::class);
    }

    /** X8 — business profile (null for a normal account). */
    public function businessProfile(): HasOne
    {
        return $this->hasOne(BusinessProfile::class);
    }

    public function quickReplies(): HasMany
    {
        return $this->hasMany(QuickReply::class);
    }

    /** Y1 — what we use to choose this person's ads. */
    public function adProfile(): HasOne
    {
        return $this->hasOne(AdProfile::class);
    }

    /* Y2 — paid features. The columns behind these are written only by the services (forceFill). */

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(AdCampaign::class, 'owner_id');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /** Age in whole years from the date of birth in the profile, when it is set. */
    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    /** X3 — browsers that receive push notifications. */
    public function webPushSubscriptions(): HasMany
    {
        return $this->hasMany(WebPushSubscription::class);
    }

    /** Mobile app installs that receive push notifications for this user. */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** Block records created by this user. */
    public function blocks(): HasMany
    {
        return $this->hasMany(BlockedUser::class, 'user_id');
    }

    /** Block records where this user has been blocked. */
    public function blockedByRecords(): HasMany
    {
        return $this->hasMany(BlockedUser::class, 'blocked_user_id');
    }

    /** Users this user has blocked. */
    public function blockedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'blocked_users', 'user_id', 'blocked_user_id')
            ->withPivot('created_at');
    }

    /** Users who have blocked this user. */
    public function blockers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'blocked_users', 'blocked_user_id', 'user_id')
            ->withPivot('created_at');
    }

    /**
     * All conversations this user participates in.
     */
    public function conversations(): Builder
    {
        return Conversation::query()->forUser($this);
    }

    /* -----------------------------------------------------------------
     |  Accessors
     | -----------------------------------------------------------------
     */

    protected function avatarUrl(): Attribute
    {
        return Attribute::get(fn () => $this->profile_image
            ? asset('storage/'.$this->profile_image)
            : null);
    }

    protected function initials(): Attribute
    {
        return Attribute::get(function () {
            $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
            $letters = collect($words)->filter()->take(2)->map(fn ($w) => Str::upper(Str::substr($w, 0, 1)));

            return $letters->implode('') ?: '?';
        });
    }

    /**
     * Stable avatar background hue derived from the user id.
     */
    protected function avatarHue(): Attribute
    {
        return Attribute::get(fn () => ((int) $this->id * 47) % 360);
    }

    /* -----------------------------------------------------------------
     |  Scopes
     | -----------------------------------------------------------------
     */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('is_online', true)
            ->where('last_seen', '>=', now()->subSeconds(config('chat.online_threshold_seconds')));
    }

    /**
     * Search by name, username, email or phone number.
     */
    public function scopeSearch(Builder $query, string $term, bool $partialContact = false): Builder
    {
        $term = trim($term);
        $like = '%'.addcslashes($term, '%_\\').'%';
        // National formats ("0345…") should match international storage ("+92345…").
        $digits = ltrim((string) preg_replace('/[^0-9]/', '', $term), '0');
        $isNumber = preg_match('/^[\d\s()+\-]+$/', $term) === 1;

        return $query->where(function (Builder $q) use ($like, $digits, $term, $isNumber, $partialContact) {
            $q->where('name', 'like', $like)->orWhere('username', 'like', $like);

            // People search the app with a full email or mobile number, so nobody can
            // work out someone's email or number letter by letter (admins may search parts).
            if ($partialContact) {
                $q->orWhere('email', 'like', $like);
                if (strlen($digits) >= 3 && $isNumber) {
                    $q->orWhere('phone', 'like', '%'.$digits.'%');
                }
            } else {
                $q->orWhere('email', mb_strtolower($term));
                if ($isNumber && ($suffix = Phone::suffix($term)) !== null) {
                    $q->orWhere('phone_suffix', $suffix);
                }
            }
        });
    }

    /* -----------------------------------------------------------------
     |  Helpers
     | -----------------------------------------------------------------
     */

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isBanned(): bool
    {
        return $this->status === self::STATUS_BANNED;
    }

    /** A ban with an end date whose time is up (lifted on the next visit or by the scheduler). */
    public function banHasEnded(): bool
    {
        return $this->isBanned() && $this->banned_until !== null && $this->banned_until->isPast();
    }

    /**
     * Online = flagged online AND recent activity (protects against stale flags
     * when a browser closes without notifying the server).
     */
    public function isOnlineNow(): bool
    {
        return $this->is_online
            && $this->last_seen !== null
            && $this->last_seen->gte(now()->subSeconds(config('chat.online_threshold_seconds')));
    }

    public function hasBlocked(User|int $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return BlockedUser::query()
            ->where('user_id', $this->getKey())
            ->where('blocked_user_id', $id)
            ->exists();
    }

    public function isBlockedBy(User|int $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return BlockedUser::query()
            ->where('user_id', $id)
            ->where('blocked_user_id', $this->getKey())
            ->exists();
    }

    /**
     * True when either user has blocked the other.
     */
    public function hasBlockWith(User|int $user): bool
    {
        $id = $user instanceof User ? $user->getKey() : $user;

        return BlockedUser::query()
            ->where(fn ($q) => $q->where('user_id', $this->getKey())->where('blocked_user_id', $id))
            ->orWhere(fn ($q) => $q->where('user_id', $id)->where('blocked_user_id', $this->getKey()))
            ->exists();
    }

    /**
     * Broadcast channel used for private, per-user realtime events.
     */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'App.Models.User.'.$this->getKey();
    }
}
