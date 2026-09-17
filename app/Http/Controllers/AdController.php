<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Services\AdService;
use App\Services\AdTargetingService;
use App\Support\AdPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ads (Y1): serving an ad to a placement in the app, and the "what we use to choose your ads"
 * panel in Settings › Privacy.
 */
class AdController extends Controller
{
    public function __construct(
        private readonly AdService $ads,
        private readonly AdTargetingService $targeting,
    ) {}

    /**
     * The app says hello when it opens: the device tells us what it can (time zone, language,
     * model, app version) and, once the phone's location permission is granted, a rounded
     * position. Nothing here is asked of the user beyond the system permission itself.
     */
    public function open(Request $request): JsonResponse
    {
        $data = $this->deviceRules($request);

        $this->targeting->recordOpen($request->user(), $data, $request);

        return response()->json($this->state($request));
    }

    /** The same details, sent again when something changes (e.g. location was just allowed). */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $this->deviceRules($request);

        $this->targeting->rebuild($request->user(), $data, $request);

        return response()->json($this->state($request));
    }

    /** "What we use to choose your ads". */
    public function data(Request $request): JsonResponse
    {
        return response()->json($this->state($request));
    }

    /** The next ad for one placement, or an empty body when there is nothing to show. */
    public function next(Request $request): JsonResponse
    {
        $placement = (string) $request->query('placement', 'chat_list');
        abort_unless(AdPlacement::exists($placement), 404);

        $campaign = $this->ads->pickForUser($request->user(), $placement);
        if ($campaign === null) {
            return response()->json(['ad' => null]);
        }
        $this->ads->recordImpression($campaign, $request->user(), $placement);

        return response()->json(['ad' => $campaign->payload() + [
            'placement' => $placement,
            'format' => AdPlacement::format($placement),
            'click' => route('ads.click', ['campaign' => $campaign, 'placement' => $placement]),
            'url' => $campaign->target_url,
        ]]);
    }

    /** Records the tap and sends the person on to the advertiser (opened in the browser by the app). */
    public function click(Request $request, AdCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->isLive(), 404);

        $placement = (string) $request->query('placement', 'chat_list');
        $this->ads->recordClick($campaign, $request->user(), $placement);

        return redirect()->away($campaign->target_url);
    }

    /**
     * What the device may tell us. Everything is optional — a browser or a phone that refuses
     * the location permission simply sends less.
     */
    private function deviceRules(Request $request): array
    {
        return $request->validate([
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:12'],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            'os_version' => ['nullable', 'string', 'max:24'],
            'device_model' => ['nullable', 'string', 'max:64'],
            'app_version' => ['nullable', 'string', 'max:24'],
            'location_allowed' => ['sometimes', 'boolean'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
    }

    private function state(Request $request): array
    {
        $user = $request->user();
        $profile = $user->adProfile()->first();

        return [
            'location_allowed' => (bool) $profile?->location_allowed,
            'data' => $profile?->summary($user) ?? [],
        ];
    }
}
