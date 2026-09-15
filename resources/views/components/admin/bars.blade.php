@props(['series', 'label' => 'Chart', 'unit' => 'messages', 'height' => '11rem', 'every' => null])
@php
    $max = max(1, collect($series)->max('count'));
    $count = count($series);
    // Show a label on every Nth bar so long ranges stay readable.
    $every ??= $count > 45 ? 14 : ($count > 20 ? 5 : 1);
@endphp
<div class="volume-chart is-compact" style="--days: {{ $count }}; height: {{ $height }}" role="img" aria-label="{{ $label }}">
    @foreach ($series as $i => $point)
        <div class="volume-col" title="{{ $point['title'] ?? $point['label'] }}: {{ number_format($point['count']) }} {{ $unit }}">
            <span class="volume-count">{{ $count <= 31 && $point['count'] ? number_format($point['count']) : '' }}</span>
            <span class="volume-bar" style="--h: {{ max(2, round($point['count'] / $max * 100)) }}%"></span>
            <span class="volume-label">{{ ($i % $every === 0 || ($loop->last && $i % $every >= $every / 2)) ? $point['label'] : '' }}</span>
        </div>
    @endforeach
</div>
