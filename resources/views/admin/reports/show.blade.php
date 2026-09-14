<x-layouts.admin title="Report" heading="Report" subheading="Review what was reported and decide what to do.">
    <a href="{{ route('admin.reports', ['status' => $report->status]) }}" class="inline-flex items-center gap-1 text-sm text-muted mb-4 hover:text-ink"><x-icon name="arrow-left" class="icon-sm" /> All reports</a>

    <div class="admin-grid">
        <section class="card">
            @if ($report->reportedUser)
                <div class="card-body flex flex-col items-center text-center gap-2">
                    <x-avatar :user="$report->reportedUser" size="xl" />
                    <h2 class="text-xl font-bold mt-2">{{ $report->reportedUser->name }}</h2>
                    <p class="text-muted">{{ '@'.$report->reportedUser->username }}</p>
                    <div class="flex flex-wrap justify-center gap-1.5"><x-admin.status-badge :user="$report->reportedUser" /></div>
                    <div class="mt-3 flex flex-wrap justify-center gap-2">
                        @if ($report->conversation_id)
                            <a href="{{ route('admin.chats.show', $report->conversation_id) }}" class="btn btn-primary btn-sm"><x-icon name="message-circle" /> Open the chat</a>
                        @endif
                        <x-admin.user-actions :user="$report->reportedUser" />
                    </div>
                    <a href="{{ route('admin.users.show', $report->reportedUser) }}" class="text-sm text-primary mt-1">View account</a>
                </div>
            @endif
            <div class="card-footer justify-start! flex-col items-stretch! gap-0!">
                @foreach ([
                    ['circle-alert', 'Reason', $report->reasonLabel()],
                    ['user', 'Reported by', $report->reporter?->name ?? 'Deleted account'],
                    ['clock', 'Reported', $report->created_at->format('M j, Y g:i A')],
                    ['ban', 'Reporter blocked them', $report->blocked ? 'Yes' : 'No'],
                    ['list-checks', 'Other reports about them', number_format($otherReports)],
                ] as [$icon, $label, $value])
                    <div class="detail-row">
                        <span class="flex items-center gap-2 text-muted text-sm"><x-icon :name="$icon" class="icon-sm" /> {{ $label }}</span>
                        <span class="text-sm font-medium text-right break-all">{{ $value }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="card-header">
                <h2 class="card-title">What they reported</h2>
                <p class="card-subtitle">Only the messages the reporter chose to send with the report are shown.</p>
            </div>
            <div class="card-body flex flex-col gap-4">
                @if ($report->details)
                    <div>
                        <div class="text-sm font-semibold mb-1">Reporter's note</div>
                        <p class="report-details">{{ $report->details }}</p>
                    </div>
                @endif

                <div>
                    <div class="text-sm font-semibold mb-2">Last messages from {{ $report->reportedUser?->name ?? 'them' }}</div>
                    @forelse ($report->evidence ?? [] as $item)
                        <div class="report-evidence">
                            <p>{{ $item['preview'] }}</p>
                            <time class="text-xs text-muted">{{ isset($item['created_at']) ? \Illuminate\Support\Carbon::parse($item['created_at'])->format('M j, g:i A') : '' }}</time>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No messages were attached.</p>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('admin.reports.update', $report) }}" class="flex flex-col gap-3">
                    @csrf
                    @method('PATCH')
                    <label class="form-label" for="admin-note">Note for other admins</label>
                    <textarea id="admin-note" name="admin_note" class="form-control" rows="3" maxlength="2000" placeholder="What you checked or did">{{ $report->admin_note }}</textarea>
                    @if ($report->reviewed_at)
                        <p class="text-xs text-muted">{{ ucfirst($report->status) }} by {{ $report->reviewer?->name ?? 'an admin' }} · {{ $report->reviewed_at->format('M j, Y g:i A') }}</p>
                    @endif
                    <div class="flex flex-wrap gap-2 justify-end">
                        @if ($report->status !== 'open')
                            <button type="submit" name="status" value="open" class="btn btn-secondary">Reopen</button>
                        @endif
                        <button type="submit" name="status" value="dismissed" class="btn btn-secondary">Dismiss</button>
                        <button type="submit" name="status" value="reviewed" class="btn btn-primary"><x-icon name="check" /> Mark reviewed</button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</x-layouts.admin>
