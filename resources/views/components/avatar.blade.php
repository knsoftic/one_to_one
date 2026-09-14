@props(['user', 'size' => 'md', 'status' => false, 'viewer' => null])
@php
    // With a viewer, the photo follows the person's privacy settings (Phase 6).
    $photo = $user->avatar_url && (! $viewer || app(\App\Services\PrivacyService::class)->canSeePhoto($user, $viewer)) ? $user->avatar_url : null;
@endphp
<span {{ $attributes->class(['avatar', 'avatar-'.$size, 'is-online' => $status && $user->isOnlineNow()]) }} data-avatar-user="{{ $user->id }}">
    @if ($photo)
        <img src="{{ $photo }}" alt="{{ $user->name }}" class="avatar-img" loading="lazy" decoding="async">
    @else
        <span class="avatar-fallback" style="--hue: {{ (int) $user->avatar_hue }}" aria-label="{{ $user->name }}">{{ $user->initials }}</span>
    @endif
    @if ($status)
        <span class="avatar-status" aria-hidden="true"></span>
    @endif
</span>
