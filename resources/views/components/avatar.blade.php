@props(['user', 'size' => 'md', 'status' => false])
<span {{ $attributes->class(['avatar', 'avatar-'.$size, 'is-online' => $status && $user->isOnlineNow()]) }} data-avatar-user="{{ $user->id }}">
    @if ($user->avatar_url)
        <img src="{{ $user->avatar_url }}" alt="{{ $user->name }}" class="avatar-img" loading="lazy" decoding="async">
    @else
        <span class="avatar-fallback" style="--hue: {{ (int) $user->avatar_hue }}" aria-label="{{ $user->name }}">{{ $user->initials }}</span>
    @endif
    @if ($status)
        <span class="avatar-status" aria-hidden="true"></span>
    @endif
</span>
