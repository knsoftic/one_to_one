{{-- Notifications --}}
<div class="wa-group">
    <h3 class="wa-group-title">Messages</h3>
    <label class="wa-row">
        <x-icon name="bell" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">New message notifications</span>
            <span class="wa-row-text">Show in-app and desktop alerts when someone messages you.</span>
        </span>
        <span class="switch">
            <input type="checkbox" data-preference="notifications_enabled" @checked($user->notifications_enabled) aria-label="New message notifications">
            <span class="switch-track"></span>
        </span>
    </label>
    <label class="wa-row">
        <x-icon name="volume-2" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Notification sound</span>
            <span class="wa-row-text">Play a short sound for incoming messages.</span>
        </span>
        <span class="switch">
            <input type="checkbox" data-preference="notification_sound" @checked($user->notification_sound) aria-label="Notification sound">
            <span class="switch-track"></span>
        </span>
    </label>
    @php($tones = ['default' => 'Default', 'chime' => 'Chime', 'bell' => 'Bell', 'pop' => 'Pop', 'chirp' => 'Chirp', 'marimba' => 'Marimba', 'pulse' => 'Pulse', 'glass' => 'Glass'])
    @php($vibrations = ['default' => 'Default', 'short' => 'Short', 'long' => 'Long', 'off' => 'Off'])
    <label class="wa-row wa-choice">
        <x-icon name="music" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Notification tone</span>
            <span class="wa-row-text" data-choice-label>{{ $tones[$user->notification_tone] ?? 'Default' }}</span>
        </span>
        <select class="wa-choice-select" data-preference="notification_tone" aria-label="Notification tone">
            @foreach ($tones as $value => $label)
                <option value="{{ $value }}" @selected(($user->notification_tone ?? 'default') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <label class="wa-row wa-choice">
        <x-icon name="vibrate" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Vibration</span>
            <span class="wa-row-text" data-choice-label>{{ $vibrations[$user->notification_vibrate] ?? 'Default' }}</span>
        </span>
        <select class="wa-choice-select" data-preference="notification_vibrate" aria-label="Vibration">
            @foreach ($vibrations as $value => $label)
                <option value="{{ $value }}" @selected(($user->notification_vibrate ?? 'default') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <p class="wa-group-note">"Default" is the app's chime in the browser and your phone's notification sound in the Android app. A chat can have its own tone: open the chat menu → Notification tone.</p>
</div>

<div class="wa-group">
    <h3 class="wa-group-title">This browser</h3>
    <button type="button" class="wa-row is-link" data-install-app hidden>
        <x-icon name="app-window" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Install {{ config('app.name') }}</span>
            <span class="wa-row-text">Open it like an app, in its own window, from your desktop or home screen.</span>
        </span>
        <x-icon name="chevron-right" class="wa-row-chevron" />
    </button>
    <div class="wa-row">
        <x-icon name="monitor" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Notifications on this browser</span>
            <span class="wa-row-text" data-browser-permission-text>Get notified of messages and calls even when this site is closed.</span>
        </span>
        <button type="button" class="btn btn-primary btn-sm" data-request-browser-notifications>
            <x-icon name="bell" /> <span data-browser-notifications-label>Turn on</span>
        </button>
    </div>
</div>
