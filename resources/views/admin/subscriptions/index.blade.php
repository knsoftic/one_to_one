<x-layouts.admin title="Subscriptions" heading="Subscriptions" subheading="Every paid or granted plan period, newest first.">
    @php
        $statusBadge = [
            'active' => ['badge-success', 'Active'], 'queued' => ['badge-primary', 'Queued'], 'expired' => ['badge-muted', 'Expired'],
            'cancelled' => ['badge-muted', 'Cancelled'], 'revoked' => ['badge-danger', 'Revoked'],
        ];
        $sourceLabel = ['manual' => 'Manual transfer', 'stripe' => 'Card (Stripe)', 'paypal' => 'PayPal', 'play' => 'Google Play', 'admin' => 'Granted by admin'];
    @endphp

    <div class="admin-dash-toolbar">
        <span class="admin-dash-updated">{{ number_format($counts['active']) }} active · {{ number_format($counts['queued']) }} queued</span>
        <a href="{{ route('admin.plans') }}" class="btn btn-secondary btn-sm"><x-icon name="crown" /> Plans</a>
    </div>

    <section class="card admin-tool">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.subscriptions') }}" class="admin-filters">
                <label class="admin-filter-field">
                    <span class="form-label">Status</span>
                    <select name="status" class="form-control admin-select" onchange="this.form.requestSubmit()">
                        <option value="">All</option>
                        @foreach (\App\Models\Subscription::STATUSES as $value)
                            <option value="{{ $value }}" @selected($status === $value)>{{ $statusBadge[$value][1] ?? ucfirst($value) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="admin-filter-field">
                    <span class="form-label">Plan</span>
                    <select name="plan" class="form-control admin-select" onchange="this.form.requestSubmit()">
                        <option value="">All plans</option>
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->id }}" @selected($planId === (int) $plan->id)>{{ $plan->name }}{{ $plan->is_active ? '' : ' (inactive)' }}</option>
                        @endforeach
                    </select>
                </label>
                <noscript><button type="submit" class="btn btn-secondary btn-sm">Filter</button></noscript>
            </form>
        </div>
    </section>

    <section class="card">
        @if ($subscriptions->isEmpty())
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon"><x-icon name="crown" /></div>
                    <div class="empty-state-title">No subscriptions{{ $status || $planId ? ' match' : ' yet' }}</div>
                    <div class="empty-state-text">Subscriptions appear here when someone buys a plan or an admin grants one from the person's Money tab.</div>
                </div>
            </div>
        @else
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr><th>Person</th><th>Plan</th><th>Source</th><th>Status</th><th>Starts</th><th>Ends</th><th>Payment</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($subscriptions as $sub)
                            @php([$badge, $label] = $statusBadge[$sub->status] ?? ['badge-muted', ucfirst($sub->status)])
                            <tr>
                                <td>
                                    @if ($sub->user)
                                        <a href="{{ route('admin.users.show', $sub->user) }}" class="admin-person">
                                            <span class="min-w-0">
                                                <span class="admin-person-name">{{ $sub->user->name }}</span>
                                                <span class="admin-person-meta">{{ $sub->user->phone }}</span>
                                            </span>
                                        </a>
                                    @else
                                        <span class="admin-muted">Deleted account</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($sub->plan)
                                        <a class="admin-link" href="{{ route('admin.plans.edit', $sub->plan) }}">{{ $sub->plan->name }}</a>
                                    @else
                                        <span class="admin-muted">—</span>
                                    @endif
                                    <span class="admin-list-text">
                                        @if ($sub->benefit('ads_off'))<span class="badge badge-muted">No ads</span>@endif
                                        @if ($sub->benefit('verified_badge'))<span class="badge badge-muted">Verified</span>@endif
                                        @if ((int) $sub->benefit('monthly_coins', 0) > 0)<span class="badge badge-muted">+{{ number_format((int) $sub->benefit('monthly_coins')) }} coins</span>@endif
                                    </span>
                                </td>
                                <td>{{ $sourceLabel[$sub->source] ?? ucfirst($sub->source) }}</td>
                                <td>
                                    <span class="badge {{ $badge }}">{{ $label }}</span>
                                    @if ($sub->end_reason)
                                        <span class="admin-person-meta">{{ $sub->end_reason }}{{ $sub->endedBy ? ' · '.$sub->endedBy->name : '' }}</span>
                                    @endif
                                </td>
                                <td class="tabular-nums" title="{{ $sub->starts_at }}">{{ $sub->starts_at?->format('j M Y') }}</td>
                                <td class="tabular-nums" title="{{ $sub->ends_at }}">{{ $sub->ends_at?->format('j M Y') }}</td>
                                <td>
                                    @if ($sub->payment_id && \Illuminate\Support\Facades\Route::has('admin.payments.show'))
                                        <a class="admin-link" href="{{ route('admin.payments.show', $sub->payment_id) }}">#{{ $sub->payment_id }}</a>
                                    @elseif ($sub->payment_id)
                                        #{{ $sub->payment_id }}
                                    @else
                                        <span class="admin-muted">—</span>
                                    @endif
                                </td>
                                <td class="admin-num-cell">
                                    @if ($sub->user && in_array($sub->status, ['active', 'queued'], true))
                                        <form method="POST" action="{{ route('admin.users.plan.end', [$sub->user, $sub]) }}" class="admin-inline-form" data-loading-form>
                                            @csrf
                                            @method('DELETE')
                                            <input name="note" required maxlength="200" class="form-control form-control-sm" placeholder="Why? (recorded)" aria-label="Reason for ending">
                                            <button type="submit" class="btn btn-ghost btn-sm is-danger" title="End this subscription now"><x-icon name="circle-stop" /> End</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-admin.pager :items="$subscriptions" label="subscriptions" />
        @endif
    </section>
</x-layouts.admin>
