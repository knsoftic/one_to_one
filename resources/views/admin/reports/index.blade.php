<x-layouts.admin title="Reports" heading="Reports" subheading="People reported by users for spam or abuse.">
    <div class="tabs mb-5" role="tablist" aria-label="Report status">
        @foreach (['open' => 'Open', 'reviewed' => 'Reviewed', 'dismissed' => 'Dismissed'] as $key => $label)
            <a href="{{ route('admin.reports', ['status' => $key]) }}" role="tab" aria-selected="{{ $status === $key ? 'true' : 'false' }}" @class(['tab', 'is-active' => $status === $key])>
                {{ $label }} <span class="badge badge-muted">{{ number_format($counts[$key] ?? 0) }}</span>
            </a>
        @endforeach
    </div>

    <div class="card">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Reported</th>
                        <th>Reason</th>
                        <th class="hidden md:table-cell">Reported by</th>
                        <th class="hidden lg:table-cell">When</th>
                        <th class="text-right"><span class="sr-only">Open</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($reports as $report)
                        <tr>
                            <td>
                                @if ($report->reportedUser)
                                    <a href="{{ route('admin.users.show', $report->reportedUser) }}" class="flex items-center gap-3 min-w-0">
                                        <x-avatar :user="$report->reportedUser" size="sm" />
                                        <span class="min-w-0">
                                            <span class="block font-semibold truncate">{{ $report->reportedUser->name }}</span>
                                            <span class="block text-xs text-muted truncate">{{ '@'.$report->reportedUser->username }}</span>
                                        </span>
                                    </a>
                                @endif
                            </td>
                            <td>
                                <span class="badge badge-danger">{{ $report->reasonLabel() }}</span>
                                @if ($report->blocked)
                                    <span class="badge badge-muted" title="The reporter also blocked them"><x-icon name="ban" class="icon-xs" /> Blocked</span>
                                @endif
                            </td>
                            <td class="text-sm hidden md:table-cell">{{ $report->reporter?->name }}</td>
                            <td class="text-sm text-muted whitespace-nowrap hidden lg:table-cell">{{ $report->created_at->diffForHumans() }}</td>
                            <td class="text-right"><a href="{{ route('admin.reports.show', $report) }}" class="btn btn-secondary btn-sm">Review</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <div class="empty-state-icon"><x-icon name="shield-check" /></div>
                                    <div class="empty-state-title">No {{ $status }} reports</div>
                                    <div class="empty-state-text">Reports people send appear here.</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($reports->hasPages())
            <div class="card-footer justify-between!">
                <span class="text-sm text-muted">Showing {{ $reports->firstItem() }}–{{ $reports->lastItem() }} of {{ $reports->total() }}</span>
                <div class="flex gap-2">
                    @if ($reports->previousPageUrl())<a href="{{ $reports->previousPageUrl() }}" class="btn btn-secondary btn-sm">Previous</a>@endif
                    @if ($reports->nextPageUrl())<a href="{{ $reports->nextPageUrl() }}" class="btn btn-secondary btn-sm">Next</a>@endif
                </div>
            </div>
        @endif
    </div>
</x-layouts.admin>
