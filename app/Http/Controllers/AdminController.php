<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\AdminAuditLog;
use App\Models\Conversation;
use App\Models\User;
use App\Models\UserReport;
use App\Services\AdminAuditService;
use App\Services\AdminContentService;
use App\Services\AdminService;
use App\Services\SessionService;
use App\Services\TwoStepService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'stats' => $this->admin->stats(),
            'volume' => $this->admin->messageVolume(14),
            'recentUsers' => User::query()->latest()->limit(6)->get(),
            'recentActivity' => AdminAuditLog::query()->with('admin')->latest('id')->limit(6)->get(),
        ]);
    }

    public function users(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(User::STATUSES)],
            'role' => ['nullable', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])],
            'online' => ['nullable', 'in:1'],
        ]);

        return view('admin.users.index', [
            'users' => $this->admin->users($filters),
            'filters' => $filters,
        ]);
    }

    public function showUser(Request $request, User $user, AdminContentService $content): View
    {
        $user->load('bannedBy');
        $chats = Conversation::query()->forUser($user->getKey())
            ->with(['userOne', 'userTwo', 'community', 'creator'])
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->limit(30)
            ->get();

        return view('admin.users.show', [
            'user' => $user,
            'summary' => $this->admin->userSummary($user),
            'counts' => $content->userCounts($user),
            'chats' => $chats,
            'content' => $content,
            'sessions' => app(SessionService::class)->list($user, $request),
            'twoStep' => app(TwoStepService::class)->enabled($user),
            'reportsAbout' => UserReport::query()->where('reported_user_id', $user->getKey())->count(),
            'activity' => AdminAuditLog::query()->with('admin')->where('target_type', 'User')->where('target_id', $user->getKey())->latest('id')->limit(8)->get(),
        ]);
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
