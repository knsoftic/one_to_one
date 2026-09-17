@props(['values' => [], 'label' => ''])
{{-- A small trend line (per day) for a dashboard number; the last day is marked. --}}
@php
    $values = array_values(array_map('intval', $values));
    $count = count($values);
    $width = 96;
    $height = 30;
    $max = max(1, ...($values ?: [0]));
    $x = fn (int $i) => $count > 1 ? round($i * ($width - 4) / ($count - 1) + 2, 2) : $width / 2;
    $y = fn (int $value) => round($height - 3 - ($value / $max) * ($height - 6), 2);
    $points = collect($values)->map(fn ($value, $i) => $x($i).','.$y($value))->implode(' ');
@endphp
@if ($count > 1)
    <svg {{ $attributes->class('kpi-spark') }} viewBox="0 0 {{ $width }} {{ $height }}" width="{{ $width }}" height="{{ $height }}" role="img" aria-label="{{ $label }}" preserveAspectRatio="none">
        <polygon class="kpi-spark-area" points="{{ $x(0) }},{{ $height }} {{ $points }} {{ $x($count - 1) }},{{ $height }}" />
        <polyline class="kpi-spark-line" points="{{ $points }}" />
        <circle class="kpi-spark-dot" cx="{{ $x($count - 1) }}" cy="{{ $y(end($values)) }}" r="2.5" />
    </svg>
@endif
