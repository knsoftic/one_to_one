<x-layouts.admin title="Plans" heading="Plans" subheading="What people can buy: a period, a price and the benefits it includes.">
    @php($money = app(\App\Services\MonetisationService::class))
    @php($limitLabels = ['upload_mb' => 'Upload', 'group_members' => 'Group', 'broadcast_recipients' => 'Broadcast', 'storage_mb' => 'Storage'])

    <div class="admin-dash-toolbar">
        <span class="admin-dash-updated">{{ number_format($plans->total()) }} {{ $plans->total() === 1 ? 'plan' : 'plans' }} · {{ number_format($activeSubscriptions) }} active {{ $activeSubscriptions === 1 ? 'subscription' : 'subscriptions' }}</span>
        <a href="{{ route('admin.plans.create') }}" class="btn btn-primary btn-sm"><x-icon name="plus" /> New plan</a>
    </div>

    @unless ($money->enabled())
        <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> Paid features are switched off. Turn on <a class="admin-link" href="{{ route('admin.settings') }}#paid">Paid features</a> for people to see these plans.</p>
    @endunless

    <section class="card">
        @if ($plans->isEmpty())
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon"><x-icon name="crown" /></div>
                    <div class="empty-state-title">No plans yet</div>
                    <div class="empty-state-text">Create a plan to sell no ads, a verified badge, monthly coins or bigger limits.</div>
                    <a href="{{ route('admin.plans.create') }}" class="btn btn-primary btn-sm mt-3"><x-icon name="plus" /> New plan</a>
                </div>
            </div>
        @else
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>Plan</th><th>Period</th><th>Price</th><th>Benefits</th><th>Play id</th><th class="admin-num-cell">Active subs</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($plans as $plan)
                            @php($limits = $plan->benefits()['limits'])
                            <tr>
                                <td>
                                    <a href="{{ route('admin.plans.edit', $plan) }}" class="admin-person">
                                        <span class="admin-ad-thumb is-empty"><x-icon name="crown" /></span>
                                        <span class="min-w-0">
                                            <span class="admin-person-name">{{ $plan->name }}</span>
                                            <span class="admin-person-meta">{{ $plan->slug }}{{ $plan->description ? ' · '.$plan->description : '' }}</span>
                                        </span>
                                    </a>
                                </td>
                                <td>{{ \App\Models\Plan::PERIODS[$plan->period] ?? ucfirst($plan->period) }}</td>
                                <td class="tabular-nums">
                                    {{ $money->formatMoney((int) $plan->price_minor, $plan->currency) }}
                                    @if ($plan->price_usd_minor !== null)
                                        <span class="admin-muted">· {{ $money->formatMoney((int) $plan->price_usd_minor, 'USD') }} PayPal</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="admin-list-text">
                                        @if ($plan->ads_off)<span class="badge badge-success">No ads</span>@endif
                                        @if ($plan->verified_badge)<span class="badge badge-primary">Verified</span>@endif
                                        @if ($plan->monthly_coins > 0)<span class="badge badge-warning">+{{ number_format($plan->monthly_coins) }} coins / month</span>@endif
                                        @foreach ($limits as $key => $value)
                                            <span class="badge badge-muted">{{ $limitLabels[$key] ?? $key }} {{ number_format((int) $value) }}{{ str_ends_with($key, '_mb') ? ' MB' : '' }}</span>
                                        @endforeach
                                        @if (! $plan->ads_off && ! $plan->verified_badge && $plan->monthly_coins <= 0 && ! $limits)
                                            <span class="badge badge-warning"><x-icon name="triangle-alert" class="icon-xs" /> No benefits</span>
                                        @endif
                                    </span>
                                </td>
                                <td><code class="admin-code">{{ $plan->play_product_id ?: '—' }}</code></td>
                                <td class="admin-num-cell tabular-nums">{{ number_format($plan->active_subscriptions_count) }}</td>
                                <td>
                                    @if ($plan->is_active)
                                        <span class="badge badge-success">On sale</span>
                                    @else
                                        <span class="badge badge-muted">Inactive</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-admin.pager :items="$plans" label="plans" />
        @endif
    </section>
</x-layouts.admin>
