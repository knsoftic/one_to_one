@props([
    'name',
    'label' => null,
    'type' => 'text',
    'icon' => null,
    'value' => null,
    'hint' => null,
    'bag' => 'default',
    'optional' => false,
    'id' => null,
])
@php
    $id = $id ?? 'field-'.str_replace(['[', ']', '.'], '-', $name);
    $bagErrors = $errors->getBag($bag);
    $hasError = $bagErrors->has($name);
    $isPassword = $type === 'password';
@endphp
<div class="form-group">
    @if ($label)
        <label for="{{ $id }}" class="form-label">
            {{ $label }}
            @if ($optional)
                <span class="optional">(optional)</span>
            @endif
        </label>
    @endif

    <div class="input-wrap">
        @if ($icon)
            <x-icon :name="$icon" />
        @endif

        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="{{ $type }}"
            @unless ($isPassword) value="{{ old($name, $value) }}" @endunless
            {{ $attributes->class(['form-control', 'is-invalid' => $hasError, 'has-action' => $isPassword]) }}
            @if ($hasError) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif
        >

        @if ($isPassword)
            <button type="button" class="input-action" data-password-toggle="{{ $id }}" aria-label="Show password" tabindex="-1">
                <x-icon name="eye" class="icon-sm" data-show />
                <x-icon name="eye-off" class="icon-sm" data-hide hidden />
            </button>
        @endif
    </div>

    {{ $slot }}

    @if ($hasError)
        <p class="form-error" id="{{ $id }}-error"><x-icon name="circle-alert" />{{ $bagErrors->first($name) }}</p>
    @elseif ($hint)
        <p class="form-hint">{{ $hint }}</p>
    @endif
</div>
