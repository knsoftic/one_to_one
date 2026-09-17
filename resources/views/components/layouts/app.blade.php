@props(['title' => null, 'back' => null, 'scripts' => []])
<x-layouts.base :title="$title" :scripts="$scripts">
    <div class="page-shell">
        <header class="topbar">
            <div class="flex items-center gap-2 min-w-0">
                <a href="{{ $back ?? route('chat.index') }}" class="btn-icon" aria-label="Back to chats" title="Back to chats">
                    <x-icon name="arrow-left" />
                </a>
                <a href="{{ route('chat.index') }}" class="brand text-ink">
                    <x-brand-mark />
                    <span class="hidden sm:inline">{{ config('app.name') }}</span>
                </a>
            </div>

            <div class="flex items-center gap-1">
                <x-theme-toggle />
                @if (auth()->user()->isAdmin() && Route::has('admin.dashboard'))
                    <a href="{{ route('admin.dashboard') }}" class="btn-icon" title="Admin panel" aria-label="Admin panel">
                        <x-icon name="layout-dashboard" />
                    </a>
                @endif
                <div class="dropdown">
                    <button type="button" class="btn-icon" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Account menu">
                        <x-avatar :user="auth()->user()" size="sm" />
                    </button>
                    <div class="dropdown-menu" data-align="right" role="menu" hidden>
                        <div class="px-3 py-2">
                            <div class="font-semibold text-sm truncate">{{ auth()->user()->name }}</div>
                            <div class="text-xs text-muted truncate">{{ '@'.auth()->user()->username }}</div>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a href="{{ route('chat.index') }}" class="dropdown-item" role="menuitem"><x-icon name="message-circle" /> Chats</a>
                        <a href="{{ route('profile.edit') }}" class="dropdown-item" role="menuitem"><x-icon name="settings" /> Settings</a>
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="dropdown-item is-danger" role="menuitem"><x-icon name="log-out" /> Log out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1">
            {{ $slot }}
        </main>
    </div>
</x-layouts.base>
