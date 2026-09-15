@php
    $statusBadge = ['open' => 'badge-warning', 'reviewed' => 'badge-success', 'dismissed' => 'badge-muted'];
@endphp
<div class="admin-grid admin-grid-even">
    @foreach ([['Reports about '.$user->name, $reportsAgainst, 'reporter', 'Reported by'], ['Reports '.$user->name.' made', $reportsMade, 'reportedUser', 'About']] as [$title, $reports, $relation, $who])
        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">{{ $title }}</h2>
                    <p class="card-subtitle">{{ number_format($reports->total()) }} {{ str('report')->plural($reports->total()) }}</p>
                </div>
            </div>
            <div class="admin-list">
                @forelse ($reports as $report)
                    @php($person = $report->{$relation})
                    <a href="{{ route('admin.reports.show', $report) }}" class="admin-list-row">
                        @if ($person)
                            <x-avatar :user="$person" size="sm" />
                        @endif
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ $report->reasonLabel() }}</span>
                            <span class="admin-list-text">{{ $who }} {{ $person?->name ?? 'a deleted account' }} · {{ $report->created_at->format('j M Y') }}{{ $report->blocked ? ' · blocked' : '' }}</span>
                        </span>
                        <span class="badge {{ $statusBadge[$report->status] ?? 'badge-muted' }}">{{ ucfirst($report->status) }}</span>
                    </a>
                @empty
                    <p class="admin-muted p-5">None.</p>
                @endforelse
            </div>
            <x-admin.pager :items="$reports" label="reports" />
        </section>
    @endforeach
</div>
