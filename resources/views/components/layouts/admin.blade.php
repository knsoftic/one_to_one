@props(['title' => null, 'heading' => null, 'subheading' => null])
<x-layouts.base :title="$title ? $title.' · Admin' : 'Admin'">
    <div class="admin-shell">
        <aside class="admin-sidebar">
            <a href="{{ route('admin.dashboard') }}" class="brand text-ink">
                <span class="brand-mark"><x-icon name="shield-check" /></span>
                <span>Admin <span class="text-muted font-semibold">Panel</span></span>
            </a>

            <nav class="admin-nav" aria-label="Admin navigation">
                <a href="{{ route('admin.dashboard') }}" @class(['admin-nav-link', 'is-active' => request()->routeIs('admin.dashboard')])>
                    <x-icon name="layout-dashboard" /> Dashboard
                </a>
                <a href="{{ route('admin.users') }}" @class(['admin-nav-link', 'is-active' => request()->routeIs('admin.users*')])>
                    <x-icon name="users" /> Users
                </a>
                <a href="{{ route('admin.users', ['status' => 'suspended']) }}" class="admin-nav-link">
                    <x-icon name="user-x" /> Suspended
                </a>
                <div class="admin-nav-divider"></div>
                <a href="{{ route('chat.index') }}" class="admin-nav-link"><x-icon name="message-circle" /> Back to chats</a>
                <a href="{{ route('profile.edit') }}" class="admin-nav-link"><x-icon name="settings" /> Settings</a>
            </nav>

            <div class="admin-privacy-note">
                <x-icon name="lock" class="icon-sm" />
                <span>Private conversations are end-user data and are not accessible from the admin panel.</span>
            </div>
        </aside>

        <div class="admin-main">
            <header class="admin-topbar">
                <div class="min-w-0">
                    @if ($heading)
                        <h1 class="page-title truncate">{{ $heading }}</h1>
                    @endif
                    @if ($subheading)
                        <p class="page-subtitle truncate">{{ $subheading }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-1">
                    <x-theme-toggle />
                    <div class="dropdown">
                        <button type="button" class="btn-icon" data-dropdown-toggle aria-haspopup="menu" aria-expanded="false" aria-label="Account menu">
                            <x-avatar :user="auth()->user()" size="sm" />
                        </button>
                        <div class="dropdown-menu" data-align="right" role="menu" hidden>
                            <div class="px-3 py-2">
                                <div class="font-semibold text-sm truncate">{{ auth()->user()->name }}</div>
                                <div class="text-xs text-muted truncate">Administrator</div>
                            </div>
                            <div class="dropdown-divider"></div>
                            <a href="{{ route('chat.index') }}" class="dropdown-item" role="menuitem"><x-icon name="message-circle" /> Chats</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item is-danger" role="menuitem"><x-icon name="log-out" /> Log out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="admin-content">
                <div class="mb-5"><x-alerts /></div>
                @if ($errors->any())
                    <div class="alert alert-error mb-5" role="alert"><x-icon name="circle-alert" /><span>{{ $errors->first() }}</span></div>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
</x-layouts.base>
