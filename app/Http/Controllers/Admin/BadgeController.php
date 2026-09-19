<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BadgeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin → person → Money (Y2): give or take away the verified badge.
 */
class BadgeController extends Controller
{
    public function __construct(private readonly BadgeService $badges) {}

    /** `days` empty = lifetime. */
    public function grant(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'between:1,3650']]);
        $days = filled($data['days'] ?? null) ? (int) $data['days'] : null;

        $this->badges->grant($request->user(), $user, $days);

        return redirect()->route('admin.users.show', ['user' => $user, 'tab' => 'money'])
            ->with('status', $days === null ? "{$user->name} is verified for good." : "{$user->name} is verified for {$days} days.");
    }

    public function remove(Request $request, User $user): RedirectResponse
    {
        $this->badges->remove($request->user(), $user);

        return redirect()->route('admin.users.show', ['user' => $user, 'tab' => 'money'])
            ->with('status', "Removed the verified badge from {$user->name}.");
    }
}
