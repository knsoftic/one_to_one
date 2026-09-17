@props(['icon' => 'message-circle', 'base' => 'brand-mark'])
{{-- The app icon from Admin → App settings, or the built-in mark when none was uploaded. --}}
@inject('brand', 'App\Services\BrandService')
@if ($brand->hasIcon())
    <span {{ $attributes->class([$base, 'has-image']) }}><img src="{{ $brand->iconUrl(96) }}" alt="" width="36" height="36"></span>
@else
    <span {{ $attributes->class([$base]) }}><x-icon :name="$icon" /></span>
@endif
