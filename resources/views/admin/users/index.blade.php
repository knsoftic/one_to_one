<x-layouts.admin title="Users" :heading="($filters['status'] ?? null) === 'banned' ? 'Banned users' : 'Users'" subheading="Search, review, ban and manage accounts.">
    <form method="GET" action="{{ route('admin.users') }}" class="admin-filters">
        <div class="input-wrap admin-search">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Name, username, email or mobile" maxlength="100" aria-label="Search users">
        </div>
        <select name="status" class="form-control admin-select" aria-label="Status">
            <option value="">All statuses</option>
            @foreach (\App\Models\User::STATUSES as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <select name="role" class="form-control admin-select" aria-label="Role">
            <option value="">All roles</option>
            <option value="user" @selected(($filters['role'] ?? '') === 'user')>Users</option>
            <option value="admin" @selected(($filters['role'] ?? '') === 'admin')>Admins</option>
        </select>
        <label class="checkbox">
            <input type="checkbox" name="online" value="1" @checked(($filters['online'] ?? null) === '1')> Online only
        </label>
        <div class="admin-filter-actions">
            <button type="submit" class="btn btn-primary"><x-icon name="search" /> Filter</button>
            @if (array_filter($filters))
                <a href="{{ route('admin.users') }}" class="btn btn-ghost">Reset</a>
            @endif
        </div>
    </form>

    <div class="card">
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th class="hidden md:table-cell">Contact</th>
                        <th>Status</th>
                        <th class="text-right hidden lg:table-cell">Messages</th>
                        <th class="hidden md:table-cell">Last seen</th>
                        <th class="hidden xl:table-cell">Joined</th>
                        <th class="text-right"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td>
                                <a href="{{ route('admin.users.show', $user) }}" class="admin-person">
                                    <x-avatar :user="$user" size="sm" status />
                                    <span class="min-w-0">
                                        <span class="admin-person-name">{{ $user->name }}</span>
                                        <span class="admin-person-meta">{{ '@'.$user->username }}</span>
                                    </span>
                                </a>
                            </td>
                            <td class="hidden md:table-cell">
                                <span class="block text-sm truncate">{{ $user->email }}</span>
                                <span class="block text-xs text-muted tabular-nums">{{ $user->phone }}</span>
                            </td>
                            <td>
                                <div class="admin-badges">
                                    <x-admin.status-badge :user="$user" />
                                    @if ($user->blocked_by_records_count)
                                        <span class="badge badge-muted" title="Blocked by {{ $user->blocked_by_records_count }} user(s)"><x-icon name="ban" class="icon-xs" /> {{ $user->blocked_by_records_count }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-right tabular-nums hidden lg:table-cell">{{ number_format($user->sent_messages_count) }}</td>
                            <td class="whitespace-nowrap text-sm hidden md:table-cell">
                                @if ($user->isOnlineNow())
                                    <span class="text-success font-semibold">Online</span>
                                @else
                                    <span class="text-muted">{{ $user->last_seen?->diffForHumans() ?? 'Never' }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap text-sm text-muted hidden xl:table-cell">{{ $user->created_at->format('M j, Y') }}</td>
                            <td class="text-right"><x-admin.user-actions :user="$user" compact /></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <div class="empty-state-icon"><x-icon name="users" /></div>
                                    <div class="empty-state-title">No users found</div>
                                    <div class="empty-state-text">Try a different search or filter.</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pager :items="$users" label="users" />
    </div>
</x-layouts.admin>
