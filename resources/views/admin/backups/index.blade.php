@php
    $size = function (?int $bytes): string {
        if (! $bytes) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = min((int) floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $i), $i === 0 ? 0 : 1).' '.$units[$i];
    };
    $statusBadge = ['pending' => ['badge-warning', 'Waiting'], 'working' => ['badge-warning', 'Being made'], 'ready' => ['badge-success', 'Ready'], 'failed' => ['badge-danger', 'Failed']];
@endphp
<x-layouts.admin title="Backups" heading="Backups" subheading="The whole database and all uploaded files in one ZIP — to keep safe, move the app, or restore it.">
    <div class="admin-stack">
        <section class="card admin-tool">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="database" /> Make a server backup</h3>
                @unless ($available)
                    <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> The PHP <code>zip</code> extension is not installed on this server, so backups can't be made. Enable it in aaPanel → PHP → Install extensions.</p>
                @endunless
                <form method="POST" action="{{ route('admin.backups.store') }}" class="admin-backup-form" data-loading-form>
                    @csrf
                    <label class="admin-setting-row">
                        <span>
                            <strong>Include uploaded files</strong>
                            <small>Chat photos, videos, voice messages, documents, status updates, profile photos and wallpapers. Without them the backup has only the database.</small>
                        </span>
                        <span class="switch">
                            <input type="hidden" name="files" value="0">
                            <input type="checkbox" name="files" value="1" checked aria-label="Include uploaded files">
                            <span class="switch-track"></span>
                        </span>
                    </label>
                    <div class="admin-backup-actions">
                        <button type="submit" class="btn btn-primary" @disabled(! $available || $busy)>
                            <x-icon name="database" /> {{ $busy ? 'A backup is being made…' : 'Back up now' }}
                        </button>
                        @if ($busy)
                            <a href="{{ route('admin.backups') }}" class="btn btn-ghost"><x-icon name="refresh-cw" /> Refresh</a>
                        @endif
                    </div>
                </form>
                <p class="admin-backup-note"><x-icon name="shield" /> A backup holds everyone's private chats and password hashes. Downloads and deletions are written to the audit log. The newest {{ $keep }} backups are kept; older ones are removed automatically.</p>
            </div>
        </section>

        <section class="card">
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Made</th>
                            <th>Status</th>
                            <th class="hidden md:table-cell">Contents</th>
                            <th class="text-right">Size</th>
                            <th class="text-right"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($backups as $backup)
                            @php([$badgeClass, $badgeLabel] = $statusBadge[$backup->status] ?? ['badge-muted', ucfirst($backup->status)])
                            <tr>
                                <td class="whitespace-nowrap text-sm">
                                    <span class="block">{{ $backup->created_at->format('j M Y') }}</span>
                                    <span class="block text-xs text-muted">{{ $backup->created_at->format('g:i A') }} · {{ $backup->user?->name ?? 'Deleted admin' }}</span>
                                </td>
                                <td>
                                    <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                    @if ($backup->error)
                                        <span class="block text-xs text-danger mt-1">{{ $backup->error }}</span>
                                    @endif
                                </td>
                                <td class="hidden md:table-cell text-sm">
                                    Database{{ $backup->include_media ? ' and files' : ' only' }}
                                    @if ($backup->stats)
                                        <span class="block text-xs text-muted tabular-nums">
                                            {{ $backup->stats['tables'] ?? 0 }} tables{{ $backup->include_media ? ' · '.number_format($backup->stats['files'] ?? 0).' files' : '' }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-right text-sm tabular-nums whitespace-nowrap">{{ $size($backup->size) }}</td>
                                <td class="text-right whitespace-nowrap">
                                    <div class="admin-row-actions">
                                        @if ($backup->status === 'ready' && $backup->path)
                                            <a href="{{ route('admin.backups.download', $backup) }}" class="btn btn-secondary btn-sm"><x-icon name="download" /> Download</a>
                                        @endif
                                        @unless ($backup->status === 'working')
                                            <form method="POST" action="{{ route('admin.backups.destroy', $backup) }}" data-confirm="The backup file is deleted for good." data-confirm-title="Delete this backup?" data-confirm-label="Delete" data-confirm-danger>
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn-icon btn-icon-sm" aria-label="Delete backup" title="Delete"><x-icon name="trash-2" /></button>
                                            </form>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty-state"><div class="empty-state-icon"><x-icon name="database" /></div><div class="empty-state-title">No server backups yet</div><div class="empty-state-text">Make one before big changes or moving to a new server.</div></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card admin-tool">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="rotate-ccw" /> Restore on a new server</h3>
                <ol class="admin-steps">
                    <li>Install the app on the new server as usual (<code>docs/DEPLOYMENT-AAPANEL.md</code>) and create an empty database.</li>
                    <li>Put the <strong>same <code>APP_KEY</code></strong> in the new <code>.env</code>. Without it the passwords and keys saved in App settings can't be read and everyone has to sign in again.</li>
                    <li>Unzip the backup and import the database: <code>mysql -u DB_USER -p DB_NAME &lt; database.sql</code></li>
                    <li>Copy <code>files/chat/</code> into <code>storage/app/private/chat/</code> and <code>files/public/</code> into <code>storage/app/public/</code>.</li>
                    <li>Run <code>php artisan storage:link</code>, <code>php artisan migrate --force</code> and <code>php artisan optimize</code>.</li>
                </ol>
                <p class="admin-backup-note"><x-icon name="smartphone" /> People moving to a new phone don't need any of this: they sign in with their number and all their chats come back. They can also download their own chat backup in Settings → Storage and data ({{ $personal['ready'] }} ready now, {{ $size($personal['bytes']) }}; each is kept {{ $keepDays }} days).</p>
                <p class="admin-backup-note">Backups are made in the background by the queue worker, or within a minute by the scheduler (<code>php artisan schedule:run</code> cron) when no worker is running.</p>
            </div>
        </section>
    </div>
</x-layouts.admin>
