@props(['items', 'label' => 'items'])
@if ($items->hasPages())
    <div class="admin-pager">
        <span class="admin-pager-info">{{ number_format($items->firstItem()) }}–{{ number_format($items->lastItem()) }} of {{ number_format($items->total()) }} {{ $label }}</span>
        <div class="admin-pager-buttons">
            @if ($items->onFirstPage())
                <span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true"><x-icon name="chevron-left" /> Previous</span>
            @else
                <a class="btn btn-secondary btn-sm" href="{{ $items->previousPageUrl() }}" rel="prev"><x-icon name="chevron-left" /> Previous</a>
            @endif
            @if ($items->hasMorePages())
                <a class="btn btn-secondary btn-sm" href="{{ $items->nextPageUrl() }}" rel="next">Next <x-icon name="chevron-right" /></a>
            @else
                <span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">Next <x-icon name="chevron-right" /></span>
            @endif
        </div>
    </div>
@endif
