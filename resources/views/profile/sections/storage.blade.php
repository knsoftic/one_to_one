{{-- Storage and data: manage storage (D6), media auto-download (D5) --}}
<div class="wa-group" data-storage-manager>
    <h3 class="wa-group-title">Manage storage</h3>
    <div data-storage-body>
        <div class="storage-loading"><span class="spinner"></span></div>
    </div>
    <p class="wa-group-note">Deleting here is "delete for me": the file leaves your chats on all your devices, and other people in the chat keep their copy.</p>
</div>

@php($autoDownload = \App\Support\ChatPreferences::autoDownload($user->auto_download))
@php($downloadKinds = ['photos' => 'Photos', 'gifs' => 'GIFs and stickers', 'videos' => 'Video previews'])

<div class="wa-group">
    <h3 class="wa-group-title">Media auto-download</h3>
    @foreach (['wifi' => ['When connected on Wi-Fi', 'wifi'], 'mobile' => ['When using mobile data', 'signal']] as $network => [$networkTitle, $networkIcon])
        <fieldset class="wa-row wa-auto-download" data-auto-download-group="{{ $network }}">
            <x-icon :name="$networkIcon" class="wa-row-icon" />
            <span class="wa-row-body">
                <legend class="wa-row-title">{{ $networkTitle }}</legend>
                <span class="wa-check-list">
                    @foreach ($downloadKinds as $kind => $kindLabel)
                        <label class="wa-check">
                            <input type="checkbox" data-auto-download="{{ $network }}" value="{{ $kind }}" @checked(in_array($kind, $autoDownload[$network], true))>
                            <span>{{ $kindLabel }}</span>
                        </label>
                    @endforeach
                </span>
            </span>
        </fieldset>
    @endforeach
    <p class="wa-group-note">Anything not ticked shows its size and a download button instead. Voice messages and documents only download when you open them. With the browser's Data Saver on, the mobile data choice is used.</p>
</div>

{{-- Chat backup (D8) --}}
@php($backups = app(\App\Services\BackupService::class))
@php($lastBackup = $backups->latestFor($user))
<div class="wa-group" data-chat-backup data-backup-available="{{ $backups->available() ? '1' : '0' }}" data-backup-current='@json($lastBackup?->toPayload())'>
    <h3 class="wa-group-title">Chat backup</h3>
    <div class="wa-row backup-status">
        <x-icon name="cloud-download" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Last backup</span>
            <span class="wa-row-text" data-backup-text>{{ $lastBackup ? 'Loading…' : 'No backup yet' }}</span>
        </span>
        <a href="#" class="btn btn-secondary btn-sm" data-backup-download hidden><x-icon name="download" /> Download</a>
    </div>
    <label class="wa-row">
        <x-icon name="images" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Include photos and videos</span>
            <span class="wa-row-text">Also voice messages and documents. The file gets much bigger.</span>
        </span>
        <span class="switch">
            <input type="checkbox" data-backup-media @checked($lastBackup?->include_media) aria-label="Include photos and videos">
            <span class="switch-track"></span>
        </span>
    </label>
    <div class="wa-row backup-actions">
        <button type="button" class="btn btn-primary" data-backup-start @disabled(! $backups->available())><x-icon name="archive" /> Back up now</button>
    </div>
    <p class="wa-group-note">Your chats are kept on the server: on a new phone, sign in with the same number and everything comes back by itself. A backup is a ZIP copy of your chats for you to keep. It can be downloaded for {{ max(1, (int) config('chat.backups.keep_days', 7)) }} days, and a new backup replaces the old one.</p>
</div>
