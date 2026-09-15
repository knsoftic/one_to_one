<?php

namespace App\Services;

use App\Models\BlockedUser;
use App\Models\Call;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Status;
use App\Models\User;
use App\Models\UserReport;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Administrative operations on accounts: statistics, status, roles, profile edits,
 * signing out and deleting. Reading chats lives in AdminContentService.
 */
class AdminService
{
    public function __construct(
        private readonly PresenceService $presence,
        private readonly AccountDeletionService $deletion,
    ) {}

    /** Sort choices of the users list. */
    public const USER_SORTS = ['newest' => 'Newest first', 'oldest' => 'Oldest first', 'last_seen' => 'Recently active', 'name' => 'Name A–Z'];

    public const PER_PAGE = [15, 50, 100];

    /**
     * Filters of the users list (also used by CSV export).
     *
     * @return array<string, list<mixed>>
     */
    public static function filterRules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'contains' => ['nullable', 'in:1'],
            'status' => ['nullable', Rule::in(User::STATUSES)],
            'role' => ['nullable', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
            'online' => ['nullable', 'in:1'],
            'joined' => ['nullable', Rule::in(['1', '7', '30', '90'])],
            'inactive' => ['nullable', Rule::in(['30', '90', '180'])],
            'app' => ['nullable', 'in:1'],
            'two_step' => ['nullable', 'in:1'],
            'sort' => ['nullable', Rule::in(array_keys(self::USER_SORTS))],
            'per_page' => ['nullable', Rule::in(array_map('strval', self::PER_PAGE))],
        ];
    }

    /** Above this many accounts, search matches the start of names (fast) unless "contains" is ticked. */
    public const LARGE_USER_TABLE = 50000;

    /**
     * Headline numbers (cached for a minute so the dashboard stays quick with many rows).
     *
     * @return array<string, int>
     */
    public function stats(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('admin:stats');
        }

        return Cache::remember('admin:stats', 60, fn () => $this->freshStats());
    }

    /**
     * @return array<string, int>
     */
    private function freshStats(): array
    {
        return [
            'total_users' => User::query()->count(),
            'online_users' => User::query()->online()->count(),
            'total_conversations' => Conversation::query()->count(),
            'total_messages' => Message::query()->count(),
            'new_users' => User::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'blocked_users' => BlockedUser::query()->distinct()->count('blocked_user_id'),
            'block_relations' => BlockedUser::query()->count(),
            'suspended_users' => User::query()->where('status', User::STATUS_SUSPENDED)->count(),
            'inactive_users' => User::query()->where('status', User::STATUS_INACTIVE)->count(),
            'messages_today' => Message::query()->where('created_at', '>=', today())->count(),
            'banned_users' => User::query()->where('status', User::STATUS_BANNED)->count(),
            'groups' => Conversation::query()->where('type', Conversation::TYPE_GROUP)->where('is_announcement', false)->whereNull('ended_at')->count(),
            'channels' => Conversation::query()->where('type', Conversation::TYPE_CHANNEL)->count(),
            'communities' => Community::query()->count(),
            'statuses' => Status::query()->active()->count(),
            'calls_today' => Call::query()->where('created_at', '>=', today())->count(),
            'open_reports' => UserReport::query()->open()->count(),
        ];
    }

    /**
     * Messages per day for the last N days (volume only).
     *
     * @return list<array{date: string, label: string, count: int}>
     */
    public function messageVolume(int $days = 7): array
    {
        $start = today()->subDays($days - 1);

        $counts = Message::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(0, $days - 1))->map(function (int $offset) use ($start, $counts) {
            $date = $start->copy()->addDays($offset);

            return [
                'date' => $date->toDateString(),
                'label' => $date->isToday() ? 'Today' : $date->format('D'),
                'count' => (int) ($counts[$date->toDateString()] ?? 0),
            ];
        })->all();
    }

    public function users(array $filters, ?int $perPage = null): LengthAwarePaginator
    {
        $size = (int) ($filters['per_page'] ?? $perPage ?? self::PER_PAGE[0]);

        return $this->userQuery($filters)
            ->withCount(['sentMessages', 'blockedByRecords'])
            ->paginate(in_array($size, self::PER_PAGE, true) ? $size : self::PER_PAGE[0])
            ->withQueryString();
    }

    /**
     * The filtered, sorted users query (list, CSV export and bulk "select all").
     */
    public function userQuery(array $filters): Builder
    {
        $sort = $filters['sort'] ?? 'newest';

        return User::query()
            ->when($filters['q'] ?? null, fn ($q, $term) => $this->searchUsers($q, $term, (bool) ($filters['contains'] ?? false)))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when(($filters['online'] ?? null) === '1', fn ($q) => $q->online())
            ->when($filters['joined'] ?? null, fn ($q, $days) => $q->where('created_at', '>=', now()->subDays((int) $days)))
            ->when(($filters['app'] ?? null) === '1', fn ($q) => $q->whereHas('deviceTokens'))
            ->when(($filters['two_step'] ?? null) === '1', fn ($q) => $q->whereNotNull('two_step_pin'))
            ->when($filters['inactive'] ?? null, fn ($q, $days) => $q->where(fn ($w) => $w->whereNull('last_seen')->orWhere('last_seen', '<', now()->subDays((int) $days))))
            ->when($sort === 'oldest', fn ($q) => $q->orderBy('created_at')->orderBy('id'))
            ->when($sort === 'last_seen', fn ($q) => $q->orderByRaw('last_seen IS NULL')->orderByDesc('last_seen')->orderByDesc('id'))
            ->when($sort === 'name', fn ($q) => $q->orderBy('name')->orderBy('id'))
            ->when(! in_array($sort, ['oldest', 'last_seen', 'name'], true), fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'));
    }

    /** True when searches should match the start of names only (many accounts). */
    public function largeUserTable(): bool
    {
        return Cache::remember('admin:users:large', 600, function () {
            try {
                $estimate = (int) (DB::selectOne('SELECT table_rows AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [(new User)->getTable()])->n ?? 0);
            } catch (\Throwable) {
                $estimate = 0;
            }

            return $estimate > self::LARGE_USER_TABLE;
        });
    }

    private function searchUsers(Builder $query, string $term, bool $contains): Builder
    {
        if ($contains || ! $this->largeUserTable()) {
            return $query->search($term, partialContact: true);
        }

        // Index friendly: names, usernames and emails starting with the text, the exact number or id.
        $term = trim($term);
        $prefix = addcslashes($term, '%_\\').'%';
        $isNumber = preg_match('/^[\d\s()+\-]+$/', $term) === 1;

        return $query->where(function (Builder $q) use ($prefix, $term, $isNumber) {
            $q->where('name', 'like', $prefix)->orWhere('username', 'like', $prefix)->orWhere('email', 'like', mb_strtolower($prefix));
            if ($isNumber && ($suffix = Phone::suffix($term)) !== null) {
                $q->orWhere('phone_suffix', $suffix);
            }
            if (ctype_digit($term)) {
                $q->orWhere('id', (int) $term);
            }
        });
    }

    /**
     * @return array<string, int|Carbon|null>
     */
    public function userSummary(User $user): array
    {
        return [
            'messages_sent' => $user->sentMessages()->count(),
            'messages_received' => $user->receivedMessages()->count(),
            'conversations' => $user->conversations()->count(),
            'blocked_by_user' => $user->blocks()->count(),
            'blocked_by_others' => $user->blockedByRecords()->count(),
        ];
    }

    /**
     * Activate, deactivate or suspend an account. Non-active users are signed
     * out everywhere immediately.
     */
    public function setStatus(User $user, string $status): User
    {
        $user->forceFill(['status' => $status])->save();

        if ($status !== User::STATUS_ACTIVE) {
            $this->signOutEverywhere($user);
        }

        return $user;
    }

    /**
     * Permanently delete a user: they leave their groups and communities, and their
     * chats, messages, channels and files are removed (A3).
     */
    public function deleteUser(User $user): void
    {
        $this->deletion->delete($user);
    }

    /**
     * Edit someone's profile details from the admin panel.
     *
     * @param  array{name: string, username: string, email: string, phone: string, about?: ?string}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->fill(['name' => $data['name'], 'username' => $data['username'], 'email' => $data['email']]);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        if ((string) $user->phone !== $data['phone']) {
            $user->forceFill(['phone' => $data['phone'], 'phone_verified_at' => null]);
        }
        $about = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['about'] ?? '')));
        $user->about = $about === '' ? null : mb_substr($about, 0, 139);
        $user->save();

        return $user;
    }

    public function setRole(User $user, string $role): User
    {
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    /** Sign someone out of every browser and phone. */
    public function logOutEverywhere(User $user): void
    {
        $this->signOutEverywhere($user);
        $user->deviceTokens()->delete();
        app(WebPushService::class)->forgetUser($user);
    }

    public function removePhoto(User $user): void
    {
        if ($user->profile_image) {
            app(ImageService::class)->deleteAvatar($user->profile_image);
            $user->forceFill(['profile_image' => null])->save();
        }
    }

    private function signOutEverywhere(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $this->presence->markOffline($user);
    }
}
