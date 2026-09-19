@php
    $money = app(\App\Services\MonetisationService::class);
    $cards = [
        ['label' => 'Revenue '.$days.' d', 'value' => $stats['revenue'], 'raw' => false, 'icon' => 'banknote', 'tone' => 'success', 'hint' => number_format($stats['revenue_count']).' delivered payments', 'href' => route('admin.payments', ['status' => 'fulfilled']), 'spark' => true],
        ['label' => 'Coins sold '.$days.' d', 'value' => $stats['coins_sold'], 'icon' => 'coins', 'tone' => 'amber', 'hint' => 'Bought through packs', 'href' => route('admin.payments', ['status' => 'fulfilled', 'purpose' => 'coins'])],
        ['label' => 'Active plans', 'value' => $stats['active_subs'], 'icon' => 'crown', 'tone' => 'violet', 'hint' => number_format($stats['queued_subs']).' queued to start', 'href' => route('admin.subscriptions')],
        ['label' => 'Coins in wallets', 'value' => $stats['coins_liability'], 'icon' => 'wallet', 'tone' => 'sky', 'hint' => number_format($stats['coins_withdrawable']).' earned (withdrawable)', 'href' => null],
    ];
    $glance = [
        ['label' => 'Payments to review', 'value' => $stats['reviews'], 'icon' => 'receipt', 'tone' => $stats['reviews'] ? 'danger' : 'primary', 'hint' => 'Manual transfers with a screenshot', 'href' => route('admin.payments', ['status' => 'review'])],
        ['label' => 'Promotions to review', 'value' => $stats['promo_reviews'], 'icon' => 'megaphone', 'tone' => $stats['promo_reviews'] ? 'danger' : 'primary', 'hint' => 'Waiting for approval', 'href' => route('admin.promotions')],
        ['label' => 'Referrals rewarded 7 d', 'value' => $stats['referrals_7d'], 'icon' => 'gift', 'tone' => 'success', 'hint' => 'Friends who verified their number', 'href' => route('admin.referrals')],
    ];
    $flagCount = collect($flags)->sum(fn ($rows) => $rows->count());
@endphp
<x-layouts.admin title="Money" heading="Money" subheading="Revenue, coins, plans and what needs a look.">
    @foreach ($warnings as $warning)
        <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> <span>{{ $warning['text'] }} @if ($warning['href'])<a class="admin-link" href="{{ $warning['href'] }}">Open settings</a>@endif</span></p>
    @endforeach

    <div class="kpi-grid">
        @foreach ($cards as $card)
            <{{ $card['href'] ? 'a' : 'div' }} @if ($card['href']) href="{{ $card['href'] }}" @endif class="kpi-card" data-tone="{{ $card['tone'] }}">
                <span class="kpi-head">
                    <span class="stat-icon"><x-icon :name="$card['icon']" /></span>
                    <span class="kpi-label">{{ $card['label'] }}</span>
                </span>
                <span class="kpi-value">{{ ($card['raw'] ?? true) ? number_format($card['value']) : $card['value'] }}</span>
                <span class="kpi-foot">
                    <span class="kpi-hint">{{ $card['hint'] }}</span>
                    @if ($card['spark'] ?? false)
                        <x-admin.sparkline :values="collect($series)->pluck('count')->all()" :label="'Revenue per day, last '.$days.' days'" />
                    @endif
                </span>
            </{{ $card['href'] ? 'a' : 'div' }}>
        @endforeach
    </div>

    <section class="card">
        <div class="glance-grid">
            @foreach ($glance as $card)
                <a href="{{ $card['href'] }}" class="glance-item" data-tone="{{ $card['tone'] }}">
                    <span class="glance-icon"><x-icon :name="$card['icon']" /></span>
                    <span class="glance-text">
                        <span class="glance-label">{{ $card['label'] }}</span>
                        <span class="glance-hint">{{ $card['hint'] }}</span>
                    </span>
                    <span class="glance-value">{{ number_format($card['value']) }}</span>
                </a>
            @endforeach
        </div>
    </section>

    <div class="admin-grid admin-grid-even">
        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Revenue per day</h2>
                    <p class="card-subtitle">{{ $currency }} only, delivered payments, last {{ $days }} days.</p>
                </div>
                <x-icon name="trending-up" class="text-subtle" />
            </div>
            <div class="card-body">
                @if (collect($series)->sum('count') > 0)
                    <x-admin.bars :series="collect($series)->map(fn ($p) => ['label' => $p['label'], 'title' => $p['title'], 'count' => intdiv($p['count'], 100)])->all()" :label="'Revenue per day for the last '.$days.' days'" :unit="$currency" height="10rem" />
                @else
                    <p class="admin-muted">No revenue in the last {{ $days }} days.</p>
                @endif
            </div>
        </section>
        <section class="card">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">By method</h2>
                    <p class="card-subtitle">Delivered payments, last {{ $days }} days, per currency.</p>
                </div>
                <x-icon name="credit-card" class="text-subtle" />
            </div>
            @if (empty($revenue))
                <div class="card-body"><p class="admin-muted">Nothing sold yet.</p></div>
            @else
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead><tr><th>Method</th><th>Currency</th><th class="admin-num-cell">Payments</th><th class="admin-num-cell">Total</th></tr></thead>
                        <tbody>
                            @foreach ($revenue as $row)
                                <tr><td>{{ $row['gateway'] }}</td><td>{{ $row['currency'] }}</td><td class="admin-num-cell tabular-nums">{{ number_format($row['count']) }}</td><td class="admin-num-cell tabular-nums">{{ $row['total'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <section class="card admin-tool" id="flags">
        <div class="card-body">
            <h3 class="admin-section-title {{ $flagCount ? 'is-danger' : '' }}"><x-icon name="circle-alert" /> Flags @if ($flagCount)<span class="badge badge-danger">{{ number_format($flagCount) }}</span>@endif</h3>
            @if (! $flagCount)
                <p class="admin-muted"><x-icon name="check" /> Nothing needs attention: no mismatches, no undelivered payments, no clawback shortfalls, no failed callbacks.</p>
            @else
                <div class="money-flags">
                    @if ($flags['undelivered']->isNotEmpty())
                        <div class="money-flag">
                            <h4 class="money-flag-title"><x-icon name="rocket" /> Paid but not delivered</h4>
                            <ul class="money-mini-list">
                                @foreach ($flags['undelivered'] as $p)
                                    <li><a class="admin-link" href="{{ route('admin.payments.show', $p) }}">#{{ $p->id }}</a> {{ $p->itemLabel() }} · {{ $money->formatMoney($p->amount_minor, $p->currency) }} · {{ \App\Models\Payment::GATEWAYS[$p->gateway] ?? $p->gateway }} <span class="admin-muted">paid {{ $p->paid_at?->diffForHumans() }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($flags['attention']->isNotEmpty())
                        <div class="money-flag">
                            <h4 class="money-flag-title"><x-icon name="circle-alert" /> Money that needs a decision ({{ $days }} days)</h4>
                            <ul class="money-mini-list">
                                @foreach ($flags['attention'] as $p)
                                    @php
                                        $why = match (true) {
                                            ($p->meta['needs_review'] ?? null) !== null => 'paid at the provider but could not be settled here',
                                            ($p->meta['paid_after_cancel'] ?? null) !== null => 'paid after it was cancelled here — delivered, check for a double payment',
                                            ($p->meta['plan_kept'] ?? null) !== null => 'refunded: one period came off a subscription other payments also paid for',
                                            default => 'partly refunded at the provider ('.$money->formatMoney((int) ($p->meta['refunded_minor'] ?? 0), $p->currency).' of '.$money->formatMoney($p->amount_minor, $p->currency).') — nothing was clawed back',
                                        };
                                    @endphp
                                    <li><a class="admin-link" href="{{ route('admin.payments.show', $p) }}">#{{ $p->id }}</a> {{ $p->itemLabel() }} · {{ \App\Models\Payment::GATEWAYS[$p->gateway] ?? $p->gateway }} <span class="admin-muted">— {{ $why }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($flags['mismatch']->isNotEmpty())
                        <div class="money-flag">
                            <h4 class="money-flag-title"><x-icon name="triangle-alert" /> Amount mismatches (7 days)</h4>
                            <ul class="money-mini-list">
                                @foreach ($flags['mismatch'] as $p)
                                    <li><a class="admin-link" href="{{ route('admin.payments.show', $p) }}">#{{ $p->id }}</a> expected {{ $money->formatMoney($p->amount_minor, $p->currency) }}, {{ \App\Models\Payment::GATEWAYS[$p->gateway] ?? $p->gateway }} reported {{ isset($p->meta['reported']['amount_minor']) ? $money->formatMoney((int) $p->meta['reported']['amount_minor'], $p->meta['reported']['currency'] ?? $p->currency) : (($p->meta['reported']['amount'] ?? '?').' '.($p->meta['reported']['currency'] ?? '')) }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($flags['shortfall']->isNotEmpty())
                        <div class="money-flag">
                            <h4 class="money-flag-title"><x-icon name="hand-coins" /> Refunds with coins already spent ({{ $days }} days)</h4>
                            <ul class="money-mini-list">
                                @foreach ($flags['shortfall'] as $p)
                                    <li><a class="admin-link" href="{{ route('admin.payments.show', $p) }}">#{{ $p->id }}</a> {{ $p->itemLabel() }} · <strong>{{ number_format((int) $p->meta['shortfall']) }} coins short</strong> <span class="admin-muted">· {{ $p->user?->name ?? 'deleted account' }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($flags['callback_errors']->isNotEmpty())
                        <div class="money-flag">
                            <h4 class="money-flag-title"><x-icon name="plug-zap" /> Provider callbacks that failed (7 days)</h4>
                            <ul class="money-mini-list">
                                @foreach ($flags['callback_errors'] as $e)
                                    <li>{{ ucfirst($e->gateway) }} · {{ $e->type }} @if ($e->payment_id)· <a class="admin-link" href="{{ route('admin.payments.show', $e->payment_id) }}">#{{ $e->payment_id }}</a>@endif <span class="admin-muted">— {{ \Illuminate\Support\Str::limit($e->error, 120) }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </section>
</x-layouts.admin>
