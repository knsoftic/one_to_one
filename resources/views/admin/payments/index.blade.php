@php
    $money = app(\App\Services\MonetisationService::class);
    $statusBadge = [
        'pending' => ['badge-muted', 'Pending'], 'review' => ['badge-warning', 'To review'], 'paid' => ['badge-warning', 'Paid, not delivered'],
        'fulfilled' => ['badge-success', 'Delivered'], 'failed' => ['badge-danger', 'Failed'], 'rejected' => ['badge-danger', 'Rejected'],
        'cancelled' => ['badge-muted', 'Cancelled'], 'refunded' => ['badge-muted', 'Refunded'],
    ];
    $active = $filters['status'];
@endphp
<x-layouts.admin title="Payments" heading="Payments" subheading="Manual transfers to review, and every card, PayPal and Google Play payment.">
    <div class="admin-dash-toolbar">
        <nav class="admin-tabs" aria-label="Payment status">
            @foreach ($tabs as $key => $label)
                @php $n = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0); @endphp
                @if ($n > 0 || in_array($key, ['review', 'paid', 'fulfilled', 'all'], true))
                    <a href="{{ route('admin.payments', array_filter(['status' => $key] + $filters)) }}" @class(['admin-tab', 'is-active' => $active === $key])>{{ $label }} @if ($n)<span class="badge {{ $key === 'review' || $key === 'paid' ? 'badge-warning' : 'badge-muted' }}">{{ number_format($n) }}</span>@endif</a>
                @endif
            @endforeach
        </nav>
    </div>

    <form method="GET" action="{{ route('admin.payments') }}" class="admin-filters">
        <input type="hidden" name="status" value="{{ $active }}">
        <div class="input-wrap admin-search">
            <x-icon name="search" />
            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control" placeholder="Person, payment #, reference or transfer ID" maxlength="100" aria-label="Search payments">
        </div>
        <select name="gateway" class="form-control admin-select" aria-label="Method">
            <option value="">All methods</option>
            @foreach (\App\Models\Payment::GATEWAYS as $key => $label)
                <option value="{{ $key }}" @selected(($filters['gateway'] ?? '') === $key)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="purpose" class="form-control admin-select" aria-label="Bought">
            <option value="">Plans and coins</option>
            <option value="plan" @selected(($filters['purpose'] ?? '') === 'plan')>Plans</option>
            <option value="coins" @selected(($filters['purpose'] ?? '') === 'coins')>Coins</option>
        </select>
        <input type="date" name="date" value="{{ $filters['date'] ?? '' }}" class="form-control admin-select" aria-label="Day">
        <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
        @if (($filters['q'] ?? '') !== '' || ($filters['gateway'] ?? '') || ($filters['purpose'] ?? '') || ($filters['date'] ?? ''))
            <a href="{{ route('admin.payments', ['status' => $active]) }}" class="btn btn-ghost btn-sm">Clear</a>
        @endif
    </form>

    <section class="card">
        @if ($payments->isEmpty())
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-state-icon"><x-icon name="receipt" /></div>
                    <div class="empty-state-title">{{ $active === 'review' ? 'Nothing to review' : 'No payments here' }}</div>
                    <div class="empty-state-text">{{ $active === 'review' ? 'Manual transfers with a screenshot appear here until you approve or reject them.' : 'Try another status or clear the filters.' }}</div>
                </div>
            </div>
        @else
            <div class="admin-table-wrap">
                <table class="admin-table money-payments-table">
                    <thead>
                        <tr><th>#</th><th>Person</th><th>Item</th><th>Method</th><th class="admin-num-cell">Amount</th><th>Status</th><th>Platform</th><th>Created</th><th>Reviewer</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($payments as $payment)
                            @php([$badge, $label] = $statusBadge[$payment->status] ?? ['badge-muted', ucfirst($payment->status)])
                            <tr>
                                <td><a href="{{ route('admin.payments.show', $payment) }}" class="admin-link tabular-nums">#{{ $payment->id }}</a></td>
                                <td>
                                    @if ($payment->user)
                                        <a href="{{ route('admin.users.show', $payment->user) }}" class="admin-person">
                                            <x-avatar :user="$payment->user" size="sm" />
                                            <span class="min-w-0">
                                                <span class="admin-person-name">{{ $payment->user->name }}</span>
                                                <span class="admin-person-meta">{{ $payment->user->phone }}</span>
                                            </span>
                                        </a>
                                    @else
                                        <span class="admin-muted">Deleted account</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="admin-list-text">
                                        <x-icon :name="$payment->purpose === 'plan' ? 'crown' : 'coins'" class="icon-xs" /> {{ $payment->itemLabel() }}
                                    </span>
                                </td>
                                <td>
                                    <span class="admin-list-text">{{ \App\Models\Payment::GATEWAYS[$payment->gateway] ?? $payment->gateway }}
                                        @if ($payment->manual_method)<span class="badge badge-muted">{{ \App\Models\Payment::MANUAL_METHODS[$payment->manual_method] ?? $payment->manual_method }}</span>@endif
                                    </span>
                                </td>
                                <td class="admin-num-cell tabular-nums">{{ $money->formatMoney($payment->amount_minor, $payment->currency) }}</td>
                                <td>
                                    <span class="badge {{ $badge }}">{{ $label }}</span>
                                    @if (($payment->meta['reason'] ?? null) === 'amount_mismatch')<span class="badge badge-danger" title="The provider reported a different amount"><x-icon name="triangle-alert" class="icon-xs" /> Mismatch</span>@endif
                                    @if (($payment->meta['shortfall'] ?? 0) > 0)<span class="badge badge-danger" title="Coins already spent at refund time">−{{ number_format($payment->meta['shortfall']) }} short</span>@endif
                                </td>
                                <td><span class="badge badge-muted">{{ $payment->platform === 'android' ? 'App' : 'Web' }}</span></td>
                                <td class="tabular-nums" title="{{ $payment->created_at->format('M j, Y g:i A') }}">{{ $payment->created_at->diffForHumans(short: true) }}</td>
                                <td>{{ $payment->reviewer?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <x-admin.pager :items="$payments" label="payments" />
        @endif
    </section>
</x-layouts.admin>
