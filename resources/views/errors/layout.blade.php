<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    {{-- Self-contained styles: error pages must render even when the app is unhealthy. --}}
    <style>
        :root { color-scheme: light dark; --bg: #f0f2f5; --card: #fff; --text: #111b21; --muted: #667781; --border: #e9edef; --primary: #00a884; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0c1317; --card: #111b21; --text: #e9edef; --muted: #8696a0; --border: #222d34; --primary: #00a884; } }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; background: var(--bg); color: var(--text);
               font-family: 'Inter Variable', Inter, ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        .card { width: 100%; max-width: 28rem; padding: 2.5rem 2rem; border-radius: 24px; background: var(--card); border: 1px solid var(--border); text-align: center;
                box-shadow: 0 24px 48px -16px rgba(15, 23, 42, .18); }
        .code { font-size: 4.5rem; font-weight: 800; line-height: 1; letter-spacing: -.05em;
                color: var(--primary); }
        h1 { margin: 1rem 0 .5rem; font-size: 1.35rem; }
        p { margin: 0; color: var(--muted); line-height: 1.6; }
        .actions { display: flex; gap: .6rem; justify-content: center; flex-wrap: wrap; margin-top: 1.75rem; }
        a { display: inline-flex; align-items: center; height: 2.6rem; padding: 0 1.1rem; border-radius: 12px; font-weight: 600; font-size: .9rem; text-decoration: none; }
        .primary { background: var(--primary); color: #fff; }
        .secondary { border: 1px solid var(--border); color: var(--text); }
    </style>
</head>
<body>
    <main class="card">
        <div class="code">@yield('code')</div>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            <a class="primary" href="{{ url('/chat') }}">Go to chats</a>
            <a class="secondary" href="{{ url()->previous() }}">Go back</a>
        </div>
    </main>
</body>
</html>
