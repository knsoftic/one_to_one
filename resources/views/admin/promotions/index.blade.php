<x-layouts.admin title="Promotions" heading="Promotions" subheading="What people paid coins to promote: review, watch and stop.">
    @php
        $statusBadge = [
            'pending' => ['badge-warning', 'Under review'],
            'active' => ['badge-success', 'Running'],
            'completed' => ['badge-muted', 'Finished'],
            'stopped' => ['badge-muted', 'Stopped'],
            'rejected' => ['badge-danger', 'Rejected'],
        ];
    @endphp

    <div class="tabs mb-5" role="tablist" aria-label="Promotion status">
        @foreach ($tabs as $key => [$label])
            <a href="{{ route('admin.promotions', ['tab' => $key]) }}" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @class(['tab', 'is-active' => $tab === $key])>
                {{ $label }} <span class="badge badge-muted">{{ number_format($counts[$key] ?? 0) }}</span>
            </a>
        @endforeach
    </div>

    @unless ((bool) \App\Models\AppSetting::get('paid_enabled') && (bool) \App\Models\AppSetting::get('promote_enabled'))
        <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> Promotions are switched off. Turn on <a class="admin-link" href="{{ route('admin.settings') }}#paid">Paid features → Promote</a> for people to submit new ones. Running ones keep showing while ads are on.</p>
    @endunless

    <section class="card">
        @if ($campaigns->isEmpty())
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon"><x-icon name="megaphone" /></div>
                    <div class="empty-state-title">{{ $tab === 'pending' ? 'Nothing to review' : 'No promotions here' }}</div>
                    <div class="empty-state-text">{{ $tab === 'pending' ? 'New promotions people submit will wait here for your approval.' : 'Promotions in this state will be listed here.' }}</div>
                </div>
            </div>
        @else
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>Promotion</th><th>Kind</th><th>Owner</th><th class="admin-num-cell">Coins</th><th>Budget</th><th class="admin-num-cell">Taps</th><th class="admin-num-cell">CTR</th><th>Submitted</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($campaigns as $promo)
                            @php
                                [$badge, $label] = $statusBadge[$promo->status] ?? ['badge-muted', ucfirst($promo->status)];
                                $budget = max(1, (int) $promo->view_budget);
                                $pct = min(100, (int) round((int) $promo->impressions / $budget * 100));
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('admin.promotions.show', $promo) }}" class="admin-person">
                                        @if ($promo->imageUrl())
                                            <img src="{{ $promo->imageUrl() }}" alt="" class="admin-ad-thumb">
                                        @else
                                            <span class="admin-ad-thumb is-empty"><x-icon name="megaphone" /></span>
                                        @endif
                                        <span class="min-w-0">
                                            <span class="admin-person-name">{{ $promo->title }}</span>
                                            <span class="admin-person-meta"><span class="badge {{ $badge }}">{{ $label }}</span></span>
                                        </span>
                                    </a>
                                </td>
                                <td><span class="badge badge-muted">{{ \App\Models\AdCampaign::KINDS[$promo->kind] ?? ucfirst((string) $promo->kind) }}</span></td>
                                <td>
                                    @if ($promo->owner)
                                        <a href="{{ route('admin.users.show', $promo->owner) }}" class="admin-link">{{ $promo->owner->name }}</a>
                                    @else
                                        <span class="admin-muted">Deleted account</span>
                                    @endif
                                </td>
                                <td class="admin-num-cell tabular-nums">{{ number_format($promo->coins_spent) }}</td>
                                <td>
                                    <div class="admin-promo-budget" title="{{ number_format($promo->impressions) }} of {{ number_format((int) $promo->view_budget) }} views">
                                        <span class="admin-promo-budget-bar"><span style="width: {{ $pct }}%"></span></span>
                                        <small class="tabular-nums">{{ number_format($promo->impressions) }} / {{ number_format((int) $promo->view_budget) }}</small>
                                    </div>
                                </td>
                                <td class="admin-num-cell tabular-nums">{{ number_format($promo->clicks) }}</td>
                                <td class="admin-num-cell tabular-nums">{{ $promo->ctr() }}%</td>
                                <td><time class="admin-muted" datetime="{{ $promo->created_at?->toIso8601String() }}">{{ $promo->created_at?->diffForHumans() }}</time></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-admin.pager :items="$campaigns" label="promotions" />
        @endif
    </section>
</x-layouts.admin>
