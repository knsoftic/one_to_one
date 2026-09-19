<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Subscriptions (Y2): every paid period, and giving or ending a plan for one person.
 * Both actions go through PlanService, which audits them.
 */
class SubscriptionController extends Controller
{
    public function __construct(private readonly PlanService $plans) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');
        $status = in_array($status, Subscription::STATUSES, true) ? $status : '';
        $planId = (int) $request->query('plan', 0);

        $subscriptions = Subscription::query()
            ->with(['user', 'plan', 'payment', 'endedBy'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($planId > 0, fn ($q) => $q->where('plan_id', $planId))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.subscriptions.index', [
            'subscriptions' => $subscriptions,
            'status' => $status,
            'planId' => $planId,
            'plans' => Plan::query()->orderBy('sort')->orderBy('id')->get(['id', 'name', 'is_active']),
            'counts' => [
                'active' => Subscription::query()->active()->count(),
                'queued' => Subscription::query()->where('status', 'queued')->count(),
            ],
        ]);
    }

    /** Give a plan for a number of days (no payment); audited as subscription.granted. */
    public function grant(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        $plan = Plan::query()->findOrFail($validated['plan_id']);
        $sub = $this->plans->grant($request->user(), $user, $plan, (int) $validated['days']);

        $message = $sub->status === 'queued'
            ? "{$plan->name} will start for {$user->name} on {$sub->starts_at->format('j M Y')}, after the current plan."
            : "{$plan->name} is on for {$user->name} until {$sub->ends_at->format('j M Y')}.";

        return back()->with('status', $message);
    }

    /** End a running or queued subscription now; the note goes into the audit row. */
    public function end(Request $request, User $user, Subscription $subscription): RedirectResponse
    {
        abort_unless((int) $subscription->user_id === (int) $user->getKey(), 404);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:200'],
        ]);

        if (! in_array($subscription->status, ['active', 'queued'], true)) {
            return back()->with('error', 'This subscription has already ended.');
        }

        $this->plans->revoke($subscription, 'admin', $request->user(), $validated['note']);

        return back()->with('status', "Ended the {$subscription->plan?->name} plan of {$user->name}.");
    }
}
