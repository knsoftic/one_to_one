<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\AdProfile;
use App\Models\AdView;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\DialCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ads (Y1): picks a house ad to show a user, and records that it was shown or tapped.
 * Targeting by interests, age or gender is used only for users who turned personalised ads on;
 * everyone else gets non-personalised ads (country from their own number is still allowed).
 */
class AdService
{
    /** Master switch, set in Admin → App settings → Ads. */
    public function enabled(): bool
    {
        return (bool) AppSetting::get('ads_enabled');
    }

    /**
     * The next sponsored card for a user, or null when there is nothing to show.
     */
    public function pickForUser(User $user): ?AdCampaign
    {
        if (! $this->enabled()) {
            return null;
        }

        $profile = $user->ads_personalised ? AdProfile::query()->find($user->getKey()) : null;
        $country = $profile?->country ?? DialCode::country($user->phone);
        $today = today();

        // Ads shown or clicked today, for frequency capping.
        $todayViews = AdView::query()->where('user_id', $user->getKey())->whereDate('day', $today)
            ->get(['campaign_id', 'views', 'clicked'])->keyBy('campaign_id');

        $eligible = AdCampaign::query()
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
            ->get()
            ->filter(fn (AdCampaign $c) => $this->matches($c, $user, $profile, $country, $todayViews->get($c->id)))
            ->values();

        return $this->weightedPick($eligible);
    }

    private function matches(AdCampaign $campaign, User $user, ?AdProfile $profile, ?string $country, ?AdView $view): bool
    {
        // Personalised targeting needs consent.
        if ($campaign->needsConsent() && ! $user->ads_personalised) {
            return false;
        }
        // Frequency cap, and don't re-show an ad the user already tapped today.
        if ($view && ($view->clicked || $view->views >= max(1, $campaign->per_user_daily_cap))) {
            return false;
        }
        // Country (allowed for everyone — it comes from the user's own number).
        if (! empty($campaign->countries) && (! $country || ! in_array($country, $campaign->countries, true))) {
            return false;
        }
        // Segments: any overlap.
        if (! empty($campaign->interests) && empty(array_intersect($campaign->interests, $profile?->interests ?? []))) {
            return false;
        }
        // Age.
        $age = $profile?->age();
        if (($campaign->min_age !== null || $campaign->max_age !== null)) {
            if ($age === null) {
                return false;
            }
            if ($campaign->min_age !== null && $age < $campaign->min_age) {
                return false;
            }
            if ($campaign->max_age !== null && $age > $campaign->max_age) {
                return false;
            }
        }
        // Gender.
        if ($campaign->gender !== null && $profile?->gender !== $campaign->gender) {
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

    public function recordImpression(AdCampaign $campaign, User $user): void
    {
        $this->countView($campaign, $user, clicked: false);
    }

    public function recordClick(AdCampaign $campaign, User $user): void
    {
        $this->countView($campaign, $user, clicked: true);
    }

    private function countView(AdCampaign $campaign, User $user, bool $clicked): void
    {
        $today = today()->toDateString();

        DB::transaction(function () use ($campaign, $user, $clicked, $today) {
            $view = AdView::query()->firstOrNew(['campaign_id' => $campaign->id, 'user_id' => $user->getKey(), 'day' => $today]);

            if ($clicked) {
                $campaign->increment('clicks');
                $this->stat($campaign->id, $today, clicks: 1);
                $view->clicked = true;
            } else {
                // A new impression only when the user has not yet reached the cap for this ad today.
                if ($view->exists && $view->views >= max(1, $campaign->per_user_daily_cap)) {
                    return;
                }
                $campaign->increment('impressions');
                $this->stat($campaign->id, $today, impressions: 1);
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
}
