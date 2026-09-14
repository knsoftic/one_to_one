<?php

namespace App\Services;

use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administrative operations. Deliberately exposes counts and account data
 * only — never private message content.
 */
class AdminService
{
    public function __construct(
        private readonly PresenceService $presence,
        private readonly AccountDeletionService $deletion,
    ) {}

    /**
     * @return array<string, int>
     */
    public function stats(): array
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

    public function users(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        return User::query()
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->search($term))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when(($filters['online'] ?? null) === '1', fn ($q) => $q->online())
            ->withCount(['sentMessages', 'blockedByRecords'])
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
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

    private function signOutEverywhere(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $this->presence->markOffline($user);
    }
}
