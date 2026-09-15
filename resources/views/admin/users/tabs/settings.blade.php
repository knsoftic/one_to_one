@php
    $kinds = ['photos' => 'Photos', 'videos' => 'Videos', 'documents' => 'Documents', 'voice' => 'Voice messages', 'gifs' => 'GIFs and stickers'];
    $autoDownload = \App\Support\ChatPreferences::autoDownload($user->auto_download);
    $downloadLabels = ['photos' => 'photos', 'gifs' => 'GIFs', 'videos' => 'videos'];
    $list = fn (array $items) => $items ? implode(', ', array_map(fn ($k) => $downloadLabels[$k] ?? $k, $items)) : 'nothing';
@endphp
<div class="admin-grid admin-grid-even">
    <section class="card">
        <div class="card-body">
            <h3 class="admin-section-title"><x-icon name="lock" /> Privacy and security</h3>
            <dl class="admin-details">
                <div><dt>Last seen</dt><dd>{{ ucfirst($user->last_seen_privacy) }}</dd></div>
                <div><dt>Online</dt><dd>{{ ucfirst(str_replace('_', ' ', (string) $user->online_privacy)) }}</dd></div>
                <div><dt>Profile photo</dt><dd>{{ ucfirst($user->photo_privacy) }}</dd></div>
                <div><dt>About</dt><dd>{{ ucfirst($user->about_privacy) }}</dd></div>
                <div><dt>Status</dt><dd>{{ ucfirst(str_replace('_', ' ', (string) ($user->status_privacy ?? 'contacts'))) }}</dd></div>
                <div><dt>Read receipts</dt><dd>{{ $user->read_receipts ? 'On' : 'Off' }}</dd></div>
                <div><dt>Two-step verification</dt><dd>{{ $twoStep ? 'On' : 'Off' }}</dd></div>
                <div><dt>Chat lock code</dt><dd>{{ $user->chat_lock_pin ? 'Set' : 'Not set' }}</dd></div>
                <div><dt>Email / mobile</dt><dd>{{ $user->email_verified_at ? 'Email verified' : 'Email not verified' }} · {{ $user->phone_verified_at ? 'mobile verified by SMS' : 'mobile not verified' }}</dd></div>
            </dl>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <h3 class="admin-section-title"><x-icon name="bell" /> Notifications and chats</h3>
            <dl class="admin-details">
                <div><dt>Notifications</dt><dd>{{ $user->notifications_enabled ? 'On' : 'Off' }} · sound {{ $user->notification_sound ? 'on' : 'off' }}</dd></div>
                <div><dt>Tone / vibration</dt><dd>{{ ucfirst((string) $user->notification_tone) }} · {{ ucfirst((string) $user->notification_vibrate) }}</dd></div>
                <div><dt>Theme / font</dt><dd>{{ ucfirst((string) $user->theme) }} · {{ ucfirst((string) $user->font_size) }} text</dd></div>
                <div><dt>Wallpaper</dt><dd>{{ $user->wallpaper ? ucfirst($user->wallpaper) : 'Default' }}</dd></div>
                <div><dt>Auto-download</dt><dd>Wi-Fi: {{ $list($autoDownload['wifi']) }} · mobile data: {{ $list($autoDownload['mobile']) }}</dd></div>
                <div><dt>Chats</dt><dd>{{ $chatSettings['pinned'] ?? 0 }} pinned · {{ $chatSettings['archived'] ?? 0 }} archived · {{ $chatSettings['muted'] ?? 0 }} muted · {{ $chatSettings['locked'] ?? 0 }} locked</dd></div>
                <div><dt>Per-chat choices</dt><dd>{{ $chatSettings['wallpapers'] ?? 0 }} wallpapers · {{ $chatSettings['tones'] ?? 0 }} tones · {{ $chatLists }} chat lists</dd></div>
            </dl>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <h3 class="admin-section-title"><x-icon name="hard-drive" /> Storage in their chats</h3>
            <p class="admin-storage-total"><strong>{{ $bytes($storage['total']['bytes']) }}</strong> in {{ number_format($storage['total']['files']) }} files they can see</p>
            <dl class="admin-details">
                @foreach ($kinds as $key => $label)
                    <div><dt>{{ $label }}</dt><dd class="tabular-nums">{{ number_format($storage['kinds'][$key]['files'] ?? 0) }} · {{ $bytes($storage['kinds'][$key]['bytes'] ?? 0) }}</dd></div>
                @endforeach
                <div><dt>Larger than 5 MB</dt><dd class="tabular-nums">{{ number_format($storage['large']['files']) }} · {{ $bytes($storage['large']['bytes']) }}</dd></div>
            </dl>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <h3 class="admin-section-title"><x-icon name="archive" /> Chat backup</h3>
            @if ($backup)
                <dl class="admin-details">
                    <div><dt>Status</dt><dd>{{ ucfirst($backup->toPayload()['status']) }}</dd></div>
                    <div><dt>Made</dt><dd>{{ ($backup->finished_at ?? $backup->created_at)->format('j M Y, g:i A') }}</dd></div>
                    <div><dt>Size</dt><dd>{{ $bytes($backup->size) }} · {{ $backup->include_media ? 'with media' : 'text only' }}</dd></div>
                    <div><dt>Chats</dt><dd>{{ $backup->stats['chats'] ?? 0 }} chats · {{ number_format($backup->stats['messages'] ?? 0) }} messages</dd></div>
                </dl>
            @else
                <p class="admin-muted">No backup made.</p>
            @endif
        </div>
    </section>
</div>
