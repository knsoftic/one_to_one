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
</div>

<div class="wa-group">
    <h3 class="wa-group-title">This browser</h3>
    <div class="wa-row">
        <x-icon name="monitor" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Desktop notifications</span>
            <span class="wa-row-text" data-browser-permission-text>Allow your browser to show notifications while the app is in the background.</span>
        </span>
        <button type="button" class="btn btn-secondary btn-sm" data-request-browser-notifications>
            <x-icon name="bell" /> Enable
        </button>
    </div>
</div>
