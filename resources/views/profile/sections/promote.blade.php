{{-- Promote (Y2): my promotions and the new-promotion wizard. Rendered by resources/js/ui/promote.js. --}}
<div class="wa-group" data-promote data-route="{{ route('promotions.index') }}" data-kind="{{ request('kind') }}" data-target="{{ request('id') }}">
    <div class="wa-loading" data-promote-loading><x-icon name="loader-circle" class="spin" /> Loading…</div>
</div>
