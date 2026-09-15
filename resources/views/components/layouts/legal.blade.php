@props(['title', 'page', 'pages', 'updated'])
<x-layouts.base :title="$title" body-class="legal-body">
    <div class="legal-shell">
        <header class="legal-topbar">
            <a href="{{ auth()->check() ? route('chat.index') : route('login') }}" class="brand">
                <span class="brand-mark"><x-icon name="message-circle" /></span>
                <span>{{ config('app.name') }}</span>
            </a>
            <x-theme-toggle />
        </header>

        <nav class="legal-nav" aria-label="Legal pages">
            @foreach ($pages as $key => $label)
                <a href="{{ route('legal', $key) }}" @class(['legal-nav-link', 'is-active' => $key === $page]) @if ($key === $page) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>

        <main class="legal-card">
            <p class="legal-eyebrow">Last updated {{ $updated }}</p>
            <h1 class="legal-title">{{ $title }}</h1>
            <article class="legal-content">
                {{ $slot }}
            </article>
        </main>

        <footer class="legal-footer">&copy; {{ date('Y') }} {{ config('app.name') }}</footer>
    </div>
</x-layouts.base>
