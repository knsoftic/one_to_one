<x-layouts.guest title="Account banned">
    @php
        $until = $ban['until'] ? \Illuminate\Support\Carbon::parse($ban['until']) : null;
    @endphp
    <div class="ban-screen" data-ban-screen>
        <span class="ban-icon"><x-icon name="ban" /></span>
        <h2 class="auth-title">Your account has been banned</h2>
        <p class="auth-subtitle">
            @if ($ban['permanent'])
                This account can no longer use {{ config('app.name') }}.
            @else
                You can't use {{ config('app.name') }} until the ban ends.
            @endif
        </p>

        <dl class="ban-details">
            <div class="ban-row">
                <dt><x-icon name="message-square-warning" /> Reason</dt>
                <dd>{{ $ban['reason'] ?: 'Breaking the rules of this app.' }}</dd>
            </div>
            <div class="ban-row">
                <dt><x-icon name="clock" /> {{ $ban['permanent'] ? 'Length' : 'Ends' }}</dt>
                <dd>
                    @if ($ban['permanent'])
                        Permanent
                    @else
                        <time datetime="{{ $until->toIso8601String() }}">{{ $until->format('j M Y, g:i A') }}</time>
                        <span class="ban-left">({{ $until->diffForHumans(['parts' => 2, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }} left)</span>
                    @endif
                </dd>
            </div>
            @if ($ban['since'])
                <div class="ban-row">
                    <dt><x-icon name="calendar" /> Since</dt>
                    <dd>{{ \Illuminate\Support\Carbon::parse($ban['since'])->format('j M Y, g:i A') }}</dd>
                </div>
            @endif
        </dl>

        <p class="ban-help">
            If you think this is a mistake, contact support
            @if (config('mail.from.address') && ! str_ends_with((string) config('mail.from.address'), '@example.com'))
                at <a href="mailto:{{ config('mail.from.address') }}" class="auth-link">{{ config('mail.from.address') }}</a>
            @endif
            and tell us your username.
        </p>

        <a href="{{ route('login') }}" class="btn btn-secondary btn-lg btn-block"><x-icon name="arrow-left" /> Back to sign in</a>
    </div>
</x-layouts.guest>
