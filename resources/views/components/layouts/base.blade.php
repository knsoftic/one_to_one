@props(['title' => null, 'bodyClass' => '', 'scripts' => []])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      class="no-transitions"
      @auth data-theme-pref="{{ auth()->user()->theme }}" @endauth>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#4f46e5">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    {{-- Apply the theme before first paint to avoid a flash of the wrong theme. --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            var root = document.documentElement, pref = root.getAttribute('data-theme-pref');
            try { if (!pref) { pref = localStorage.getItem('theme'); } } catch (e) {}
            if (['light', 'dark', 'system'].indexOf(pref) === -1) { pref = 'system'; }
            var dark = pref === 'dark' || (pref === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
            root.setAttribute('data-theme-pref', pref);
            root.setAttribute('data-theme', dark ? 'dark' : 'light');
            // Inside the mobile app the page is drawn behind the status bar.
            if (window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()) { root.classList.add('is-native-app'); }
        })();
    </script>

    @vite(array_merge(['resources/css/app.css', 'resources/js/app.js'], $scripts))
    {{ $head ?? '' }}
</head>
<body class="{{ $bodyClass }}">
    {{ $slot }}

    <div class="toast-stack" id="toast-stack" aria-live="polite" aria-atomic="false"></div>

    <script type="application/json" id="app-config">@json($appConfig)</script>
</body>
</html>
