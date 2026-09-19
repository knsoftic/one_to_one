<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\BadgeService;
use App\Services\MonetisationService;
use App\Services\PaymentService;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → Premium (Y2): the plans on sale, this person's current and queued subscription,
 * the verified badge, and which payment methods this platform may use. Rendered by
 * resources/js/ui/premium.js.
 */
class PremiumController extends Controller
{
    /** "Renew" is offered from this many days before the plan ends. */
    public const RENEW_DAYS = 7;

    public function __construct(
        private readonly PlanService $plans,
        private readonly BadgeService $badges,
        private readonly PaymentService $payments,
        private readonly MonetisationService $money,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $active = $this->plans->activeFor($user);
        $queued = Subscription::query()->where('user_id', $user->getKey())->where('status', 'queued')
            ->orderBy('starts_at')->with('plan')->first();

        return response()->json([
            'plans' => $this->plans->purchasable()->map(fn (Plan $plan) => $this->plan($plan))->values(),
            'active' => $active ? $this->subscription($active) + [
                'renew_available' => $active->ends_at->lte(now()->addDays(self::RENEW_DAYS)),
            ] : null,
            'queued' => $queued ? $this->subscription($queued) : null,
            'badge' => $this->badges->state($user),
            'methods' => $this->payments->methodsFor($user, $request),
            'play' => $this->money->configFor($user, $request)['play'] ?? ['enabled' => false, 'accountHash' => null, 'minAppCode' => null],
            'refund_url' => route('legal', 'refunds'),
        ]);
    }

    private function plan(Plan $plan): array
    {
        return [
            'id' => $plan->getKey(),
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'period' => $plan->period,
            'price_minor' => (int) $plan->price_minor,
            'price_display' => $this->money->formatMoney((int) $plan->price_minor, $plan->currency),
            'currency' => $plan->currency,
            'price_usd_minor' => $plan->price_usd_minor !== null ? (int) $plan->price_usd_minor : null,
            'benefits' => $plan->benefits(),
            'play_product_id' => $plan->play_product_id,
        ];
    }

    private function subscription(Subscription $sub): array
    {
        return [
            'id' => $sub->getKey(),
            'plan_id' => (int) $sub->plan_id,
            'plan' => $sub->plan?->name ?? 'Plan',
            'status' => $sub->status,
            'starts_at' => $sub->starts_at?->toIso8601String(),
            'ends_at' => $sub->ends_at?->toIso8601String(),
            'benefits' => $sub->benefits ?? [],
        ];
    }
}
