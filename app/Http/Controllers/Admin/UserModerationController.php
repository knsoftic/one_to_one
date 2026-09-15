<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Concerns\ValidatesProfileFields;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\AdminService;
use App\Services\BanService;
use App\Services\TwoStepService;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\NotIn;

/**
 * Admin panel: ban / unban, roles, editing a profile, signing someone out,
 * removing a profile photo and turning off two-step verification.
 */
class UserModerationController extends Controller
{
    use ValidatesProfileFields;

    public function __construct(
        private readonly AdminService $admin,
        private readonly BanService $bans,
        private readonly AdminAuditService $audit,
    ) {}

    public function ban(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $validated = $request->validateWithBag('ban', [
            'duration' => ['required', Rule::in(array_keys(BanService::DURATIONS))],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], ['reason.required' => 'Write the reason the person will see.']);

        $days = $validated['duration'] === 'permanent' ? null : (int) $validated['duration'];
        $this->bans->ban($user, $request->user(), $days, $validated['reason']);

        $length = $days ? BanService::DURATIONS[$validated['duration']] : 'permanently';
        $this->audit->record($request->user(), 'user.banned', $user, "Banned {$user->name} ({$length})", ['reason' => $validated['reason'], 'days' => $days]);

        return back()->with('status', $days ? "{$user->name} is banned for {$length}." : "{$user->name} is banned permanently.");
    }

    public function unban(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);
        abort_unless($user->isBanned(), 404);

        $this->bans->unban($user);
        $this->audit->record($request->user(), 'user.unbanned', $user, "Lifted the ban on {$user->name}");

        return back()->with('status', "{$user->name} can use the app again.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $this->prepareInput($request);
        $validated = Validator::make($request->all(), [
            'name' => $this->nameRules(),
            'username' => array_values(array_filter($this->usernameRules($user->getKey()), fn ($rule) => ! $rule instanceof NotIn)),
            'email' => $this->emailRules($user->getKey(), required: false),
            'phone' => $this->phoneRules($user->getKey()),
            'about' => ['nullable', 'string', 'max:139'],
        ], $this->profileMessages())->validateWithBag('edit');

        $before = $user->only(['name', 'username', 'email', 'phone', 'about']);
        $this->admin->updateProfile($user, $validated);
        $changed = array_keys(array_diff_assoc(array_map('strval', $user->only(array_keys($before))), array_map('strval', $before)));

        if ($changed) {
            $this->audit->record($request->user(), 'user.updated', $user, "Edited {$user->name}'s profile (".implode(', ', $changed).')', ['before' => array_intersect_key($before, array_flip($changed))]);
        }

        return back()->with('status', $changed ? "{$user->name}'s profile has been updated." : 'Nothing was changed.');
    }

    public function role(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('changeRole', $user);
        $role = $request->validate(['role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_ADMIN])]])['role'];

        $this->admin->setRole($user, $role);
        $this->audit->record($request->user(), 'user.role', $user, $role === User::ROLE_ADMIN ? "Made {$user->name} an administrator" : "Removed {$user->name}'s administrator role");

        return back()->with('status', $role === User::ROLE_ADMIN ? "{$user->name} is now an administrator." : "{$user->name} is no longer an administrator.");
    }

    public function logout(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $this->admin->logOutEverywhere($user);
        $this->audit->record($request->user(), 'user.logged_out', $user, "Signed {$user->name} out of every device");

        return back()->with('status', "{$user->name} has been signed out of every device.");
    }

    public function removePhoto(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manage', $user);
        abort_unless($user->profile_image, 404);

        $this->admin->removePhoto($user);
        $this->audit->record($request->user(), 'user.photo_removed', $user, "Removed {$user->name}'s profile photo");

        return back()->with('status', "{$user->name}'s profile photo has been removed.");
    }

    public function resetTwoStep(Request $request, User $user, TwoStepService $twoStep): RedirectResponse
    {
        Gate::authorize('manage', $user);
        abort_unless($twoStep->enabled($user), 404);

        $twoStep->disable($user);
        $this->audit->record($request->user(), 'user.two_step_reset', $user, "Turned off two-step verification for {$user->name}");

        return back()->with('status', "Two-step verification is off for {$user->name}.");
    }

    private function prepareInput(Request $request): void
    {
        $request->merge([
            'name' => preg_replace('/\s+/u', ' ', trim((string) $request->input('name'))),
            'username' => mb_strtolower(ltrim(trim((string) $request->input('username')), '@')),
            'email' => mb_strtolower(trim((string) $request->input('email'))) ?: null,
            'phone' => Phone::forAccount((string) $request->input('phone')),
        ]);
    }
}
