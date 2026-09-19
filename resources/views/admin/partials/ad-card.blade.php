{{-- The sponsored / promoted card as the app shows it (admin preview). --}}
@props(['campaign', 'format' => 'row'])
@php
    $promoted = $campaign->isPromotion();
    $tag = $promoted
        ? 'Promoted'.($campaign->sponsor ? ' · '.$campaign->sponsor : '')
        : 'Sponsored'.($campaign->sponsor ? ' · '.$campaign->sponsor : '');
@endphp
<div class="ad-card ad-card-{{ $format }} admin-ad-preview" data-ad-preview>
    <div class="ad-card-media" data-ad-preview-media @if ($campaign->imageUrl()) style="background-image:url('{{ $campaign->imageUrl() }}')" @endif></div>
    <div class="ad-card-body">
        <span class="ad-card-tag" data-ad-preview-tag>{{ $tag }}</span>
        <span class="ad-card-title" data-ad-preview-title>{{ $campaign->title ?: 'Your headline' }}</span>
        <span class="ad-card-text" data-ad-preview-body>{{ $campaign->body }}</span>
        <span class="ad-card-cta" data-ad-preview-cta>{{ $campaign->cta_label ?: 'Learn more' }} <x-icon name="{{ $campaign->isInternal() ? 'arrow-right' : 'square-arrow-out-up-right' }}" /></span>
    </div>
</div>
