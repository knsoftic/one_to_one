@props(['title' => null, 'wide' => false, 'scripts' => []])
<x-layouts.base :title="$title" :scripts="$scripts">
    <div class="auth-shell">
        <aside class="auth-hero">
            <a href="{{ route('login') }}" class="brand">
                <span class="brand-mark"><x-icon name="message-circle" /></span>
                {{ config('app.name') }}
            </a>

            <div>
                <h1 class="auth-hero-title">Your conversations, delivered instantly.</h1>
                <p class="auth-hero-text">
                    Real-time one-to-one messaging with read receipts, voice notes, file sharing and
                    typing indicators.
                </p>

                <div class="auth-preview" aria-hidden="true">
                    <div class="auth-preview-head">
                        <div class="auth-preview-avatar">AK</div>
                        <div>
                            <div class="auth-preview-name">Ahmed Khan</div>
                            <div class="auth-preview-status">online</div>
                        </div>
                    </div>
                    <div class="auth-preview-body">
                        <div class="auth-bubble in">Hey! Did you get the project files? 📁</div>
                        <div class="auth-bubble out">
                            Yes, just reviewed them. Looks great!
                            <div class="auth-bubble-meta">10:32 PM <x-icon name="check-check" /></div>
                        </div>
                        <div class="auth-bubble in">Awesome, let's ship it tomorrow 🚀</div>
                        <div class="auth-typing"><span></span><span></span><span></span></div>
                    </div>
                </div>

                <div class="auth-features">
                    <span class="auth-feature"><x-icon name="shield-check" /> Block &amp; report</span>
                    <span class="auth-feature"><x-icon name="check-check" /> Read receipts</span>
                    <span class="auth-feature"><x-icon name="mic" /> Voice notes</span>
                    <span class="auth-feature"><x-icon name="moon" /> Dark mode</span>
                </div>
            </div>

            <p class="auth-hero-footer">&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</p>
        </aside>

        <main class="auth-main">
            <div class="auth-topbar">
                <a href="{{ route('login') }}" class="brand">
                    <span class="brand-mark"><x-icon name="message-circle" /></span>
                    <span>{{ config('app.name') }}</span>
                </a>
                <x-theme-toggle />
            </div>

            <div @class(['auth-card', 'is-wide' => $wide])>
                {{ $slot }}
            </div>
        </main>
    </div>
</x-layouts.base>
