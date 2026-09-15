<section class="card">
    <div class="card-header admin-card-head">
        <div>
            <h2 class="card-title">Admin history</h2>
            <p class="card-subtitle">What administrators did to this account.</p>
        </div>
        <a href="{{ route('admin.audit', ['target' => $user->id]) }}" class="btn btn-secondary btn-sm">Audit log</a>
    </div>
    <div class="admin-list">
        @forelse ($logs as $log)
            <div class="admin-list-row">
                <span class="admin-log-icon" data-action="{{ \Illuminate\Support\Str::before($log->action, '.') }}"><x-icon name="scroll-text" /></span>
                <span class="admin-list-body">
                    <span class="admin-list-title">{{ $log->description }}</span>
                    <span class="admin-list-text">{{ $log->label() }} · {{ $log->admin?->name ?? 'Deleted admin' }} · {{ $log->created_at->format('j M Y, g:i A') }}@if (! empty($log->meta['reason'])) · Reason: {{ $log->meta['reason'] }}@endif</span>
                </span>
            </div>
        @empty
            <div class="empty-state">
                <div class="empty-state-icon"><x-icon name="history" /></div>
                <div class="empty-state-title">Nothing yet</div>
            </div>
        @endforelse
    </div>
    <x-admin.pager :items="$logs" label="entries" />
</section>
