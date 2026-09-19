<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\AdminAuditService;
use App\Services\MonetisationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Plans (Y2): the paid plans people can buy. Editing a plan never changes a running
 * subscription (they carry a snapshot), and a plan with subscriptions can only be deactivated.
 */
class PlanController extends Controller
{
    public function __construct(
        private readonly AdminAuditService $audit,
        private readonly MonetisationService $money,
    ) {}

    public function index(): View
    {
        $plans = Plan::query()->withCount([
            'subscriptions as active_subscriptions_count' => fn ($q) => $q->active(),
        ])->orderBy('sort')->orderBy('id')->paginate(30);

        return view('admin.plans.index', [
            'plans' => $plans,
            'activeSubscriptions' => Subscription::query()->active()->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.plans.edit', [
            'plan' => new Plan(['period' => 'month', 'currency' => $this->money->currency(), 'is_active' => true, 'sort' => 0, 'monthly_coins' => 0]),
        ]);
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.edit', [
            'plan' => $plan,
            'subscriptions' => $plan->subscriptions()->count(),
            'activeSubscriptions' => $plan->subscriptions()->active()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = Plan::query()->create($this->validated($request));
        $this->audit->record($request->user(), 'plan.created', $plan, "Created the plan \"{$plan->name}\"", $this->meta($plan));

        return redirect()->route('admin.plans.edit', $plan)->with('status', 'Plan created.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $plan->update($this->validated($request, $plan));
        $this->audit->record($request->user(), 'plan.updated', $plan, "Edited the plan \"{$plan->name}\"", $this->meta($plan));

        return redirect()->route('admin.plans.edit', $plan)->with('status', 'Plan saved. People already on it keep what they bought.');
    }

    public function destroy(Request $request, Plan $plan): RedirectResponse
    {
        // Subscriptions keep the plan they were bought from (restrictOnDelete), past ones included.
        if ($plan->subscriptions()->exists()) {
            return redirect()->route('admin.plans.edit', $plan)
                ->with('error', 'People have subscribed to this plan, so it cannot be deleted. Deactivate it instead: nobody new can buy it and running subscriptions carry on.');
        }

        $name = $plan->name;
        $meta = $this->meta($plan);
        $plan->delete();
        $this->audit->record($request->user(), 'plan.deleted', null, "Deleted the plan \"{$name}\"", $meta);

        return redirect()->route('admin.plans')->with('status', 'Plan deleted.');
    }

    /**
     * Prices are typed in the currency's main unit ("499" or "4.99") and stored in minor units.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $request->merge([
            'slug' => strtolower(trim((string) $request->input('slug'))),
            'currency' => strtoupper(trim((string) $request->input('currency'))),
            'play_product_id' => trim((string) $request->input('play_product_id')) ?: null,
            'price_usd' => trim((string) $request->input('price_usd')) === '' ? null : $request->input('price_usd'),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('plans', 'slug')->ignore($plan)],
            'description' => ['nullable', 'string', 'max:200'],
            'period' => ['required', Rule::in(array_keys(Plan::PERIODS))],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'price_usd' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'ads_off' => ['nullable', 'boolean'],
            'verified_badge' => ['nullable', 'boolean'],
            'monthly_coins' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'limits' => ['nullable', 'array'],
            'limits.upload_mb' => ['nullable', 'integer', 'min:1', 'max:4096'],
            'limits.group_members' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'limits.broadcast_recipients' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'limits.storage_mb' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'play_product_id' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.]+$/', Rule::unique('plans', 'play_product_id')->ignore($plan)],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ], [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'currency.regex' => 'Use a 3-letter currency code such as PKR or USD.',
            'play_product_id.regex' => 'Play product ids use lowercase letters, numbers, underscores and dots.',
            'play_product_id.unique' => 'Another plan already uses this Play product id.',
        ]);

        // Only the limit keys the app knows, and only the ones that were filled in.
        $limits = array_filter(
            array_intersect_key($validated['limits'] ?? [], array_flip(Plan::LIMIT_KEYS)),
            fn ($value) => $value !== null && $value !== '',
        );

        return [
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'period' => $validated['period'],
            'price_minor' => (int) round((float) $validated['price'] * 100),
            'currency' => $validated['currency'],
            'price_usd_minor' => isset($validated['price_usd']) ? (int) round((float) $validated['price_usd'] * 100) : null,
            'ads_off' => $request->boolean('ads_off'),
            'verified_badge' => $request->boolean('verified_badge'),
            'monthly_coins' => (int) ($validated['monthly_coins'] ?? 0),
            'limits' => $limits ? array_map('intval', $limits) : null,
            'play_product_id' => $validated['play_product_id'] ?? null,
            'is_active' => $request->boolean('is_active'),
            'sort' => (int) ($validated['sort'] ?? 0),
        ];
    }

    /** What the audit row remembers about a plan (no secrets involved). */
    private function meta(Plan $plan): array
    {
        return [
            'plan' => $plan->getKey(),
            'slug' => $plan->slug,
            'period' => $plan->period,
            'price_minor' => (int) $plan->price_minor,
            'currency' => $plan->currency,
            'is_active' => (bool) $plan->is_active,
        ];
    }
}
