<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Services\AccountExportService;
use App\Services\AdminAuditService;
use App\Services\AdminService;
use App\Services\BanService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin panel at scale: CSV export of the users list, bulk actions on many accounts,
 * one person's full data as JSON, and ending one browser session.
 */
class UserDataController extends Controller
{
    /** Bulk actions work on at most this many selected accounts. */
    public const MAX_BULK = 100;

    public const BULK_ACTIONS = ['logout', 'suspend', 'activate', 'ban', 'unban', 'delete'];

    public function __construct(
        private readonly AdminService $admin,
        private readonly AdminAuditService $audit,
    ) {}

    /** The users list with the same filters, as a CSV file (streamed, any size). */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate(AdminService::filterRules());
        $this->audit->record($request->user(), 'users.exported', null, 'Exported the users list'.(array_filter($filters) ? ' (filtered)' : ''), ['filters' => array_filter($filters)]);

        $query = $this->admin->userQuery($filters)->reorder();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['ID', 'Name', 'Username', 'Email', 'Mobile', 'Status', 'Role', 'Joined', 'Last seen', 'Messages sent', 'Android app', 'Two-step', 'Email verified', 'Mobile verified']);

            $query->select(['id', 'name', 'username', 'email', 'phone', 'status', 'role', 'created_at', 'last_seen', 'two_step_pin', 'email_verified_at', 'phone_verified_at'])
                ->chunkById(1000, function (Collection $users) use ($out) {
                    $ids = $users->modelKeys();
                    $messages = Message::query()->whereIn('sender_id', $ids)->selectRaw('sender_id, COUNT(*) as total')->groupBy('sender_id')->pluck('total', 'sender_id');
                    $apps = DB::table('device_tokens')->whereIn('user_id', $ids)->distinct()->pluck('user_id')->flip();

                    foreach ($users as $user) {
                        fputcsv($out, array_map([$this, 'cell'], [
                            $user->id,
                            $user->name,
                            $user->username,
                            $user->email,
                            $user->phone,
                            $user->status,
                            $user->role,
                            $user->created_at?->toDateTimeString(),
                            $user->last_seen?->toDateTimeString(),
                            (int) ($messages[$user->id] ?? 0),
                            isset($apps[$user->id]) ? 'yes' : 'no',
                            $user->two_step_pin ? 'on' : 'off',
                            $user->email_verified_at ? 'yes' : 'no',
                            $user->phone_verified_at ? 'yes' : 'no',
                        ]));
                    }
                    flush();
                });

            fclose($out);
        }, 'users-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function bulk(Request $request, BanService $bans): RedirectResponse
    {
        $validated = $request->validateWithBag('bulk', [
            'action' => ['required', Rule::in(self::BULK_ACTIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
            'ids.*' => ['integer', 'min:1'],
            'duration' => ['required_if:action,ban', 'nullable', Rule::in(array_keys(BanService::DURATIONS))],
            'reason' => ['required_if:action,ban', 'nullable', 'string', 'min:3', 'max:500'],
        ], [
            'ids.required' => 'Select at least one account.',
            'reason.required_if' => 'Write the reason the people will see.',
        ]);

        $admin = $request->user();
        $done = 0;
        $skipped = 0;

        User::query()->whereKey(array_unique($validated['ids']))->get()->each(function (User $user) use ($admin, $validated, $bans, &$done, &$skipped) {
            // Your own account and administrators are never changed in bulk.
            if (Gate::forUser($admin)->denies('manage', $user)) {
                $skipped++;

                return;
            }

            switch ($validated['action']) {
                case 'logout':
                    $this->admin->logOutEverywhere($user);
                    break;
                case 'suspend':
                    $this->admin->setStatus($user, User::STATUS_SUSPENDED);
                    break;
                case 'activate':
                    if ($user->isBanned()) {
                        $bans->unban($user);
                    } else {
                        $this->admin->setStatus($user, User::STATUS_ACTIVE);
                    }
                    break;
                case 'ban':
                    $days = $validated['duration'] === 'permanent' ? null : (int) $validated['duration'];
                    $bans->ban($user, $admin, $days, (string) $validated['reason']);
                    break;
                case 'unban':
                    if (! $user->isBanned()) {
                        $skipped++;

                        return;
                    }
                    $bans->unban($user);
                    break;
                case 'delete':
                    $this->audit->record($admin, 'user.deleted', $user, "Deleted the account of {$user->name} (@{$user->username})", ['email' => $user->email, 'phone' => $user->phone, 'bulk' => true]);
                    $this->admin->deleteUser($user);
                    $done++;

                    return;
            }

            $done++;
        });

        $labels = ['logout' => 'Signed out', 'suspend' => 'Suspended', 'activate' => 'Activated', 'ban' => 'Banned', 'unban' => 'Lifted the ban on', 'delete' => 'Deleted'];
        $summary = "{$labels[$validated['action']]} {$done} ".($done === 1 ? 'account' : 'accounts');
        $this->audit->record($admin, 'users.bulk', null, $summary.' at once', array_filter([
            'action' => $validated['action'],
            'ids' => array_values(array_unique(array_map('intval', $validated['ids']))),
            'skipped' => $skipped,
            'reason' => $validated['reason'] ?? null,
        ]));

        return back()->with('status', $summary.($skipped ? " · {$skipped} skipped (administrators, your own account or nothing to change)" : '').'.');
    }

    /** Everything stored about one account, as JSON (like "Download my data"). */
    public function data(Request $request, User $user, AccountExportService $export): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $this->audit->record($request->user(), 'user.data_exported', $user, "Downloaded the account data of {$user->name}");

        return response()->json($export->build($user, $request), 200, [
            'Content-Disposition' => 'attachment; filename="account-'.$user->id.'-'.now()->format('Y-m-d').'.json"',
            'Cache-Control' => 'private, no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Sign someone out of one browser. */
    public function endSession(Request $request, User $user, string $key): RedirectResponse
    {
        Gate::authorize('manage', $user);

        $row = DB::table('sessions')->where('user_id', $user->getKey())->get(['id', 'user_agent'])
            ->first(fn ($row) => hash_equals(app(SessionService::class)->keyFor($row->id), $key));
        abort_if($row === null, 404);

        DB::table('sessions')->where('id', $row->id)->delete();
        $this->audit->record($request->user(), 'user.session_ended', $user, "Signed {$user->name} out of one browser");

        return back()->with('status', 'That browser is signed out.');
    }

    private function cell(mixed $value): string
    {
        $value = (string) $value;

        // Spreadsheets run cells starting with these as formulas.
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value) ? "'".$value : $value;
    }
}
