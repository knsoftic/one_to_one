<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AdminAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin panel: the audit log and app-wide settings.
 */
class SystemController extends Controller
{
    public function audit(Request $request): View
    {
        $filters = $request->validate([
            'action' => ['nullable', Rule::in(array_keys(AdminAuditLog::ACTIONS))],
            'admin' => ['nullable', 'integer'],
            'target' => ['nullable', 'integer'],
        ]);

        $logs = AdminAuditLog::query()
            ->with('admin')
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['admin'] ?? null, fn ($q, $id) => $q->where('admin_id', $id))
            ->when($filters['target'] ?? null, fn ($q, $id) => $q->where('target_type', 'User')->where('target_id', $id))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'admins' => User::query()->whereIn('id', AdminAuditLog::query()->select('admin_id')->distinct())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function settings(): View
    {
        return view('admin.settings', [
            'registrationOpen' => (bool) AppSetting::get('registration_open'),
            'notice' => AppSetting::get('notice'),
        ]);
    }

    public function updateSettings(Request $request, AdminAuditService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'registration_open' => ['nullable', 'boolean'],
            'notice' => ['nullable', 'string', 'max:300'],
        ]);

        $values = [
            'registration_open' => $request->boolean('registration_open'),
            'notice' => filled($validated['notice'] ?? null) ? trim((string) $validated['notice']) : null,
        ];
        AppSetting::put($values);

        $audit->record($request->user(), 'settings.updated', null, 'Changed app settings: sign-ups '.($values['registration_open'] ? 'open' : 'closed').', notice '.($values['notice'] ? 'on' : 'off'), $values);

        return back()->with('status', 'Settings saved.');
    }
}
