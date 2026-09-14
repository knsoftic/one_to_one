<x-layouts.admin title="Status updates" heading="Status updates" :subheading="'Updates that are live right now (they disappear after '.config('chat.statuses.lifetime_hours', 24).' hours).'" :back="($filters['user'] ?? null) ? route('admin.users.show', $filters['user']) : null">
    @if ($filters['user'] ?? null)
        <p class="admin-muted mb-4">Showing one person's updates. <a href="{{ route('admin.statuses') }}" class="admin-link">Show everyone's</a></p>
    @endif

    <div class="admin-status-grid">
        @forelse ($statuses as $status)
            <article class="card admin-status">
                <div class="admin-status-preview" data-background="{{ $status->background ?? 'teal' }}">
                    @if ($status->type === 'text')
                        <p class="admin-status-text">{{ $status->body }}</p>
                    @elseif ($status->attachment)
                        @if (str_starts_with((string) $status->attachment_mime, 'video/'))
                            <video controls preload="none" src="{{ route('admin.statuses.media', $status) }}" @if (! empty($status->attachment_meta['thumbnail'])) poster="{{ route('admin.statuses.media', [$status, 'variant' => 'thumbnail']) }}" @endif></video>
                        @else
                            <a href="{{ route('admin.statuses.media', $status) }}" target="_blank" rel="noopener"><img src="{{ route('admin.statuses.media', [$status, 'variant' => 'thumbnail']) }}" alt="Status photo" loading="lazy"></a>
                        @endif
                    @endif
                </div>
                <div class="admin-status-body">
                    @if ($status->user)
                        <a href="{{ route('admin.users.show', $status->user) }}" class="admin-person">
                            <x-avatar :user="$status->user" size="sm" />
                            <span class="min-w-0">
                                <span class="admin-person-name">{{ $status->user->name }}</span>
                                <span class="admin-person-meta">{{ $status->created_at?->diffForHumans() }} · {{ $status->views_count }} {{ str('view')->plural($status->views_count) }}</span>
                            </span>
                        </a>
                    @endif
                    @if ($status->type !== 'text' && $status->body)
                        <p class="admin-status-caption">{{ $status->body }}</p>
                    @endif
                    <div class="admin-status-foot">
                        <span class="badge badge-muted">{{ ucfirst($status->privacy ?? 'contacts') }}</span>
                        <form method="POST" action="{{ route('admin.statuses.destroy', $status) }}" data-confirm="This status update will be removed for everyone." data-confirm-title="Delete this update?" data-confirm-label="Delete" data-confirm-danger>
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger-soft btn-sm"><x-icon name="trash-2" /> Delete</button>
                        </form>
                    </div>
                </div>
            </article>
        @empty
            <div class="card admin-status-empty">
                <div class="empty-state">
                    <div class="empty-state-icon"><x-icon name="circle-dashed" /></div>
                    <div class="empty-state-title">No live status updates</div>
                </div>
            </div>
        @endforelse
    </div>
    <div class="card mt-4"><x-admin.pager :items="$statuses" label="updates" /></div>
</x-layouts.admin>
