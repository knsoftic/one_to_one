@props(['user', 'compact' => false])
@can('manage', $user)
    <div class="dropdown">
        <button type="button" @class(['btn btn-secondary btn-sm' => ! $compact, 'btn-icon btn-icon-sm' => $compact]) data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Actions for {{ $user->name }}">
            @if ($compact)
                <x-icon name="ellipsis-vertical" />
            @else
                <x-icon name="user-cog" /> Manage <x-icon name="chevron-down" class="icon-sm" />
            @endif
        </button>
        <div class="dropdown-menu" data-align="right" role="menu" hidden>
            <a href="{{ route('admin.users.show', $user) }}" class="dropdown-item" role="menuitem"><x-icon name="user-round" /> View profile</a>
            <a href="{{ route('admin.chats', ['user' => $user->id]) }}" class="dropdown-item" role="menuitem"><x-icon name="message-circle" /> View chats</a>
            <div class="dropdown-divider"></div>
            @if ($user->isBanned())
                <form method="POST" action="{{ route('admin.users.unban', $user) }}" data-confirm="{{ $user->name }} will be able to sign in and use the app again." data-confirm-title="Lift the ban?" data-confirm-label="Lift ban">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="dropdown-item" role="menuitem"><x-icon name="lock-open" /> Lift ban</button>
                </form>
            @else
                <a href="{{ route('admin.users.show', $user) }}#ban" class="dropdown-item is-danger" role="menuitem"><x-icon name="shield-ban" /> Ban…</a>
            @endif
            @foreach ([
                ['active', 'Activate', 'user-check', 'Activate this account? The user will be able to sign in again.'],
                ['inactive', 'Deactivate', 'circle-pause', 'Deactivate this account? The user will be signed out and cannot sign in.'],
                ['suspended', 'Suspend', 'user-x', 'Suspend this account? The user will be signed out immediately.'],
            ] as [$status, $label, $icon, $confirm])
                @continue($user->status === $status || ($user->isBanned() && $status !== 'active'))
                <form method="POST" action="{{ route('admin.users.status', $user) }}" data-confirm="{{ $confirm }}" data-confirm-title="{{ $label }} {{ $user->name }}?" data-confirm-label="{{ $label }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="{{ $status }}">
                    <button type="submit" class="dropdown-item" role="menuitem"><x-icon :name="$icon" /> {{ $label }}</button>
                </form>
            @endforeach
            <form method="POST" action="{{ route('admin.users.logout', $user) }}" data-confirm="{{ $user->name }} will be signed out of every browser and phone." data-confirm-title="Sign out everywhere?" data-confirm-label="Sign out">
                @csrf
                <button type="submit" class="dropdown-item" role="menuitem"><x-icon name="log-out" /> Sign out everywhere</button>
            </form>
            <div class="dropdown-divider"></div>
            <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                  data-confirm="This permanently deletes the account, its chats, messages and files. Groups of other people stay. This cannot be undone."
                  data-confirm-title="Delete {{ $user->name }}?" data-confirm-label="Delete permanently" data-confirm-danger>
                @csrf
                @method('DELETE')
                <button type="submit" class="dropdown-item is-danger" role="menuitem"><x-icon name="trash-2" /> Delete user</button>
            </form>
        </div>
    </div>
@else
    <a href="{{ route('admin.users.show', $user) }}" class="btn btn-ghost btn-sm">{{ auth()->user()->is($user) ? 'You' : 'View' }}</a>
@endcan
