@php
    $moreFilters = array_intersect_key(array_filter($filters), array_flip(['joined', 'inactive', 'app', 'two_step', 'contains', 'role']));
@endphp
<x-layouts.admin title="Users" :heading="($filters['status'] ?? null) === 'banned' ? 'Banned users' : 'Users'" subheading="Search, review, ban and manage accounts — one at a time or many at once.">
    <form method="GET" action="{{ route('admin.users') }}" class="admin-filters admin-user-filters">
        <div class="input-wrap admin-search">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Name, username, email, mobile or ID" maxlength="100" aria-label="Search users">
        </div>
        <select name="status" class="form-control admin-select" aria-label="Status">
            <option value="">All statuses</option>
            @foreach (\App\Models\User::STATUSES as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <select name="sort" class="form-control admin-select" aria-label="Sort">
            @foreach (\App\Services\AdminService::USER_SORTS as $value => $label)
                <option value="{{ $value }}" @selected(($filters['sort'] ?? 'newest') === $value)>{{ $label }}</option>
            @endforeach
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

        <details class="admin-more-filters" @if ($moreFilters) open @endif>
            <summary><x-icon name="sliders-horizontal" /> More filters @if ($moreFilters)<span class="badge badge-soft">{{ count($moreFilters) }}</span>@endif</summary>
            <div class="admin-more-grid">
                <label class="form-group">
                    <span class="form-label">Role</span>
                    <select name="role" class="form-control">
                        <option value="">All roles</option>
                        <option value="user" @selected(($filters['role'] ?? '') === 'user')>Users</option>
                        <option value="admin" @selected(($filters['role'] ?? '') === 'admin')>Admins</option>
                    </select>
                </label>
                <label class="form-group">
                    <span class="form-label">Joined</span>
                    <select name="joined" class="form-control">
                        <option value="">Any time</option>
                        @foreach (['1' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['joined'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="form-group">
                    <span class="form-label">Not seen for</span>
                    <select name="inactive" class="form-control">
                        <option value="">—</option>
                        @foreach (['30' => '30 days or more', '90' => '90 days or more', '180' => '180 days or more'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['inactive'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="form-group">
                    <span class="form-label">Per page</span>
                    <select name="per_page" class="form-control">
                        @foreach (\App\Services\AdminService::PER_PAGE as $size)
                            <option value="{{ $size }}" @selected((int) ($filters['per_page'] ?? 15) === $size)>{{ $size }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="checkbox"><input type="checkbox" name="app" value="1" @checked(($filters['app'] ?? null) === '1')> Uses the Android app</label>
                <label class="checkbox"><input type="checkbox" name="two_step" value="1" @checked(($filters['two_step'] ?? null) === '1')> Two-step verification on</label>
                <label class="checkbox"><input type="checkbox" name="contains" value="1" @checked(($filters['contains'] ?? null) === '1')> Search inside names and numbers (slower)</label>
            </div>
        </details>
    </form>

    @if ($prefixSearch)
        <p class="admin-hint"><x-icon name="info" /> Many accounts: showing names, usernames and emails that <strong>start with</strong> “{{ $filters['q'] }}”. Tick “Search inside names and numbers” under More filters to search anywhere in them.</p>
    @endif
    @error('ids', 'bulk')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
    @error('reason', 'bulk')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror

    <div class="card" data-admin-bulk>
            <form method="POST" action="{{ route('admin.users.bulk') }}" id="bulk-form" class="admin-bulk-bar" data-bulk-bar>
                @csrf
                <label class="checkbox admin-bulk-all">
                    <input type="checkbox" data-bulk-all aria-label="Select all on this page">
                    <span data-bulk-count>Select</span>
                </label>
                <div class="admin-bulk-controls" data-bulk-controls hidden>
                    <select name="action" class="form-control admin-select" data-bulk-action aria-label="Action">
                        <option value="logout">Sign out everywhere</option>
                        <option value="suspend">Suspend</option>
                        <option value="activate">Activate</option>
                        <option value="ban">Ban…</option>
                        <option value="unban">Lift ban</option>
                        <option value="delete">Delete permanently</option>
                    </select>
                    <span class="admin-bulk-ban" data-bulk-ban hidden>
                        <select name="duration" class="form-control admin-select" aria-label="Ban length">
                            @foreach (\App\Services\BanService::DURATIONS as $value => $label)
                                <option value="{{ $value }}" @selected((string) $value === '7')>{{ $label }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="reason" class="form-control" maxlength="500" placeholder="Reason people will see" aria-label="Ban reason">
                    </span>
                    <button type="submit" class="btn btn-primary btn-sm" data-bulk-submit>Apply</button>
                </div>
                <a href="{{ route('admin.users.export', request()->query()) }}" class="btn btn-secondary btn-sm admin-bulk-export"><x-icon name="file-down" /> Export CSV</a>
            </form>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th class="admin-check-col"><span class="sr-only">Select</span></th>
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
                                <td class="admin-check-col">
                                    @unless ($user->isAdmin() || auth()->id() === $user->id)
                                        <input type="checkbox" name="ids[]" value="{{ $user->id }}" form="bulk-form" data-bulk-item aria-label="Select {{ $user->name }}">
                                    @endunless
                                </td>
                                <td>
                                    <a href="{{ route('admin.users.show', $user) }}" class="admin-person">
                                        <x-avatar :user="$user" size="sm" status />
                                        <span class="min-w-0">
                                            <span class="admin-person-name">{{ $user->name }}</span>
                                            <span class="admin-person-meta">{{ '@'.$user->username }} · #{{ $user->id }}</span>
                                        </span>
                                    </a>
                                </td>
                                <td class="hidden md:table-cell">
                                    <span class="block text-sm truncate">{{ $user->email ?? '—' }}</span>
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
                                <td colspan="8">
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
