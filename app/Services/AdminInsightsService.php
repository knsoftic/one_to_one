<?php

namespace App\Services;

use App\Console\Commands\ChatDoctor;
use App\Models\Call;
use App\Models\ChatBackup;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\Status;
use App\Models\User;
use App\Models\UserLogin;
use App\Models\UserReport;
use App\Models\WebPushSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin panel analytics that stay quick with many users and messages: every heavy number
 * is grouped in the database (on indexed columns) and cached for a few minutes.
 */
class AdminInsightsService
{
    public const RANGES = [7, 30, 90];

    private const CACHE_SECONDS = 300;

    public function __construct(private readonly AdminContentService $content) {}

    /* ------------------------------------------------------------------ */
    /* Dashboard */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $days, bool $fresh = false): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : 30;
        $key = "admin:insights:{$days}";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_SECONDS, function () use ($days) {
            $start = today()->subDays($days - 1);

            return [
                'days' => $days,
                'generated_at' => now()->toIso8601String(),
                'series' => [
                    'messages' => $this->perDay(Message::query(), $start, $days),
                    'users' => $this->perDay(User::query(), $start, $days),
                    'active' => $this->perDay(Message::query(), $start, $days, 'COUNT(DISTINCT sender_id)'),
                    'calls' => $this->perDay(Call::query(), $start, $days),
                ],
                'types' => Message::query()->where('created_at', '>=', $start)
                    ->where('message_type', '!=', Message::TYPE_SYSTEM)
                    ->selectRaw('message_type, COUNT(*) as total')->groupBy('message_type')
                    ->orderByDesc('total')->pluck('total', 'message_type')->map(fn ($n) => (int) $n)->all(),
                'top_users' => $this->topSenders($start),
                'storage' => [
                    'messages' => (int) Message::query()->whereNotNull('attachment')->sum('attachment_size'),
                    'statuses' => (int) Status::query()->whereNotNull('attachment')->sum('attachment_size'),
                    'backups' => (int) ChatBackup::query()->whereNotNull('path')->sum('size'),
                ],
                'logins' => [
                    'today' => UserLogin::query()->where('event', UserLogin::EVENT_LOGIN)->where('created_at', '>=', today())->count(),
                    'failed_today' => UserLogin::query()->where('event', UserLogin::EVENT_FAILED)->where('created_at', '>=', today())->count(),
                ],
                'devices' => [
                    'android' => DeviceToken::query()->where('last_used_at', '>=', now()->subDays(30))->distinct()->count('user_id'),
                    'push' => DeviceToken::query()->whereNotNull('fcm_token')->distinct()->count('user_id'),
                    'web' => DB::table('sessions')->whereNotNull('user_id')->where('last_activity', '>=', now()->subDays(1)->getTimestamp())->distinct()->count('user_id'),
                ],
                'active_30d' => User::query()->where('last_seen', '>=', now()->subDays(30))->count(),
            ];
        });
    }

    /**
     * Queue, scheduler, disk and database health.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $health = Cache::remember('admin:health', 60, function () {
            $lastRun = Cache::get(ChatDoctor::SCHEDULER_HEARTBEAT_KEY);
            $storage = storage_path();

            return [
                'scheduler' => $lastRun ? Carbon::parse($lastRun) : null,
                'queue_waiting' => $this->safeCount('jobs'),
                'queue_failed' => $this->safeCount('failed_jobs'),
                'disk_free' => @disk_free_space($storage) ?: null,
                'disk_total' => @disk_total_space($storage) ?: null,
                'database_bytes' => $this->databaseSize(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'zip' => class_exists(\ZipArchive::class),
                'push' => app(PushService::class)->enabled(),
                'web_push' => WebPushSubscription::query()->count(),
                'realtime' => (string) config('broadcasting.default') === 'reverb',
                'queue_driver' => (string) config('queue.default'),
            ];
        });

        // Not cached with the rest: a check made in App settings shows up straight away.
        $turn = app(TurnServerService::class)->status();

        return $health + ['turn' => ['configured' => $turn['configured'], 'check' => $turn['check']]];
    }

    /* ------------------------------------------------------------------ */
    /* One person */
    /* ------------------------------------------------------------------ */

    /**
     * Everything counted about one account.
     *
     * @return array<string, int>
     */
    public function userCounts(User $user): array
    {
        $id = (int) $user->getKey();
        $memberships = $this->content->userCounts($user);

        $calls = Call::query()
            ->where(fn ($q) => $q->where('caller_id', $id)->orWhere('callee_id', $id))
            ->selectRaw('SUM(caller_id = ?) as made', [$id])
            ->selectRaw('SUM(callee_id = ?) as received', [$id])
            ->selectRaw("SUM(callee_id = ? AND end_reason IN ('missed','cancelled','busy')) as missed", [$id])
            ->selectRaw('COALESCE(SUM(duration), 0) as seconds')
            ->toBase()->first();

        $media = Message::query()->where('sender_id', $id)->whereNotNull('attachment')
            ->selectRaw('COUNT(*) as files, COALESCE(SUM(attachment_size), 0) as bytes')->toBase()->first();

        return $memberships + [
            'messages_received' => Message::query()->where('receiver_id', $id)->where('sender_id', '!=', $id)->count(),
            'messages_30d' => Message::query()->where('sender_id', $id)->where('created_at', '>=', now()->subDays(30))->count(),
            'media_files' => (int) ($media->files ?? 0),
            'media_bytes' => (int) ($media->bytes ?? 0),
            'calls_made' => (int) ($calls->made ?? 0),
            'calls_received' => (int) ($calls->received ?? 0),
            'calls_missed' => (int) ($calls->missed ?? 0),
            'call_seconds' => (int) ($calls->seconds ?? 0),
            'group_calls' => (int) DB::table('call_room_participants')->where('user_id', $id)->whereNotNull('joined_at')->count(),
            'groups_created' => Conversation::query()->where('type', Conversation::TYPE_GROUP)->where('is_announcement', false)->where('created_by', $id)->count(),
            'broadcast_lists' => Conversation::query()->where('type', Conversation::TYPE_BROADCAST)->where('created_by', $id)->count(),
            'contacts' => $user->contacts()->count(),
            'blocked' => $user->blocks()->count(),
            'blocked_by' => $user->blockedByRecords()->count(),
            'reports_made' => UserReport::query()->where('reporter_id', $id)->count(),
            'reports_against' => UserReport::query()->where('reported_user_id', $id)->count(),
            'statuses' => Status::query()->where('user_id', $id)->count(),
            'stickers' => $user->stickers()->count(),
            'starred' => DB::table('starred_messages')->where('user_id', $id)->count(),
            'devices' => $user->deviceTokens()->count(),
            'logins' => UserLogin::query()->where('user_id', $id)->where('event', UserLogin::EVENT_LOGIN)->count(),
            'failed_logins_7d' => UserLogin::query()->where('user_id', $id)->where('event', UserLogin::EVENT_FAILED)->where('created_at', '>=', now()->subDays(7))->count(),
        ];
    }

    /**
     * Activity charts of one account.
     *
     * @return array{days: list<array{date: string, count: int}>, hours: list<int>, types: array<string, array{count: int, bytes: int}>, top_chats: list<array<string, mixed>>}
     */
    public function userActivity(User $user, int $days = 90): array
    {
        $id = (int) $user->getKey();
        $start = today()->subDays($days - 1);

        $hours = array_fill(0, 24, 0);
        Message::query()->where('sender_id', $id)->where('created_at', '>=', now()->subDays(90))
            ->selectRaw('HOUR(created_at) as hour, COUNT(*) as total')->groupBy('hour')->toBase()->get()
            ->each(function ($row) use (&$hours) {
                $hours[(int) $row->hour] = (int) $row->total;
            });

        $types = Message::query()->where('sender_id', $id)
            ->selectRaw('message_type, COUNT(*) as total, COALESCE(SUM(attachment_size), 0) as bytes')
            ->groupBy('message_type')->orderByDesc('total')->toBase()->get()
            ->mapWithKeys(fn ($row) => [$row->message_type => ['count' => (int) $row->total, 'bytes' => (int) $row->bytes]])
            ->all();

        $top = Message::query()->where('sender_id', $id)
            ->selectRaw('conversation_id, COUNT(*) as total, MAX(created_at) as last_at')
            ->groupBy('conversation_id')->orderByDesc('total')->limit(10)->toBase()->get();
        $chats = Conversation::query()->whereKey($top->pluck('conversation_id'))->with(['userOne', 'userTwo', 'community', 'creator'])->get()->keyBy('id');

        return [
            'days' => $this->perDay(Message::query()->where('sender_id', $id), $start, $days),
            'hours' => $hours,
            'types' => $types,
            'top_chats' => $top->filter(fn ($row) => $chats->has($row->conversation_id))->map(function ($row) use ($chats, $id) {
                $chat = $chats[$row->conversation_id];
                $other = $chat->type === Conversation::TYPE_DIRECT ? ((int) $chat->user_one_id === $id ? $chat->userTwo : $chat->userOne) : null;

                return [
                    'chat' => $chat,
                    'other' => $other,
                    'title' => $other?->name ?? $this->content->title($chat),
                    'type' => $this->content->typeLabel($chat),
                    'messages' => (int) $row->total,
                    'last_at' => Carbon::parse($row->last_at),
                ];
            })->values()->all(),
        ];
    }

    /** People signed in from the same networks (possible second accounts). */
    public function sharedNetworks(User $user, int $limit = 10): Collection
    {
        $ips = UserLogin::query()->where('user_id', $user->getKey())->where('event', UserLogin::EVENT_LOGIN)
            ->whereNotNull('ip_address')->where('created_at', '>=', now()->subDays(UserLogin::KEEP_DAYS))
            ->distinct()->limit(50)->pluck('ip_address');

        if ($ips->isEmpty()) {
            return collect();
        }

        $rows = UserLogin::query()->whereIn('ip_address', $ips)->where('user_id', '!=', $user->getKey())
            ->where('event', UserLogin::EVENT_LOGIN)
            ->selectRaw('user_id, COUNT(DISTINCT ip_address) as networks, MAX(created_at) as last_at')
            ->groupBy('user_id')->orderByDesc('networks')->limit($limit)->toBase()->get();
        $users = User::query()->whereKey($rows->pluck('user_id'))->get()->keyBy('id');

        return $rows->filter(fn ($row) => $users->has($row->user_id))
            ->map(fn ($row) => ['user' => $users[$row->user_id], 'networks' => (int) $row->networks, 'last_at' => Carbon::parse($row->last_at)])
            ->values();
    }

    /** Memberships in groups, communities and channels with the role. */
    public function memberships(User $user, int $perPage = 25)
    {
        return ConversationMember::query()
            ->where('user_id', $user->getKey())
            ->with(['conversation' => fn ($q) => $q->with(['community', 'creator', 'userOne', 'userTwo'])->withCount(['members as active_members_count' => fn ($m) => $m->whereNull('left_at')])])
            ->whereHas('conversation')
            ->orderByRaw('left_at IS NOT NULL')
            ->orderByDesc('joined_at')
            ->paginate($perPage, ['*'], 'page')
            ->withQueryString();
    }

    /* ------------------------------------------------------------------ */

    /**
     * Rows per day from a query on created_at (index friendly: one grouped scan of the range).
     *
     * @return list<array{date: string, count: int}>
     */
    public function perDay($query, Carbon $start, int $days, string $aggregate = 'COUNT(*)'): array
    {
        $table = $query->getModel()->getTable();
        $counts = $query->where("{$table}.created_at", '>=', $start)
            ->selectRaw("DATE({$table}.created_at) as day, {$aggregate} as total")
            ->groupBy('day')
            ->toBase()
            ->pluck('total', 'day');

        return collect(range(0, $days - 1))->map(function (int $offset) use ($start, $counts) {
            $date = $start->copy()->addDays($offset)->toDateString();

            return ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        })->all();
    }

    /** @return list<array{user: User, messages: int}> */
    private function topSenders(Carbon $start): array
    {
        $rows = Message::query()->where('created_at', '>=', $start)->where('message_type', '!=', Message::TYPE_SYSTEM)
            ->selectRaw('sender_id, COUNT(*) as total')->groupBy('sender_id')->orderByDesc('total')->limit(10)->toBase()->get();
        $users = User::query()->whereKey($rows->pluck('sender_id'))->get()->keyBy('id');

        return $rows->filter(fn ($row) => $users->has($row->sender_id))
            ->map(fn ($row) => ['user' => $users[$row->sender_id], 'messages' => (int) $row->total])
            ->values()->all();
    }

    private function safeCount(string $table): ?int
    {
        try {
            return Schema::hasTable($table) ? DB::table($table)->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function databaseSize(): ?int
    {
        try {
            if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                return null;
            }

            return (int) DB::selectOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS size FROM information_schema.tables WHERE table_schema = DATABASE()')->size;
        } catch (Throwable) {
            return null;
        }
    }
}
