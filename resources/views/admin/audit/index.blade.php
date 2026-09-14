<x-layouts.admin title="Audit log" heading="Audit log" subheading="Everything administrators did: opened chats, searches, bans, deletions and settings.">
    <form method="GET" action="{{ route('admin.audit') }}" class="admin-filters">
        @if ($filters['target'] ?? null)
            <input type="hidden" name="target" value="{{ $filters['target'] }}">
        @endif
        <select name="action" class="form-control admin-select" aria-label="Action">
            <option value="">All actions</option>
            @foreach (\App\Models\AdminAuditLog::ACTIONS as $action => $label)
                <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="admin" class="form-control admin-select" aria-label="Administrator">
            <option value="">All administrators</option>
            @foreach ($admins as $admin)
                <option value="{{ $admin->id }}" @selected((int) ($filters['admin'] ?? 0) === $admin->id)>{{ $admin->name }}</option>
            @endforeach
        </select>
        <div class="admin-filter-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="search" /> Filter</button>
            @if (array_filter($filters))
                <a href="{{ route('admin.audit') }}" class="btn btn-ghost">Reset</a>
            @endif
        </div>
    </form>

    <div class="card">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>What</th>
                        <th class="hidden md:table-cell">Administrator</th>
                        <th class="hidden lg:table-cell">Network</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap text-sm">
                                <span class="block">{{ $log->created_at->format('j M Y') }}</span>
                                <span class="block text-xs text-muted">{{ $log->created_at->format('g:i:s A') }}</span>
                            </td>
                            <td class="admin-audit-what">
                                <span class="badge badge-muted">{{ $log->label() }}</span>
                                <span class="block mt-1">
                                    @if ($log->target_type === 'Conversation' && $log->target_id)
                                        <a href="{{ route('admin.chats.show', $log->target_id) }}" class="admin-link">{{ $log->description }}</a>
                                    @elseif ($log->target_type === 'User' && $log->target_id && $log->action !== 'user.deleted')
                                        <a href="{{ route('admin.users.show', $log->target_id) }}" class="admin-link">{{ $log->description }}</a>
                                    @else
                                        {{ $log->description }}
                                    @endif
                                </span>
                                @if (! empty($log->meta['reason']))
                                    <span class="block text-xs text-muted">Reason: {{ $log->meta['reason'] }}</span>
                                @endif
                            </td>
                            <td class="hidden md:table-cell text-sm">{{ $log->admin?->name ?? 'Deleted admin' }}</td>
                            <td class="hidden lg:table-cell text-xs text-muted tabular-nums">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4"><div class="empty-state"><div class="empty-state-icon"><x-icon name="scroll-text" /></div><div class="empty-state-title">Nothing recorded yet</div></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pager :items="$logs" label="entries" />
    </div>
</x-layouts.admin>
