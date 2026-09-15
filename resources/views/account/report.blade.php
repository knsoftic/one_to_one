@php
    $label = fn (string $key) => ucfirst(str_replace('_', ' ', $key));
    $value = function (mixed $v): string {
        return match (true) {
            is_bool($v) => $v ? 'Yes' : 'No',
            $v === null || $v === '' => '—',
            is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $v) === 1 => \Illuminate\Support\Carbon::parse($v)->format('M j, Y g:i A'),
            default => (string) $v,
        };
    };
    $account = $data['account'];
    $lists = [
        'contacts' => 'Saved contacts on '.$data['report']['app'],
        'blocked' => 'Blocked contacts',
        'groups' => 'Groups',
        'communities' => 'Communities',
        'channels' => 'Channels',
        'broadcast_lists' => 'Broadcast lists',
        'chat_lists' => 'Chat lists',
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Account report · {{ $account['name'] }} · {{ $data['report']['app'] }}</title>
    <style>
        :root { color-scheme: light; --ink: #17152e; --muted: #69668a; --line: #e8e6f3; --band: #4f46e5; --soft: #f4f3fb; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: var(--ink); background: var(--soft); }
        header { padding: 2rem 1.25rem 3.5rem; color: #fff; background: var(--band); }
        header p { margin: 0.25rem 0 0; opacity: 0.85; }
        h1 { margin: 0; font-size: 1.6rem; }
        main { max-width: 52rem; margin: -2.25rem auto 2rem; padding: 0 1rem; display: flex; flex-direction: column; gap: 1rem; }
        section { padding: 1.1rem 1.25rem; border-radius: 12px; background: #fff; box-shadow: 0 1px 2px rgb(11 20 26 / 0.08); }
        h2 { margin: 0 0 0.6rem; font-size: 1.05rem; }
        h3 { margin: 0.9rem 0 0.35rem; font-size: 0.85rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; }
        dl { display: grid; grid-template-columns: minmax(9rem, 14rem) 1fr; gap: 0.35rem 1rem; margin: 0; }
        dt { color: var(--muted); }
        dd { margin: 0; overflow-wrap: anywhere; }
        .table { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
        th, td { padding: 0.45rem 0.5rem; text-align: left; border-bottom: 1px solid var(--line); white-space: nowrap; }
        th { font-weight: 600; color: var(--muted); }
        .count { color: var(--muted); font-weight: 400; }
        .empty { margin: 0; color: var(--muted); }
        .note { margin: 0; color: var(--muted); font-size: 0.9rem; }
        @media (max-width: 560px) { dl { grid-template-columns: 1fr; } dt { margin-top: 0.4rem; } }
    </style>
</head>
<body>
    <header>
        <h1>Account report</h1>
        <p>{{ $account['name'] }} ({{ '@'.$account['username'] }}) · {{ $data['report']['app'] }} · made {{ $value($data['report']['generated_at']) }}</p>
    </header>
    <main>
        <section>
            <h2>Account</h2>
            <dl>
                @foreach ($account as $key => $item)
                    <dt>{{ $label($key) }}</dt><dd>{{ $value($item) }}</dd>
                @endforeach
            </dl>
        </section>

        <section>
            <h2>Settings</h2>
            <dl>
                @foreach (['theme', 'notifications', 'notification_sound', 'chat_lock'] as $key)
                    <dt>{{ $label($key) }}</dt><dd>{{ $value(is_string($data['settings'][$key]) ? ucfirst($data['settings'][$key]) : $data['settings'][$key]) }}</dd>
                @endforeach
            </dl>
            <h3>Privacy</h3>
            <dl>
                @foreach ($data['settings']['privacy'] as $key => $item)
                    <dt>{{ $label($key) }}</dt><dd>{{ $value(is_string($item) ? $label($item) : $item) }}</dd>
                @endforeach
            </dl>
            <h3>Two-step verification</h3>
            <dl>
                @foreach ($data['settings']['two_step_verification'] as $key => $item)
                    <dt>{{ $label($key) }}</dt><dd>{{ $value($item) }}</dd>
                @endforeach
            </dl>
        </section>

        <section>
            <h2>Activity</h2>
            <dl>
                @foreach ($data['activity'] as $key => $item)
                    <dt>{{ $label($key) }}</dt><dd>{{ number_format($item) }}</dd>
                @endforeach
            </dl>
        </section>

        @foreach ($lists as $key => $title)
            <section>
                <h2>{{ $title }} <span class="count">({{ count($data[$key]) }})</span></h2>
                @if ($data[$key] === [])
                    <p class="empty">None</p>
                @else
                    <div class="table">
                        <table>
                            <thead><tr>@foreach (array_keys($data[$key][0]) as $column)<th>{{ $label($column) }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($data[$key] as $row)
                                    <tr>@foreach ($row as $cell)<td>{{ $value($cell) }}</td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endforeach

        @foreach (['signed_in' => 'Where you\'re signed in', 'two_step_trusted_browsers' => 'Browsers trusted for two-step verification'] as $key => $title)
            <section>
                <h2>{{ $title }} <span class="count">({{ count($data['devices'][$key]) }})</span></h2>
                @if ($data['devices'][$key] === [])
                    <p class="empty">None</p>
                @else
                    <div class="table">
                        <table>
                            <thead><tr>@foreach (array_keys($data['devices'][$key][0]) as $column)<th>{{ $label($column) }}</th>@endforeach</tr></thead>
                            <tbody>
                                @foreach ($data['devices'][$key] as $row)
                                    <tr>@foreach ($row as $cell)<td>{{ $value($cell) }}</td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endforeach

        <section>
            <p class="note">{{ $data['report']['note'] }}</p>
        </section>
    </main>
</body>
</html>
