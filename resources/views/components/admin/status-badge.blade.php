@props(['user'])
@php
    $tone = match ($user->status) {
        \App\Models\User::STATUS_ACTIVE => 'success',
        \App\Models\User::STATUS_INACTIVE => 'warning',
        default => 'danger',
    };
@endphp
<span class="badge badge-{{ $tone }} capitalize">{{ $user->status }}</span>
@if ($user->isAdmin())
    <span class="badge badge-soft">Admin</span>
@endif
