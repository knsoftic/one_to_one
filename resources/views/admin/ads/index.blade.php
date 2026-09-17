<x-layouts.admin title="Ads" heading="Ads" subheading="Your own sponsored cards, shown in the chat list.">
    @php
        $statusBadge = ['active' => ['badge-success', 'Live'], 'paused' => ['badge-warning', 'Paused'], 'draft' => ['badge-muted', 'Draft']];
    @endphp

    <div class="admin-dash-toolbar">
        <span class="admin-dash-updated">{{ number_format($live) }} live · {{ number_format($impressions) }} views · {{ number_format($clicks) }} clicks</span>
        <a href="{{ route('admin.ads.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" /> New ad</a>
    </div>

    @unless ((bool) \App\Models\AppSetting::get('ads_enabled'))
        <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> Ads are switched off. Turn on <a class="admin-link" href="{{ route('admin.settings') }}#ads">Show ads in the app</a> for these to appear.</p>
    @endunless

    <section class="card">
        @if ($campaigns->isEmpty())
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon"><x-icon name="badge-dollar-sign" /></div>
                    <div class="empty-state-title">No ad campaigns yet</div>
                    <div class="empty-state-text">Create an ad to show a sponsored card in the chat list.</div>
                    <a href="{{ route('admin.ads.create') }}" class="btn btn-primary btn-sm mt-3"><x-icon name="plus" /> New ad</a>
                </div>
            </div>
        @else
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>Campaign</th><th>Status</th><th>Targeting</th><th class="admin-num-cell">Views</th><th class="admin-num-cell">Clicks</th><th class="admin-num-cell">CTR</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $ad)
                            @php([$badge, $label] = $statusBadge[$ad->status] ?? ['badge-muted', ucfirst($ad->status)])
                            <tr>
                                <td>
                                    <a href="{{ route('admin.ads.edit', $ad) }}" class="admin-person">
                                        @if ($ad->imageUrl())
                                            <img src="{{ $ad->imageUrl() }}" alt="" class="admin-ad-thumb">
                                        @else
                                            <span class="admin-ad-thumb is-empty"><x-icon name="badge-dollar-sign" /></span>
                                        @endif
                                        <span class="min-w-0">
                                            <span class="admin-person-name">{{ $ad->name }}</span>
                                            <span class="admin-person-meta">{{ $ad->title }}</span>
                                        </span>
                                    </a>
                                </td>
                                <td><span class="badge {{ $badge }}">{{ $label }}</span></td>
                                <td>
                                    <span class="admin-list-text">
                                        @if ($ad->needsConsent())<span class="badge badge-muted"><x-icon name="target" class="icon-xs" /> Personalised</span>@endif
                                        {{ $ad->countries ? implode(', ', $ad->countries) : 'All countries' }}
                                        @if ($ad->interests){{ ' · '.count($ad->interests).' segments' }}@endif
                                    </span>
                                </td>
                                <td class="admin-num-cell tabular-nums">{{ number_format($ad->impressions) }}</td>
                                <td class="admin-num-cell tabular-nums">{{ number_format($ad->clicks) }}</td>
                                <td class="admin-num-cell tabular-nums">{{ $ad->ctr() }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-admin.pager :items="$campaigns" label="campaigns" />
        @endif
    </section>
</x-layouts.admin>
