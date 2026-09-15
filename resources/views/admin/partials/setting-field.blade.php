{{--
    One field of the app settings form.
    $name, $label, $values; optional: $type (text|password|number|url|email|select|textarea), $options [value => label],
    $hint, $placeholder, $env (the .env name it replaces).
--}}
@php
    $type ??= 'text';
    $secret = (bool) (\App\Services\AppConfigService::FIELDS[$name]['secret'] ?? false);
    $saved = $values[$name] ?? null;
    $invalid = $errors->has($name);
    $id = 'setting-'.$name;
@endphp
<div class="form-group admin-setting-field">
    <label for="{{ $id }}" class="form-label">
        {{ $label }}
        @if ($secret && $saved)
            <span class="badge badge-success">Saved</span>
        @endif
    </label>

    @if ($type === 'select')
        <select id="{{ $id }}" name="{{ $name }}" class="form-control @if ($invalid) is-invalid @endif">
            @foreach ($options as $value => $optionLabel)
                <option value="{{ $value }}" @selected((string) old($name, $saved ?? '') === (string) $value)>{{ $optionLabel }}</option>
            @endforeach
        </select>
    @elseif ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" rows="2" class="form-control @if ($invalid) is-invalid @endif" placeholder="{{ $secret && $saved ? 'Saved — leave empty to keep it' : ($placeholder ?? '') }}" @if ($secret) autocomplete="off" spellcheck="false" @endif>{{ $secret ? '' : old($name, $saved) }}</textarea>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $secret ? 'password' : $type }}" class="form-control @if ($invalid) is-invalid @endif"
               value="{{ $secret ? '' : old($name, $saved) }}" placeholder="{{ $secret && $saved ? 'Saved — leave empty to keep it' : ($placeholder ?? '') }}"
               @if ($secret) autocomplete="new-password" @else autocomplete="off" @endif spellcheck="false">
    @endif

    @if ($invalid)
        <p class="form-error"><x-icon name="circle-alert" />{{ $errors->first($name) }}</p>
    @elseif (! empty($hint) || ! empty($env))
        <p class="form-hint">{{ $hint ?? '' }}@if (! empty($env)) <span class="admin-env">Replaces {{ $env }}</span>@endif</p>
    @endif

    @if ($secret && $saved)
        <label class="checkbox admin-setting-clear">
            <input type="checkbox" name="clear[]" value="{{ $name }}"> Remove the saved value
        </label>
    @endif
</div>
