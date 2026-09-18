<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\AdminAuditLog;
use App\Models\Call;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserLogin;
use App\Models\UserReport;
use App\Services\AdminAuditService;
use App\Services\AdminContentService;
use App\Services\AdminInsightsService;
use App\Services\AdminService;
use App\Services\BackupService;
use App\Services\SessionService;
use App\Services\StorageUsageService;
use App\Services\TwoStepService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin panel: dashboard and accounts. Opening someone's chats is recorded in the
 * audit log (see Admin\ChatController).
 */
class AdminController extends Controller
{
    public function __construct(
        private readonly AdminService $admin,
        private readonly AdminAuditService $audit,
    ) {}

    public function dashboard(Request $request, AdminInsightsService $insights): View
    {
        $validated = $request->validate([
            'days' => ['nullable', Rule::in(array_map('strval', AdminInsightsService::RANGES))],
            'refresh' => ['nullable', 'in:1'],
        ]);
        $fresh = ($validated['refresh'] ?? null) === '1';

        return view('admin.dashboard', [
            'stats' => $this->admin->stats($fresh),
            'insights' => $insights->dashboard((int) ($validated['days'] ?? 30), $fresh),
            'health' => $insights->health(),
            'recentUsers' => User::query()->latest()->limit(6)->get(),
            'recentActivity' => AdminAuditLog::query()->with('admin')->latest('id')->limit(6)->get(),
        ]);
    }

    public function users(Request $request): View
    {
        $filters = $request->validate(AdminService::filterRules());

        return view('admin.users.index', [
            'users' => $this->admin->users($filters),
            'filters' => $filters,
            'prefixSearch' => ($filters['q'] ?? null) && ! ($filters['contains'] ?? null) && $this->admin->largeUserTable(),
        ]);
    }

    /** Tabs of a user's page; each loads only its own data. */
    public const USER_TABS = [
        'overview' => ['Overview', 'layout-dashboard'],
        'activity' => ['Activity', 'activity'],
        'chats' => ['Chats', 'message-circle'],
        'groups' => ['Groups & channels', 'users-round'],
        'devices' => ['Devices & sign-ins', 'smartphone'],
        'contacts' => ['Contacts & blocks', 'contact'],
        'calls' => ['Calls', 'phone'],
        'reports' => ['Reports', 'message-square-warning'],
        'money' => ['Money', 'coins'],
        'settings' => ['Settings & storage', 'sliders-horizontal'],
        'history' => ['Admin history', 'history'],
    ];

    public function showUser(Request $request, User $user, AdminContentService $content, AdminInsightsService $insights): View
    {
        $tab = array_key_exists((string) $request->query('tab'), self::USER_TABS) ? (string) $request->query('tab') : 'overview';
        $user->load('bannedBy');

        $data = [
            'user' => $user,
            'tab' => $tab,
            'tabs' => self::USER_TABS,
            'content' => $content,
            'twoStep' => app(TwoStepService::class)->enabled($user),
        ];

        $data += match ($tab) {
            'overview' => [
                'counts' => $insights->userCounts($user),
                'days' => $insights->perDay(Message::query()->where('sender_id', $user->getKey()), today()->subDays(29), 30),
                'sessions' => app(SessionService::class)->list($user, $request),
                'activity' => AdminAuditLog::query()->with('admin')->where('target_type', 'User')->where('target_id', $user->getKey())->latest('id')->limit(8)->get(),
                'lastLogin' => UserLogin::query()->where('user_id', $user->getKey())->where('event', UserLogin::EVENT_LOGIN)->latest('id')->first(),
            ],
            'activity' => ['activityData' => $insights->userActivity($user, 90)],
            'chats' => ['chats' => $this->userChats($request, $user)],
            'groups' => ['memberships' => $insights->memberships($user)],
            'devices' => $this->devicesTab($request, $user, $insights),
            'contacts' => [
                'contacts' => $user->contacts()->with('contactUser')->orderBy('name')->paginate(30)->withQueryString(),
                'blocked' => $user->blockedUsers()->orderBy('name')->limit(100)->get(),
                'blockers' => $user->blockers()->orderBy('name')->limit(100)->get(),
            ],
            'calls' => [
                'calls' => Call::query()->where(fn ($q) => $q->where('caller_id', $user->getKey())->orWhere('callee_id', $user->getKey()))
                    ->with(['caller', 'callee'])->latest('id')->paginate(30)->withQueryString(),
                'groupCalls' => DB::table('call_room_participants')->join('call_rooms', 'call_rooms.id', '=', 'call_room_participants.call_room_id')
                    ->where('call_room_participants.user_id', $user->getKey())
                    ->orderByDesc('call_room_participants.id')->limit(20)
                    ->get(['call_rooms.id', 'call_rooms.type', 'call_rooms.host_id', 'call_room_participants.status', 'call_room_participants.joined_at', 'call_room_participants.left_at', 'call_room_participants.created_at']),
            ],
            'reports' => [
                'reportsAgainst' => UserReport::query()->where('reported_user_id', $user->getKey())->with('reporter')->latest('id')->paginate(20, ['*'], 'against')->withQueryString(),
                'reportsMade' => UserReport::query()->where('reporter_id', $user->getKey())->with('reportedUser')->latest('id')->paginate(20, ['*'], 'made')->withQueryString(),
            ],
            'settings' => $this->settingsTab($user),
            'history' => ['logs' => AdminAuditLog::query()->with('admin')->where('target_type', 'User')->where('target_id', $user->getKey())->latest('id')->paginate(25)->withQueryString()],
        };

        return view('admin.users.show', $data);
    }

    private function userChats(Request $request, User $user)
    {
        $type = in_array($request->query('type'), ['direct', 'group', 'channel', 'broadcast'], true) ? $request->query('type') : null;

        return Conversation::query()->forUser($user->getKey())
            ->when($type, fn ($q) => $q->where('type', $type))
            ->with(['userOne', 'userTwo', 'community', 'creator'])
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString();
    }

    private function devicesTab(Request $request, User $user, AdminInsightsService $insights): array
    {
        $event = in_array($request->query('event'), [UserLogin::EVENT_LOGIN, UserLogin::EVENT_FAILED, UserLogin::EVENT_LOGOUT], true) ? $request->query('event') : null;

        return [
            'sessions' => app(SessionService::class)->list($user, $request),
            'phones' => $user->deviceTokens()->latest('last_used_at')->get(),
            'trusted' => $user->trustedDevices()->latest('last_used_at')->get(),
            'logins' => UserLogin::query()->where('user_id', $user->getKey())->when($event, fn ($q) => $q->where('event', $event))->latest('id')->paginate(30)->withQueryString(),
            'loginEvent' => $event,
            'networks' => UserLogin::query()->where('user_id', $user->getKey())->whereNotNull('ip_address')->distinct()->count('ip_address'),
            'shared' => $insights->sharedNetworks($user),
        ];
    }

    private function settingsTab(User $user): array
    {
        $chatSettings = ChatSetting::query()->where('user_id', $user->getKey())
            ->selectRaw('SUM(pinned_at IS NOT NULL) as pinned, SUM(archived_at IS NOT NULL) as archived, SUM(muted_until > NOW()) as muted, SUM(locked_at IS NOT NULL) as locked, SUM(wallpaper IS NOT NULL) as wallpapers, SUM(notification_tone IS NOT NULL) as tones')
            ->toBase()->first();

        return [
            'chatSettings' => collect((array) $chatSettings)->map(fn ($n) => (int) $n)->all(),
            'storage' => app(StorageUsageService::class)->summary($user),
            'backup' => app(BackupService::class)->latestFor($user),
            'chatLists' => $user->chatLists()->count(),
        ];
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): RedirectResponse
    {
        $status = $request->validated('status');
        $this->admin->setStatus($user, $status);

        $label = match ($status) {
            User::STATUS_ACTIVE => 'activated',
            User::STATUS_INACTIVE => 'deactivated',
            default => 'suspended',
        };
        $this->audit->record($request->user(), 'user.status', $user, ucfirst($label)." {$user->name}'s account");

        return back()->with('status', "{$user->name}'s account has been {$label}.");
    }

    public function destroyUser(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $name = $user->name;
        $this->audit->record($request->user(), 'user.deleted', $user, "Deleted the account of {$name} (@{$user->username})", ['email' => $user->email, 'phone' => $user->phone]);
        $this->admin->deleteUser($user);

        return redirect()->route('admin.users')->with('status', "{$name}'s account has been permanently deleted.");
    }
}
