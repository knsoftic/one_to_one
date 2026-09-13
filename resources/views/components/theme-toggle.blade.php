{{-- Cycles Light → Dark → System. The visible icon follows <html data-theme-pref>. --}}
<button type="button" {{ $attributes->class(['btn-icon theme-toggle']) }} data-theme-cycle aria-label="Change theme" title="Change theme">
    <x-icon name="sun" data-theme-icon="light" />
    <x-icon name="moon" data-theme-icon="dark" />
    <x-icon name="monitor" data-theme-icon="system" />
</button>
