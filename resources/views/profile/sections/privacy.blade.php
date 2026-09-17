{{-- Privacy (Phase 6) --}}
@php
    $privacyOptions = ['everyone' => 'Everyone', 'contacts' => 'My contacts', 'nobody' => 'Nobody'];
    $choices = [
        ['key' => 'last_seen_privacy', 'title' => 'Last seen', 'label' => 'Who can see my last seen', 'options' => $privacyOptions],
        ['key' => 'online_privacy', 'title' => 'Online', 'label' => 'Who can see when I\'m online', 'options' => ['everyone' => 'Everyone', 'same' => 'Same as last seen']],
        ['key' => 'photo_privacy', 'title' => 'Profile photo', 'label' => 'Who can see my profile photo', 'options' => $privacyOptions],
        ['key' => 'about_privacy', 'title' => 'About', 'label' => 'Who can see my About', 'options' => $privacyOptions],
    ];
@endphp

<div class="wa-group">
    <h3 class="wa-group-title">Who can see my personal info</h3>
    @foreach ($choices as $choice)
        <label class="wa-row wa-choice">
            <span class="wa-row-body">
                <span class="wa-row-title">{{ $choice['title'] }}</span>
                <span class="wa-row-text" data-choice-label>{{ $choice['options'][$user->{$choice['key']}] ?? 'Everyone' }}</span>
            </span>
            <select class="wa-choice-select" data-preference="{{ $choice['key'] }}" aria-label="{{ $choice['label'] }}">
                @foreach ($choice['options'] as $value => $optionLabel)
                    <option value="{{ $value }}" @selected($user->{$choice['key']} === $value)>{{ $optionLabel }}</option>
                @endforeach
            </select>
        </label>
    @endforeach
    <p class="wa-group-note">"My contacts" means people you saved and people you chat with. If you don't share your last seen with anyone, you won't see other people's.</p>
</div>

<div class="wa-group">
    <label class="wa-row">
        <span class="wa-row-body">
            <span class="wa-row-title">Read receipts</span>
            <span class="wa-row-text">If turned off, you won't send or receive blue ticks in one-to-one chats. Group chats always show them.</span>
        </span>
        <span class="switch">
            <input type="checkbox" data-preference="read_receipts" @checked($user->read_receipts) aria-label="Read receipts">
            <span class="switch-track"></span>
        </span>
    </label>
</div>

<div class="wa-group">
    <h3 class="wa-group-title">Status and contacts</h3>
    <div class="wa-row">
        <span class="wa-row-body">
            <span class="wa-row-title">Status</span>
            <span class="wa-row-text">Choose who sees your status updates from Updates → the lock button in the chats.</span>
        </span>
    </div>
    <button type="button" class="wa-row is-link" data-settings-open="blocked">
        <span class="wa-row-body">
            <span class="wa-row-title">Blocked contacts</span>
            <span class="wa-row-text">{{ $blockedUsers->count() === 1 ? '1 person' : $blockedUsers->count().' people' }}</span>
        </span>
        <x-icon name="chevron-right" class="wa-row-chevron" />
    </button>
</div>

<div class="wa-group">
    <h3 class="wa-group-title">Your messages</h3>
    <div class="wa-row">
        <x-icon name="eye" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Who can read chats</span>
            <span class="wa-row-text" data-admin-access-note>Chats on {{ config('app.name') }} aren't end-to-end encrypted. To keep the app safe, its administrators can open chats, messages and files — for example after a report. Every time they do, it is recorded.</span>
        </span>
    </div>
</div>

{{-- App lock (P8): shown only inside the Android app --}}
<div class="wa-group" data-app-lock-row hidden>
    <h3 class="wa-group-title">App lock</h3>
    <label class="wa-row">
        <span class="wa-row-body">
            <span class="wa-row-title">Unlock with fingerprint</span>
            <span class="wa-row-text" data-app-lock-text>Use your fingerprint, face or phone screen lock to open the app on this phone.</span>
        </span>
        <span class="switch">
            <input type="checkbox" data-app-lock-toggle aria-label="App lock">
            <span class="switch-track"></span>
        </span>
    </label>
    <div class="wa-row">
        <span class="wa-row-body">
            <span class="wa-row-title">Lock the app</span>
        </span>
        <select class="form-control setting-select" data-app-lock-timeout aria-label="Lock the app">
            <option value="0">Immediately</option>
            <option value="60">After 1 minute</option>
            <option value="1800">After 30 minutes</option>
        </select>
    </div>
</div>

@if ((bool) \App\Models\AppSetting::get('ads_enabled'))
    @php($adProfile = \App\Models\AdProfile::find($user->id))
    <div class="wa-group" data-ads-settings data-route-profile="{{ route('ads.profile') }}">
        <h3 class="wa-group-title">Ads</h3>
        <p class="wa-group-note">
            This app is free because it shows ads. To keep them relevant we use your country, what you do in the app,
            the device you are on, and the gender and date of birth you set in <a href="{{ route('profile.edit', ['tab' => 'profile']) }}">your profile</a>.
            Your area is used only while you allow this device's location permission. We never read your contacts,
            your messages or your exact position. See our <a href="{{ route('legal', 'privacy') }}" target="_blank" rel="noopener">privacy policy</a>.
        </p>

        <label class="wa-row">
            <span class="wa-row-body">
                <span class="wa-row-title">Use my area</span>
                <span class="wa-row-text">Roughly, to about a kilometre — never your exact spot. Turn it on and your phone asks for the location permission, the same way it does for contacts and the camera.</span>
            </span>
            <span class="switch">
                <input type="checkbox" data-ads-location @checked($adProfile?->location_allowed) aria-label="Use my area for ads">
                <span class="switch-track"></span>
            </span>
        </label>

        <details class="wa-group-note wa-ads-data">
            <summary>What we use to choose your ads</summary>
            <dl data-ads-data>
                @forelse (($adProfile?->summary($user) ?? []) as $label => $value)
                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                @empty
                    <p>Nothing yet.</p>
                @endforelse
            </dl>
            <p>Gender and date of birth are whatever you set in your profile — clear them there and they stop being used.</p>
        </details>
    </div>
@endif
