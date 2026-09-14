@props(['name' => '', 'src' => null, 'size' => 'md', 'icon' => null])
@php
    $initials = collect(preg_split('/\s+/u', trim((string) $name)) ?: [])->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('') ?: '#';
@endphp
<span {{ $attributes->class(['avatar', 'avatar-'.$size, 'admin-space-avatar']) }}>
    @if ($src)
        <img src="{{ $src }}" alt="" class="avatar-img" loading="lazy">
    @elseif ($icon)
        <span class="avatar-fallback is-neutral"><x-icon :name="$icon" /></span>
    @else
        <span class="avatar-fallback is-neutral">{{ $initials }}</span>
    @endif
</span>
