<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\AdProfile;
use App\Models\AdView;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\AdPlacement;
use App\Support\DialCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ads (Y1): picks the ad to show in a given placement, and records that it was shown or tapped.
 * Ads run for everyone — the admin decides whether they run at all and where.
 */
class AdService
{
    /** Master switch, set in Admin → App settings → Ads. */
    public function enabled(): bool
    {
        return (bool) AppSetting::get('ads_enabled');
    }

    /**
     * The next ad for a user in one placement, or null when there is nothing to show.
     */
    public function pickForUser(User $user, string $placement = 'chat_list'): ?AdCampaign
    {
        if (! $this->enabled() || ! AdPlacement::isEnabled($placement)) {
            return null;
        }
        // A plan with "no ads" (Y2) means no house ads and no promotions.
        if (app(PlanService::class)->hasBenefit($user, 'ads_off')) {
            return null;
        }

        $profile = AdProfile::query()->find($user->getKey());
        $country = $profile?->ip_country ?? $profile?->country ?? DialCode::country($user->phone);
        $age = $user->age();

        // Ads shown or tapped today, for frequency capping (across all placements).
        $todayViews = AdView::query()->where('user_id', $user->getKey())->whereDate('day', today())
            ->get(['campaign_id', 'views', 'clicked'])
            ->groupBy('campaign_id');

        $eligible = AdCampaign::query()
            ->where('status', 'active')
            // Promotions (Y2): only approved ones with views left, and never to their own owner.
            ->where(fn ($q) => $q->whereNull('owner_id')->orWhere('review_status', 'approved'))
            ->where(fn ($q) => $q->whereNull('view_budget')->orWhereColumn('impressions', '<', 'view_budget'))
            ->where(fn ($q) => $q->whereNull('owner_id')->orWhere('owner_id', '!=', $user->getKey()))
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->get()
            ->filter(fn (AdCampaign $c) => $this->matches($c, $placement, $user, $profile, $country, $age, $todayViews->get($c->id)))
            ->values();

        return $this->weightedPick($eligible);
    }

    /**
     * @param  ?Collection<int, AdView>  $views  today's rows for this campaign
     */
    private function matches(AdCampaign $campaign, string $placement, User $user, ?AdProfile $profile, ?string $country, ?int $age, ?Collection $views): bool
    {
        if (! $campaign->runsIn($placement)) {
            return false;
        }

        // Frequency cap, and never re-show an ad the person already tapped today.
        $seen = (int) ($views?->sum('views') ?? 0);
        if ($views?->contains('clicked', true) || $seen >= max(1, $campaign->per_user_daily_cap)) {
            return false;
        }
        // Country.
        if (! empty($campaign->countries) && (! $country || ! in_array($country, $campaign->countries, true))) {
            return false;
        }
        // Segments: any overlap.
        if (! empty($campaign->segments) && empty(array_intersect($campaign->segments, $profile?->segments ?? []))) {
            return false;
        }
        // Age, from the date of birth in the person's profile.
        if ($campaign->min_age !== null || $campaign->max_age !== null) {
            if ($age === null
                || ($campaign->min_age !== null && $age < $campaign->min_age)
                || ($campaign->max_age !== null && $age > $campaign->max_age)) {
                return false;
            }
        }
        // Gender, from the person's profile.
        if ($campaign->gender !== null && $user->gender !== $campaign->gender) {
            return false;
        }

        return true;
    }

    /**
     * @param  Collection<int, AdCampaign>  $campaigns
     */
    private function weightedPick($campaigns): ?AdCampaign
    {
        $total = $campaigns->sum(fn (AdCampaign $c) => max(1, $c->weight));
        if ($total <= 0) {
            return null;
        }
        $roll = random_int(1, $total);
        foreach ($campaigns as $campaign) {
            $roll -= max(1, $campaign->weight);
            if ($roll <= 0) {
                return $campaign;
            }
        }

        return $campaigns->first();
    }

    public function recordImpression(AdCampaign $campaign, User $user, string $placement = 'chat_list'): void
    {
        $this->countView($campaign, $user, $placement, clicked: false);
    }

    public function recordClick(AdCampaign $campaign, User $user, string $placement = 'chat_list'): void
    {
        $this->countView($campaign, $user, $placement, clicked: true);
    }

    private function countView(AdCampaign $campaign, User $user, string $placement, bool $clicked): void
    {
        $placement = AdPlacement::exists($placement) ? $placement : 'chat_list';
        $today = today()->toDateString();

        DB::transaction(function () use ($campaign, $user, $placement, $clicked, $today) {
            $view = AdView::query()->firstOrNew([
                'campaign_id' => $campaign->id,
                'user_id' => $user->getKey(),
                'placement' => $placement,
                'day' => $today,
            ]);

            if ($clicked) {
                $campaign->increment('clicks');
                $this->stat($campaign->id, $today, clicks: 1);
                $this->placementStat($campaign->id, $placement, clicks: 1);
                $view->clicked = true;
            } else {
                // A new impression only while the person is under the cap for this ad today.
                $seen = (int) AdView::query()
                    ->where('campaign_id', $campaign->id)->where('user_id', $user->getKey())
                    ->whereDate('day', $today)->sum('views');
                if ($seen >= max(1, $campaign->per_user_daily_cap)) {
                    return;
                }
                $campaign->increment('impressions');
                $this->stat($campaign->id, $today, impressions: 1);
                $this->placementStat($campaign->id, $placement, impressions: 1);
                $view->views = ($view->views ?? 0) + 1;
            }
            $view->save();
        });
    }

    private function stat(int $campaignId, string $day, int $impressions = 0, int $clicks = 0): void
    {
        DB::table('ad_stats')->upsert(
            [['campaign_id' => $campaignId, 'day' => $day, 'impressions' => $impressions, 'clicks' => $clicks]],
            ['campaign_id', 'day'],
            [
                'impressions' => DB::raw('ad_stats.impressions + '.$impressions),
                'clicks' => DB::raw('ad_stats.clicks + '.$clicks),
            ],
        );
    }

    private function placementStat(int $campaignId, string $placement, int $impressions = 0, int $clicks = 0): void
    {
        DB::table('ad_placement_stats')->upsert(
            [['campaign_id' => $campaignId, 'placement' => $placement, 'impressions' => $impressions, 'clicks' => $clicks]],
            ['campaign_id', 'placement'],
            [
                'impressions' => DB::raw('ad_placement_stats.impressions + '.$impressions),
                'clicks' => DB::raw('ad_placement_stats.clicks + '.$clicks),
            ],
        );
    }
}
