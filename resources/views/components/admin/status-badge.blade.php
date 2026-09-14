@props(['user'])
@php
    $tone = match ($user->status) {
        \App\Models\User::STATUS_ACTIVE => 'success',
        \App\Models\User::STATUS_INACTIVE => 'warning',
        default => 'danger',
    };
@endphp
<span class="badge badge-{{ $tone }} capitalize">
    @if ($user->isBanned())
        <x-icon name="shield-ban" class="icon-xs" />
        {{ $user->banned_until ? 'Banned until '.$user->banned_until->format('j M') : 'Banned' }}
    @else
        {{ $user->status }}
    @endif
</span>
@if ($user->isAdmin())
    <span class="badge badge-soft"><x-icon name="crown" class="icon-xs" /> Admin</span>
@endif
