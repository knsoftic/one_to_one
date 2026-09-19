<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Services\AdminAuditService;
use App\Support\AdPlacement;
use App\Support\DialCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Ads: create and manage the app's own "sponsored" cards (Y1).
 */
class AdController extends Controller
{
    public function __construct(private readonly AdminAuditService $audit) {}

    public function index(): View
    {
        // House ads only: user promotions (Y2) have their own review queue.
        $campaigns = AdCampaign::query()->house()->latest()->paginate(20);
        $totals = AdCampaign::query()->house()->selectRaw('SUM(impressions) i, SUM(clicks) c')->first();

        return view('admin.ads.index', [
            'campaigns' => $campaigns,
            'live' => AdCampaign::query()->house()->where('status', 'active')->count(),
            'impressions' => (int) ($totals->i ?? 0),
            'clicks' => (int) ($totals->c ?? 0),
        ]);
    }

    public function create(): View
    {
        return view('admin.ads.edit', ['campaign' => new AdCampaign(['status' => 'draft', 'cta_label' => 'Learn more', 'per_user_daily_cap' => 3, 'weight' => 1]), 'countries' => DialCode::countries()]);
    }

    public function edit(AdCampaign $ad): View
    {
        $days = DB::table('ad_stats')->where('campaign_id', $ad->id)->orderByDesc('day')->limit(30)
            ->get()->map(fn ($r) => ['label' => Carbon::parse($r->day)->format('j M'), 'title' => $r->day, 'count' => (int) $r->impressions])->reverse()->values()->all();

        $byPlacement = DB::table('ad_placement_stats')->where('campaign_id', $ad->id)->get()->keyBy('placement');

        return view('admin.ads.edit', [
            'campaign' => $ad,
            'countries' => DialCode::countries(),
            'days' => $days,
            'byPlacement' => $byPlacement,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->getKey();
        $data['image_path'] = $this->storeImage($request);

        $campaign = AdCampaign::query()->create($data);
        $this->audit->record($request->user(), 'ad.created', null, "Created the ad campaign \"{$campaign->name}\"");

        return redirect()->route('admin.ads.edit', $campaign)->with('status', 'Ad campaign created.');
    }

    public function update(Request $request, AdCampaign $ad): RedirectResponse
    {
        $data = $this->validated($request);
        if ($request->hasFile('image') || $request->boolean('remove_image')) {
            if ($ad->image_path) {
                Storage::disk('public')->delete($ad->image_path);
            }
            $data['image_path'] = $request->boolean('remove_image') ? null : $this->storeImage($request);
        }

        $ad->update($data);
        $this->audit->record($request->user(), 'ad.updated', null, "Edited the ad campaign \"{$ad->name}\"");

        return redirect()->route('admin.ads.edit', $ad)->with('status', 'Ad campaign saved.');
    }

    public function destroy(Request $request, AdCampaign $ad): RedirectResponse
    {
        if ($ad->image_path) {
            Storage::disk('public')->delete($ad->image_path);
        }
        $name = $ad->name;
        $ad->delete();
        $this->audit->record($request->user(), 'ad.deleted', null, "Deleted the ad campaign \"{$name}\"");

        return redirect()->route('admin.ads')->with('status', 'Ad campaign deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['required', Rule::in(AdCampaign::STATUSES)],
            'title' => ['required', 'string', 'max:80'],
            'body' => ['nullable', 'string', 'max:200'],
            'cta_label' => ['required', 'string', 'max:24'],
            'target_url' => ['required', 'url:http,https', 'max:600'],
            'sponsor' => ['nullable', 'string', 'max:60'],
            'countries' => ['nullable', 'array'],
            'countries.*' => [Rule::in(array_keys(DialCode::countries()))],
            'segments' => ['nullable', 'array'],
            'segments.*' => [Rule::in(array_keys(AdCampaign::SEGMENTS))],
            'placements' => ['nullable', 'array'],
            'placements.*' => [Rule::in(AdPlacement::keys())],
            'min_age' => ['nullable', 'integer', 'between:13,100'],
            'max_age' => ['nullable', 'integer', 'between:13,100', 'gte:min_age'],
            'gender' => ['nullable', Rule::in(array_keys(AdCampaign::GENDERS))],
            'per_user_daily_cap' => ['required', 'integer', 'between:1,50'],
            'weight' => ['required', 'integer', 'between:1,100'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:3072'],
        ]);

        $validated['countries'] = array_values($validated['countries'] ?? []) ?: null;
        $validated['segments'] = array_values($validated['segments'] ?? []) ?: null;
        // No placement ticked means "wherever ads are switched on".
        $validated['placements'] = array_values($validated['placements'] ?? []) ?: null;
        unset($validated['image']);

        return $validated;
    }

    private function storeImage(Request $request): ?string
    {
        return $request->hasFile('image') ? $request->file('image')->store('ads', 'public') : null;
    }
}
