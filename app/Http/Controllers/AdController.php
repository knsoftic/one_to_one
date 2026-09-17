<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Services\AdService;
use App\Services\AdTargetingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ads (Y1): the user's own consent and ad data, and serving a sponsored card to the app.
 */
class AdController extends Controller
{
    public function __construct(
        private readonly AdService $ads,
        private readonly AdTargetingService $targeting,
    ) {}

    /** Turn personalised ads on or off (the app's first-run choice, or the settings switch). */
    public function consent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'personalised' => ['required', 'boolean'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:12'],
            'platform' => ['nullable', 'string', 'max:16'],
            'os_version' => ['nullable', 'string', 'max:24'],
            'app_version' => ['nullable', 'string', 'max:24'],
        ]);

        $this->targeting->setConsent($request->user(), (bool) $data['personalised'], $data);

        return response()->json($this->state($request));
    }

    /** Optional ad-settings the user can change: coarse location, gender, birth year. */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless((bool) $user->ads_personalised, 403, 'Turn on personalised ads first.');

        $data = $request->validate([
            'location_allowed' => ['sometimes', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'gender' => ['sometimes', 'nullable', Rule::in(['male', 'female'])],
            'birth_year' => ['sometimes', 'nullable', 'integer', 'between:1900,'.((int) date('Y'))],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $this->targeting->rebuild($user, $data);

        return response()->json($this->state($request));
    }

    /** "The ad data we keep about you" (also downloadable via the account data export). */
    public function data(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    /** The next sponsored card, or an empty body when there is nothing to show. */
    public function next(Request $request): JsonResponse
    {
        $campaign = $this->ads->pickForUser($request->user());
        if ($campaign === null) {
            return response()->json(['ad' => null]);
        }
        $this->ads->recordImpression($campaign, $request->user());

        return response()->json(['ad' => $campaign->payload() + [
            'click' => route('ads.click', $campaign),
            'url' => $campaign->target_url,
        ]]);
    }

    /** Records the tap and sends the user on to the advertiser (opened in the browser by the app). */
    public function click(Request $request, AdCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->isLive(), 404);
        $this->ads->recordClick($campaign, $request->user());

        return redirect()->away($campaign->target_url);
    }

    private function state(Request $request): array
    {
        $user = $request->user()->fresh();
        $profile = $user->adProfile()->first();

        return [
            'personalised' => (bool) $user->ads_personalised,
            'decided' => $user->ads_consent_at !== null,
            'location_allowed' => (bool) ($profile?->location_allowed),
            'gender' => $profile?->gender,
            'birth_year' => $profile?->birth_year,
            'data' => $profile?->summary() ?? [],
        ];
    }
}
