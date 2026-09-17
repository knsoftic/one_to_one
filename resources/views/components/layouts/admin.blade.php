@props(['title' => null, 'heading' => null, 'subheading' => null, 'back' => null])
@php
    $openReports = \App\Models\UserReport::query()->open()->count();
    $banned = \App\Models\User::query()->where('status', \App\Models\User::STATUS_BANNED)->count();
    $nav = [
        'Overview' => [
            ['admin.dashboard', [], 'layout-dashboard', 'Dashboard', 'admin.dashboard', null],
        ],
        'People' => [
            ['admin.users', [], 'users', 'Users', 'admin.users*', null],
            ['admin.users', ['status' => 'banned'], 'shield-ban', 'Banned', null, $banned ?: null],
            ['admin.reports', [], 'message-square-warning', 'Reports', 'admin.reports*', $openReports ?: null],
        ],
        'Content' => [
            ['admin.chats', [], 'message-circle', 'Chats', 'admin.chats*', null],
            ['admin.messages', [], 'file-search', 'Search messages', 'admin.messages*', null],
            ['admin.groups', [], 'users-round', 'Groups', 'admin.groups*', null],
            ['admin.channels', [], 'rss', 'Channels', 'admin.channels*', null],
            ['admin.communities', [], 'layers', 'Communities', 'admin.communities*', null],
            ['admin.statuses', [], 'circle-dashed', 'Status updates', 'admin.statuses*', null],
        ],
        'System' => [
            ['admin.ads', [], 'badge-dollar-sign', 'Ads', 'admin.ads*', null],
            ['admin.audit', [], 'scroll-text', 'Audit log', 'admin.audit*', null],
            ['admin.settings', [], 'sliders-horizontal', 'App settings', 'admin.settings*', null],
            ['admin.backups', [], 'database', 'Backups', 'admin.backups*', null],
            ['admin.docs', [], 'book-open', 'Guides', 'admin.docs*', null],
        ],
    ];
    $bannedFilter = request()->routeIs('admin.users') && request('status') === 'banned';
@endphp
<x-layouts.base :title="$title ? $title.' · Admin' : 'Admin'">
    <div class="admin-shell">
        <input type="checkbox" id="admin-nav-toggle" class="admin-nav-toggle" aria-hidden="true" tabindex="-1">

        <aside class="admin-sidebar" aria-label="Admin navigation">
            <div class="admin-brand">
                <x-brand-mark base="admin-brand-mark" icon="shield-check" />
                <span class="admin-brand-text">
                    <strong>{{ config('app.name') }}</strong>
                    <small>Admin panel</small>
                </span>
                <label for="admin-nav-toggle" class="btn-icon admin-nav-close" aria-label="Close menu"><x-icon name="x" /></label>
            </div>

            <nav class="admin-nav">
                @foreach ($nav as $group => $links)
                    <div class="admin-nav-group">
                        <span class="admin-nav-heading">{{ $group }}</span>
                        @foreach ($links as [$route, $params, $icon, $label, $pattern, $count])
                            @php
                                $active = $label === 'Banned' ? $bannedFilter : ($pattern && request()->routeIs($pattern) && ! ($label === 'Users' && $bannedFilter));
                            @endphp
                            <a href="{{ route($route, $params) }}" @class(['admin-nav-link', 'is-active' => $active]) @if ($active) aria-current="page" @endif>
                                <x-icon :name="$icon" />
                                <span>{{ $label }}</span>
                                @if ($count)
                                    <span class="admin-nav-count">{{ $count > 99 ? '99+' : $count }}</span>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </nav>

            <div class="admin-sidebar-foot">
                <a href="{{ route('chat.index') }}" class="admin-nav-link"><x-icon name="message-circle" /> <span>Back to chats</span></a>
                <p class="admin-audit-note">
                    <x-icon name="scroll-text" />
                    <span>Opening a chat, searching messages and every moderation action goes into the <a href="{{ route('admin.audit') }}">audit log</a>.</span>
                </p>
            </div>
        </aside>
        <label for="admin-nav-toggle" class="admin-scrim" aria-hidden="true"></label>

        <div class="admin-main">
            <header class="admin-topbar">
                <label for="admin-nav-toggle" class="btn-icon admin-menu-btn" aria-label="Open menu"><x-icon name="menu" /></label>
                @if ($back)
                    <a href="{{ $back }}" class="btn-icon" aria-label="Back"><x-icon name="arrow-left" /></a>
                @endif
                <div class="admin-topbar-titles">
                    @if ($heading)
                        <h1 class="admin-title">{{ $heading }}</h1>
                    @endif
                    @if ($subheading)
                        <p class="admin-subtitle">{{ $subheading }}</p>
                    @endif
                </div>
                <form method="GET" action="{{ route('admin.users') }}" class="admin-topbar-search" role="search">
                    <x-icon name="search" />
                    <input type="search" name="q" value="{{ request()->routeIs('admin.users') ? request('q') : '' }}" placeholder="Find a person…" aria-label="Find a person by name, username, email or mobile" autocomplete="off" data-admin-search>
                    <kbd aria-hidden="true">/</kbd>
                </form>
                <div class="admin-topbar-actions">
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
                            <a href="{{ route('profile.edit') }}" class="dropdown-item" role="menuitem"><x-icon name="settings" /> Settings</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item is-danger" role="menuitem"><x-icon name="log-out" /> Log out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="admin-content">
                <x-alerts />
                @if ($errors->any())
                    <div class="alert alert-error" role="alert"><x-icon name="circle-alert" /><span>{{ $errors->first() }}</span></div>
                @endif
                {{ $slot }}
            </main>
        </div>
    </div>
</x-layouts.base>
