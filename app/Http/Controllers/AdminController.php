<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\User;
use App\Services\AdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin panel: statistics and account management.
 * Private conversations are intentionally not viewable here.
 */
class AdminController extends Controller
{
    public function __construct(private readonly AdminService $admin) {}

    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'stats' => $this->admin->stats(),
            'volume' => $this->admin->messageVolume(),
            'recentUsers' => User::query()->latest()->limit(6)->get(),
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

    public function showUser(User $user): View
    {
        return view('admin.users.show', [
            'user' => $user,
            'summary' => $this->admin->userSummary($user),
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

        return back()->with('status', "{$user->name}'s account has been {$label}.");
    }

    public function destroyUser(User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $name = $user->name;
        $this->admin->deleteUser($user);

        return redirect()->route('admin.users')->with('status', "{$name}'s account has been permanently deleted.");
    }
}
