@php
    // WhatsApp-style settings: a list screen, and one screen per section.
    $sections = [
        'profile' => ['title' => 'Profile', 'icon' => 'user-round'],
        'account' => ['title' => 'Account', 'text' => 'Change number, my data, delete account', 'icon' => 'key-round'],
        'privacy' => ['title' => 'Privacy', 'text' => 'Last seen, profile photo, blocked contacts', 'icon' => 'lock'],
        'security' => ['title' => 'Security', 'text' => 'Password, two-step verification, devices', 'icon' => 'shield-check'],
        'chats' => ['title' => 'Chats', 'text' => 'Theme, wallpaper, font size', 'icon' => 'message-square-text'],
        'notifications' => ['title' => 'Notifications', 'text' => 'Message alerts, tones and vibration', 'icon' => 'bell'],
        'business' => ['title' => 'Business tools', 'text' => 'Business profile, away message, quick replies, labels', 'icon' => 'briefcase-business'],
        'storage' => ['title' => 'Storage and data', 'text' => 'Manage storage, auto-download, chat backup', 'icon' => 'hard-drive'],
        'qr' => ['title' => 'QR code', 'icon' => 'qr-code', 'menu' => false],
        'blocked' => ['title' => 'Blocked contacts', 'icon' => 'ban', 'menu' => false, 'parent' => 'privacy'],
    ];
    $requested = ['preferences' => 'chats'][request('tab')] ?? request('tab');

    // Open the section that has validation errors, otherwise the requested one.
    $activeTab = match (true) {
        $errors->getBag('profile')->isNotEmpty() => 'profile',
        $errors->getBag('password')->isNotEmpty(), $errors->getBag('twoStep')->isNotEmpty() => 'security',
        $errors->getBag('phone')->isNotEmpty(), $errors->getBag('deleteAccount')->isNotEmpty() => 'account',
        $errors->getBag('preferences')->isNotEmpty() => 'chats',
        $errors->getBag('business')->isNotEmpty(), $errors->getBag('businessMessages')->isNotEmpty(), $errors->getBag('quickReply')->isNotEmpty() => 'business',
        is_string($requested) && array_key_exists($requested, $sections) => $requested,
        ($phoneChange ?? null) !== null => 'account',
        default => null,
    };
    $settingsView = $activeTab ? 'section' : 'list';
    $activeTab ??= 'profile';
@endphp

<x-layouts.base title="Settings" body-class="overflow-hidden" :scripts="($phoneChange ?? null) ? ['resources/js/auth/otp.js'] : []">
    <div class="wa-settings" data-settings data-settings-tabs data-view="{{ $settingsView }}" data-active="{{ $activeTab }}">
        {{-- ============================ List ============================ --}}
        <aside class="wa-settings-list" aria-label="Settings">
            <header class="wa-appbar">
                <a href="{{ route('chat.index') }}" class="btn-icon" aria-label="Back to chats" title="Back to chats"><x-icon name="arrow-left" /></a>
                <h1 class="wa-appbar-title">Settings</h1>
                <x-theme-toggle />
            </header>

            <div class="wa-scroll">
                <div class="wa-me">
                    <button type="button" class="wa-me-main" data-settings-open="profile" aria-current="{{ $activeTab === 'profile' ? 'page' : 'false' }}">
                        <x-avatar :user="$user" size="lg" />
                        <span class="wa-me-body">
                            <span class="wa-me-name">{{ $user->name }}</span>
                            <span class="wa-me-about">{{ $user->about ?: '@'.$user->username }}</span>
                        </span>
                    </button>
                    <button type="button" class="btn-icon wa-me-qr" data-settings-open="qr" aria-label="QR code" title="QR code"><x-icon name="qr-code" /></button>
                </div>

                <nav class="wa-menu" aria-label="Settings sections">
                    @foreach ($sections as $key => $section)
                        @continue(($section['menu'] ?? true) === false || $key === 'profile')
                        <button type="button" class="wa-menu-row" data-settings-open="{{ $key }}"
                                aria-current="{{ $activeTab === $key || ($sections[$activeTab]['parent'] ?? null) === $key ? 'page' : 'false' }}">
                            <x-icon :name="$section['icon']" />
                            <span class="wa-menu-body">
                                <span class="wa-menu-title">{{ $section['title'] }}</span>
                                <span class="wa-menu-text">{{ $section['text'] }}</span>
                            </span>
                        </button>
                    @endforeach

                    @if ($user->isAdmin() && Route::has('admin.dashboard'))
                        <a href="{{ route('admin.dashboard') }}" class="wa-menu-row">
                            <x-icon name="layout-dashboard" />
                            <span class="wa-menu-body">
                                <span class="wa-menu-title">Admin panel</span>
                                <span class="wa-menu-text">Users, reports and statistics</span>
                            </span>
                        </a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="wa-menu-row is-danger">
                            <x-icon name="log-out" />
                            <span class="wa-menu-body"><span class="wa-menu-title">Log out</span></span>
                        </button>
                    </form>
                </nav>

                <nav class="wa-legal" aria-label="Help and legal">
                    <a href="{{ route('legal', 'privacy') }}">Privacy policy</a>
                    <a href="{{ route('legal', 'terms') }}">Terms</a>
                    <a href="{{ route('legal', 'child-safety') }}">Child safety</a>
                    <a href="{{ route('legal', 'delete-account') }}">Delete account</a>
                </nav>
                <p class="wa-settings-footer">from<br><strong>{{ config('app.name') }}</strong></p>
            </div>
        </aside>

        {{-- ========================== Sections ========================== --}}
        <main class="wa-settings-detail">
            @foreach ($sections as $key => $section)
                <section id="section-{{ $key }}" @class(['wa-section', 'is-active' => $activeTab === $key])
                         data-settings-section="{{ $key }}" @isset($section['parent']) data-parent="{{ $section['parent'] }}" @endisset
                         aria-labelledby="section-{{ $key }}-title">
                    <header class="wa-appbar">
                        <button type="button" class="btn-icon wa-back" data-settings-back aria-label="Back"><x-icon name="arrow-left" /></button>
                        <h2 class="wa-appbar-title" id="section-{{ $key }}-title">
                            {{ $section['title'] }}
                            @if ($key === 'blocked')
                                <span class="wa-appbar-count">({{ $blockedUsers->count() }})</span>
                            @endif
                        </h2>
                    </header>
                    <div class="wa-scroll">
                        <div class="wa-section-body">
                            @if ($activeTab === $key)
                                <div class="wa-alerts"><x-alerts /></div>
                            @endif
                            @include('profile.sections.'.$key)
                        </div>
                    </div>
                </section>
            @endforeach
        </main>
    </div>
</x-layouts.base>
