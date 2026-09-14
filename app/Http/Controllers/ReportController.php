<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use App\Models\UserReport;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * P6 — Report someone (users) and review reports (admins).
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function store(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', Rule::in(array_keys(UserReport::REASONS))],
            'details' => ['nullable', 'string', 'max:1000'],
            'conversation_id' => ['nullable', 'integer'],
            'block' => ['nullable', 'boolean'],
        ], ['reason.required' => 'Choose why you are reporting.']);

        $conversation = isset($validated['conversation_id']) ? Conversation::find($validated['conversation_id']) : null;
        $report = $this->reports->report($request->user(), $user, $validated['reason'], $validated['details'] ?? null, $conversation, $request->boolean('block'));

        return response()->json(['id' => $report->id, 'blocked' => $request->boolean('block'), 'evidence_count' => count($report->evidence ?? [])], 201);
    }

    public function index(Request $request): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in(UserReport::STATUSES)]]);
        $status = $filters['status'] ?? UserReport::STATUS_OPEN;

        return view('admin.reports.index', [
            'reports' => UserReport::query()->where('status', $status)->with(['reporter', 'reportedUser'])->latest()->paginate(20)->withQueryString(),
            'status' => $status,
            'counts' => UserReport::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function show(UserReport $report): View
    {
        $report->load(['reporter', 'reportedUser', 'reviewer']);

        return view('admin.reports.show', [
            'report' => $report,
            'otherReports' => UserReport::query()->where('reported_user_id', $report->reported_user_id)->whereKeyNot($report->getKey())->count(),
        ]);
    }

    public function update(Request $request, UserReport $report): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(UserReport::STATUSES)],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->reports->review($report, $request->user(), $validated['status'], $validated['admin_note'] ?? null);

        return redirect()->route('admin.reports.show', $report)->with('status', match ($validated['status']) {
            UserReport::STATUS_REVIEWED => 'Report marked as reviewed.',
            UserReport::STATUS_DISMISSED => 'Report dismissed.',
            default => 'Report reopened.',
        });
    }
}
