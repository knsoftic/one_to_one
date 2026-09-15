@props(['name'])
<svg {{ $attributes->class(['icon', 'icon-flip' => in_array($name, \App\Support\Icons::FLIP_IN_RTL, true)]) }} xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false">{!! \App\Support\Icons::get($name) !!}</svg>
