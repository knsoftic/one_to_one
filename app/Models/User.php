<?php

namespace App\Models;

use App\Support\Phone;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_SUSPENDED];

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
        'profile_image',
        'password',
        'theme',
        'notifications_enabled',
        'notification_sound',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function booted(): void
    {
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
            'last_seen' => 'datetime',
            'is_online' => 'boolean',
            'notifications_enabled' => 'boolean',
            'notification_sound' => 'boolean',
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
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        $like = '%'.addcslashes($term, '%_\\').'%';
        // National formats ("0345…") should match international storage ("+92345…").
        $digits = ltrim((string) preg_replace('/[^0-9]/', '', $term), '0');

        return $query->where(function (Builder $q) use ($like, $digits, $term) {
            $q->where('name', 'like', $like)
                ->orWhere('username', 'like', $like)
                ->orWhere('email', 'like', $like);

            if (strlen($digits) >= 3 && preg_match('/^[\d\s()+\-]+$/', $term)) {
                $q->orWhere('phone', 'like', '%'.$digits.'%');
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
