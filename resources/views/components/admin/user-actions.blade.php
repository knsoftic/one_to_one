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
            @foreach ([
                ['active', 'Activate', 'user-check', 'Activate this account? The user will be able to sign in again.'],
                ['inactive', 'Deactivate', 'circle-pause', 'Deactivate this account? The user will be signed out and cannot sign in.'],
                ['suspended', 'Suspend', 'user-x', 'Suspend this account? The user will be signed out immediately.'],
            ] as [$status, $label, $icon, $confirm])
                @continue($user->status === $status)
                <form method="POST" action="{{ route('admin.users.status', $user) }}" data-confirm="{{ $confirm }}" data-confirm-title="{{ $label }} {{ $user->name }}?" data-confirm-label="{{ $label }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="status" value="{{ $status }}">
                    <button type="submit" class="dropdown-item" role="menuitem"><x-icon :name="$icon" /> {{ $label }}</button>
                </form>
            @endforeach
            <div class="dropdown-divider"></div>
            <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                  data-confirm="This permanently deletes the account, its conversations, messages and files. This cannot be undone."
                  data-confirm-title="Delete {{ $user->name }}?" data-confirm-label="Delete permanently" data-confirm-danger>
                @csrf
                @method('DELETE')
                <button type="submit" class="dropdown-item is-danger" role="menuitem"><x-icon name="trash-2" /> Delete user</button>
            </form>
        </div>
    </div>
@else
    <span class="text-xs text-subtle">{{ auth()->user()->is($user) ? 'You' : 'Protected' }}</span>
@endcan
