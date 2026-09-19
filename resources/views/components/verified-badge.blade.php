@props(['user', 'hint' => false])
@php
    // Y2: the verified tick — bought with coins, granted by an admin, or part of the active plan.
    $badges = app(\App\Services\BadgeService::class);
    $state = $badges->isVerified($user) ? $badges->state($user) : null;
@endphp
@if ($state)
    <span {{ $attributes->class(['verified-badge']) }} title="Verified" aria-label="Verified" data-verified-badge><x-icon name="badge-check" /></span>
    @if ($hint)
        <span class="verified-hint">
            @if ($state['source'] === 'plan')
                Included in your plan
            @elseif ($state['lifetime'])
                Verified for good
            @elseif ($state['until'])
                Verified until {{ \Illuminate\Support\Carbon::parse($state['until'])->format('j M Y') }}
            @endif
        </span>
    @endif
@endif
