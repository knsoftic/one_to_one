<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\BusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * X8 — Settings → Business tools: business profile, away and greeting messages and
 * quick replies; and the business details other people see in the contact info.
 */
class BusinessController extends Controller
{
    public function __construct(private readonly BusinessService $business) {}

    public function saveProfile(Request $request): RedirectResponse
    {
        $time = ['nullable', 'date_format:H:i'];
        $validated = $request->validateWithBag('business', [
            'category' => ['required', Rule::in(array_keys(BusinessProfile::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:512'],
            'address' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:191'],
            'website' => ['nullable', 'url:https,http', 'max:255'],
            'hours_mode' => ['required', Rule::in(array_keys(BusinessProfile::HOURS_MODES))],
            'days' => ['nullable', 'array'],
            'days.*.open' => ['nullable', 'boolean'],
            'days.*.from' => $time,
            'days.*.to' => $time,
        ], ['category.required' => 'Choose what kind of business this is.']);

        $created = ! $this->business->profileFor($request->user());
        $this->business->saveProfile($request->user(), $validated);

        return redirect()->route('profile.edit', ['tab' => 'business'])
            ->with('status', $created ? 'Business account turned on.' : 'Business profile saved.');
    }

    public function saveMessages(Request $request): RedirectResponse
    {
        $profile = $this->business->profileFor($request->user());
        abort_if($profile === null, 404);

        $validated = $request->validateWithBag('businessMessages', [
            'away_enabled' => ['nullable', 'boolean'],
            'away_message' => ['nullable', 'required_if_accepted:away_enabled', 'string', 'max:1000'],
            'away_schedule' => ['required', Rule::in(array_keys(BusinessProfile::AWAY_SCHEDULES))],
            'away_from' => ['nullable', 'required_if:away_schedule,custom', 'date'],
            'away_until' => ['nullable', 'required_if:away_schedule,custom', 'date', 'after:away_from'],
            'away_recipients' => ['required', Rule::in(array_keys(BusinessProfile::RECIPIENTS))],
            'greeting_enabled' => ['nullable', 'boolean'],
            'greeting_message' => ['nullable', 'required_if_accepted:greeting_enabled', 'string', 'max:1000'],
            'greeting_recipients' => ['required', Rule::in(array_keys(BusinessProfile::RECIPIENTS))],
        ], [
            'away_message.required_if_accepted' => 'Write the away message.',
            'greeting_message.required_if_accepted' => 'Write the greeting message.',
            'away_until.after' => 'The end must be after the start.',
        ]);

        if (($validated['away_schedule'] === 'outside_hours') && ! $profile->hasHours()) {
            return back()->withErrors(['away_schedule' => 'Set your business hours first, or choose another time.'], 'businessMessages')->withInput();
        }

        $this->business->saveMessages($profile, $validated);

        return redirect()->route('profile.edit', ['tab' => 'business'])->with('status', 'Automatic messages saved.');
    }

    /** Back to a normal account (quick replies are kept). */
    public function destroy(Request $request): RedirectResponse
    {
        BusinessProfile::query()->where('user_id', $request->user()->getKey())->delete();

        return redirect()->route('profile.edit', ['tab' => 'business'])->with('status', 'Business account turned off.');
    }

    /** The business details of someone you can chat with ({business: null} for a normal account). */
    public function show(Request $request, User $user): JsonResponse
    {
        abort_if(! $user->isActive() || $user->hasBlocked($request->user()) || $request->user()->hasBlocked($user), 404);

        return response()->json(['business' => $this->business->profileFor($user)?->publicPayload()]);
    }

    /* ------------------------------------------------------------------ */
    /* Quick replies */
    /* ------------------------------------------------------------------ */

    public function quickReplies(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()->quickReplies()->orderBy('shortcut')->get()->map->toPayload()->values()]);
    }

    public function storeQuickReply(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_if($user->quickReplies()->count() >= QuickReply::MAX_PER_USER, 422, 'You can save up to '.QuickReply::MAX_PER_USER.' quick replies.');
        $reply = $user->quickReplies()->create($this->validateQuickReply($request));

        return $request->expectsJson()
            ? response()->json($reply->toPayload(), 201)
            : redirect()->route('profile.edit', ['tab' => 'business'])->with('status', 'Quick reply saved.');
    }

    public function updateQuickReply(Request $request, QuickReply $quickReply): RedirectResponse|JsonResponse
    {
        abort_unless((int) $quickReply->user_id === (int) $request->user()->getKey(), 404);
        $quickReply->update($this->validateQuickReply($request, $quickReply));

        return $request->expectsJson()
            ? response()->json($quickReply->toPayload())
            : redirect()->route('profile.edit', ['tab' => 'business'])->with('status', 'Quick reply saved.');
    }

    public function destroyQuickReply(Request $request, QuickReply $quickReply): RedirectResponse|JsonResponse
    {
        abort_unless((int) $quickReply->user_id === (int) $request->user()->getKey(), 404);
        $quickReply->delete();

        return $request->expectsJson()
            ? response()->json(['id' => $quickReply->id])
            : redirect()->route('profile.edit', ['tab' => 'business'])->with('status', 'Quick reply deleted.');
    }

    /** @return array{shortcut: string, message: string} */
    private function validateQuickReply(Request $request, ?QuickReply $reply = null): array
    {
        $request->merge(['shortcut' => strtolower(ltrim(trim((string) $request->input('shortcut')), '/'))]);

        return $request->validateWithBag('quickReply', [
            'shortcut' => ['required', 'string', 'max:24', 'regex:/^[a-z0-9_-]+$/', Rule::unique('quick_replies', 'shortcut')->where('user_id', $request->user()->getKey())->ignore($reply?->id)],
            'message' => ['required', 'string', 'max:1000'],
        ], [
            'shortcut.regex' => 'Use letters, numbers, - or _ (no spaces).',
            'shortcut.unique' => 'You already have a quick reply with this shortcut.',
        ]);
    }
}
