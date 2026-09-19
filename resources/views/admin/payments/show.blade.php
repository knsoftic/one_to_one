@php
    $money = app(\App\Services\MonetisationService::class);
    $statusBadge = [
        'pending' => ['badge-muted', 'Pending'], 'review' => ['badge-warning', 'To review'], 'paid' => ['badge-warning', 'Paid, not delivered'],
        'fulfilled' => ['badge-success', 'Delivered'], 'failed' => ['badge-danger', 'Failed'], 'rejected' => ['badge-danger', 'Rejected'],
        'cancelled' => ['badge-muted', 'Cancelled'], 'refunded' => ['badge-muted', 'Refunded'],
    ];
    [$badge, $label] = $statusBadge[$payment->status] ?? ['badge-muted', ucfirst($payment->status)];
    $gatewayLabel = \App\Models\Payment::GATEWAYS[$payment->gateway] ?? $payment->gateway;
    $reason = $payment->meta['reason'] ?? null;
    $shortfall = (int) ($payment->meta['shortfall'] ?? 0);
    $fulfilError = $payment->meta['fulfil_error'] ?? null;
    $canApprove = $payment->gateway === 'manual' && $payment->status === 'review';
    $canReject = in_array($payment->status, ['review', 'pending'], true);
    $canRefund = in_array($payment->status, ['fulfilled', 'paid'], true);
    $canRetry = $payment->status === 'paid';
@endphp
<x-layouts.admin :title="'Payment #'.$payment->id" :heading="'Payment #'.$payment->id" :subheading="$payment->itemLabel().' · '.$money->formatMoney($payment->amount_minor, $payment->currency).' · '.$gatewayLabel" :back="route('admin.payments', ['status' => $payment->status === 'review' ? 'review' : 'all'])">
    @if ($reason === 'amount_mismatch' && $reported)
        <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> <span><strong>Amount mismatch.</strong> {{ $gatewayLabel }} reported {{ $money->formatMoney($reported['amount_minor'], $reported['currency'] ?: $payment->currency) }} but the item cost {{ $money->formatMoney($payment->amount_minor, $payment->currency) }}. Nothing was credited — check the provider dashboard.</span></p>
    @endif
    @if ($payment->status === 'paid')
        <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> <span><strong>Money taken, nothing delivered.</strong> {{ $fulfilError ? 'Delivery failed: '.$fulfilError : 'Delivery did not finish.' }} Press <strong>Retry delivery</strong> below.</span></p>
    @endif
    @if ($shortfall > 0)
        <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> <span><strong>{{ number_format($shortfall) }} coins short.</strong> They had already been spent when the payment was refunded; the rest was taken back.</span></p>
    @endif
    @if ($duplicates->isNotEmpty())
        <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> <span><strong>Transfer ID "{{ $payment->proof_ref }}" was also used on</strong>
            @foreach ($duplicates as $dup)<a class="admin-link" href="{{ route('admin.payments.show', $dup) }}">#{{ $dup->id }}</a> ({{ $statusBadge[$dup->status][1] ?? $dup->status }}){{ $loop->last ? '' : ', ' }}@endforeach. Check the screenshots before approving.</span></p>
    @endif

    <div class="admin-grid money-payment-layout">
        <div class="admin-stack">
            <section class="card">
                <div class="card-body">
                    <h3 class="admin-section-title"><x-icon name="receipt" /> Payment <span class="badge {{ $badge }}">{{ $label }}</span></h3>
                    <dl class="admin-details">
                        <div><dt><x-icon name="user" /> Person</dt><dd>
                            @if ($payment->user)
                                <a href="{{ route('admin.users.show', $payment->user) }}" class="admin-person"><x-avatar :user="$payment->user" size="sm" /><span class="min-w-0"><span class="admin-person-name">{{ $payment->user->name }}</span><span class="admin-person-meta">{{ $payment->user->phone }} · #{{ $payment->user->id }}</span></span></a>
                            @else
                                <span class="admin-muted">Deleted account</span>
                            @endif
                        </dd></div>
                        <div><dt><x-icon :name="$payment->purpose === 'plan' ? 'crown' : 'coins'" /> Item</dt><dd>{{ $payment->itemLabel() }}@if ($payment->coinPack) <span class="admin-muted">({{ $payment->coinPack->name }})</span>@endif</dd></div>
                        <div><dt><x-icon name="banknote" /> Amount</dt><dd><strong>{{ $money->formatMoney($payment->amount_minor, $payment->currency) }}</strong>
                            @if ($reported)
                                <span class="badge {{ $reported['mismatch'] ? 'badge-danger' : 'badge-success' }}" title="What {{ $gatewayLabel }} reported">{{ $gatewayLabel }}: {{ $money->formatMoney($reported['amount_minor'], $reported['currency'] ?: $payment->currency) }}</span>
                            @endif
                        </dd></div>
                        <div><dt><x-icon name="credit-card" /> Method</dt><dd>{{ $gatewayLabel }}@if ($payment->manual_method) <span class="badge badge-muted">{{ \App\Models\Payment::MANUAL_METHODS[$payment->manual_method] ?? $payment->manual_method }}</span>@endif <span class="badge badge-muted">{{ $payment->platform === 'android' ? 'App' : 'Web' }}</span></dd></div>
                        @if ($payment->proof_ref)
                            <div><dt><x-icon name="ticket" /> Transfer ID</dt><dd><code data-copy-source>{{ $payment->proof_ref }}</code> <button type="button" class="btn btn-ghost btn-sm" data-copy><x-icon name="copy" /> Copy</button></dd></div>
                        @endif
                        @if ($payment->proof_note)
                            <div><dt><x-icon name="message-square-text" /> Their note</dt><dd>{{ $payment->proof_note }}</dd></div>
                        @endif
                        <div><dt><x-icon name="link" /> References</dt><dd>
                            <span class="admin-list-text">
                                <span title="Our reference">{{ $payment->uuid }}</span>
                                @if ($payment->gateway_ref)<span class="badge badge-muted" title="Provider reference">{{ \Illuminate\Support\Str::limit($payment->gateway_ref, 40) }}</span>@endif
                                @if ($payment->gateway_capture_ref)<span class="badge badge-muted" title="Capture / order">{{ \Illuminate\Support\Str::limit($payment->gateway_capture_ref, 40) }}</span>@endif
                                @if ($payment->refund_ref)<span class="badge badge-muted" title="Refund">{{ \Illuminate\Support\Str::limit($payment->refund_ref, 40) }}</span>@endif
                            </span>
                        </dd></div>
                        <div><dt><x-icon name="clock" /> Timeline</dt><dd>
                            <span class="admin-list-text">
                                <span>Created {{ $payment->created_at->format('M j, Y g:i A') }}</span>
                                @if ($payment->paid_at)<span>· Paid {{ $payment->paid_at->format('M j, g:i A') }}</span>@endif
                                @if ($payment->fulfilled_at)<span>· Delivered {{ $payment->fulfilled_at->format('M j, g:i A') }}</span>@endif
                                @if ($payment->refunded_at)<span>· Refunded {{ $payment->refunded_at->format('M j, g:i A') }}</span>@endif
                            </span>
                        </dd></div>
                        @if ($payment->reviewer || $payment->review_note)
                            <div><dt><x-icon name="shield-check" /> Review</dt><dd>{{ $payment->reviewer?->name ?? 'Admin' }}@if ($payment->reviewed_at) · {{ $payment->reviewed_at->format('M j, g:i A') }}@endif @if ($payment->review_note)<span class="admin-muted">— {{ $payment->review_note }}</span>@endif</dd></div>
                        @endif
                        @if ($reason)
                            <div><dt><x-icon name="circle-alert" /> Reason</dt><dd>
                                @php $reasonText = ['play_void' => 'Reversed by Google (refund or chargeback)', 'stripe_refund' => 'Refunded in Stripe', 'paypal_refund' => 'Refunded in PayPal', 'admin_refund' => 'Refunded by an admin', 'amount_mismatch' => 'Amount mismatch', 'no_proof' => 'No screenshot in time', 'abandoned' => 'Checkout abandoned', 'expired' => 'Checkout expired', 'superseded' => 'Started again', 'user_cancelled' => 'Cancelled by the person', 'rejected' => 'Rejected', 'gateway_error' => 'Provider error', 'capture_failed' => 'Capture failed', 'payment_failed' => 'Payment failed', 'cancelled' => 'Cancelled at Google Play'][$reason] ?? $reason; @endphp
                                {{ $reasonText }}@if ($payment->meta['refund_note'] ?? null) <span class="admin-muted">— {{ $payment->meta['refund_note'] }}</span>@endif
                            </dd></div>
                        @endif
                        @if ($payment->subscription)
                            <div><dt><x-icon name="crown" /> Subscription</dt><dd>#{{ $payment->subscription->id }} · {{ ucfirst($payment->subscription->status) }} · {{ $payment->subscription->starts_at?->format('M j') }} → {{ $payment->subscription->ends_at?->format('M j, Y') }}</dd></div>
                        @endif
                    </dl>
                </div>
            </section>

            @if ($payment->gateway === 'manual')
                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="image" /> Screenshot</h3>
                        @if ($hasProof)
                            <a href="{{ route('admin.payments.proof', $payment) }}" target="_blank" rel="noopener" class="money-proof-link">
                                <img src="{{ route('admin.payments.proof', $payment) }}" alt="Transfer screenshot for payment #{{ $payment->id }}" class="money-proof-image" loading="lazy">
                            </a>
                            <p class="admin-muted mt-2"><x-icon name="shield-check" /> Stored privately; each view is recorded in the audit log.</p>
                        @else
                            <p class="admin-muted">{{ $payment->status === 'pending' ? 'No screenshot uploaded yet.' : 'The screenshot was removed (retention period over, or account deleted).' }}</p>
                        @endif
                    </div>
                </section>
            @endif

            @if ($payment->events->isNotEmpty())
                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="history" /> Provider events</h3>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
                                <thead><tr><th>When</th><th>Type</th><th>Event</th><th>State</th></tr></thead>
                                <tbody>
                                    @foreach ($payment->events as $event)
                                        <tr>
                                            <td class="tabular-nums">{{ $event->created_at?->format('M j, g:i A') }}</td>
                                            <td>{{ $event->type }}</td>
                                            <td><code>{{ \Illuminate\Support\Str::limit($event->event_id, 48) }}</code></td>
                                            <td>
                                                @if ($event->processed_at)<span class="badge badge-success">Applied</span>
                                                @elseif ($event->error)<span class="badge badge-danger" title="{{ $event->error }}">Failed</span>
                                                @else<span class="badge badge-muted">Received</span>@endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            @endif
        </div>

        <div class="admin-stack">
            <section class="card admin-tool">
                <div class="card-body">
                    <h3 class="admin-section-title"><x-icon name="hand-coins" /> Actions</h3>
                    @if (! $canApprove && ! $canReject && ! $canRefund && ! $canRetry)
                        <p class="admin-muted">Nothing to do: this payment is {{ strtolower($label) }}.</p>
                    @endif
                    <div class="money-actions">
                        @if ($canApprove)
                            <form method="POST" action="{{ route('admin.payments.approve', $payment) }}" class="money-action-form" data-confirm="{{ $payment->itemLabel() }} will be delivered to {{ $payment->user?->name ?? 'the account' }} right away." data-confirm-title="Approve this payment?" data-confirm-label="Approve">
                                @csrf
                                <input type="text" name="note" maxlength="200" class="form-control" placeholder="Note (optional, only admins see it)">
                                <button type="submit" class="btn btn-primary btn-sm"><x-icon name="check" /> Approve</button>
                            </form>
                        @endif
                        @if ($canReject)
                            <form method="POST" action="{{ route('admin.payments.reject', $payment) }}" class="money-action-form" data-pay-note="reject">
                                @csrf
                                <input type="text" name="note" maxlength="200" required class="form-control @error('note') is-invalid @enderror" placeholder="Why? The person sees this." value="{{ old('note') }}">
                                <button type="submit" class="btn btn-danger btn-sm"><x-icon name="x" /> Reject</button>
                            </form>
                        @endif
                        @if ($canRefund)
                            <form method="POST" action="{{ route('admin.payments.refund', $payment) }}" class="money-action-form" data-pay-note="refund">
                                @csrf
                                <input type="text" name="note" maxlength="200" required class="form-control" placeholder="Refund note (admins only)">
                                @if (in_array($payment->gateway, ['stripe', 'paypal'], true))
                                    <label class="money-check"><input type="checkbox" name="via_gateway" value="1" checked> Also refund the money through {{ $gatewayLabel }}</label>
                                @elseif ($payment->gateway === 'play')
                                    <p class="admin-muted">Refund in the Play Console; the daily check reverses it on its own — or press <strong>Reverse now</strong>.</p>
                                @else
                                    <p class="admin-muted">Send the money back outside the app first, then record it here.</p>
                                @endif
                                <button type="submit" class="btn btn-danger btn-sm"><x-icon name="banknote" /> {{ $payment->gateway === 'play' ? 'Reverse now' : 'Refund' }}</button>
                            </form>
                        @endif
                        @if ($canRetry)
                            <form method="POST" action="{{ route('admin.payments.retry', $payment) }}" class="money-action-form">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-sm"><x-icon name="rocket" /> Retry delivery</button>
                            </form>
                        @endif
                    </div>
                </div>
            </section>

            @if ($payment->user)
                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="receipt" /> Their other payments</h3>
                        @if ($history->isEmpty())
                            <p class="admin-muted">First payment from this account.</p>
                        @else
                            <ul class="money-mini-list">
                                @foreach ($history as $other)
                                    @php([$b, $l] = $statusBadge[$other->status] ?? ['badge-muted', $other->status])
                                    <li><a href="{{ route('admin.payments.show', $other) }}" class="admin-link">#{{ $other->id }}</a> {{ $other->itemLabel() }} · {{ $money->formatMoney($other->amount_minor, $other->currency) }} <span class="badge {{ $b }}">{{ $l }}</span> <span class="admin-muted">{{ $other->created_at->diffForHumans(short: true) }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>
                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="coins" /> Coin ledger (latest)</h3>
                        @if ($ledger->isEmpty())
                            <p class="admin-muted">No coin movements yet.</p>
                        @else
                            <ul class="money-mini-list">
                                @foreach ($ledger as $row)
                                    <li><span class="tabular-nums {{ $row->amount > 0 ? 'text-success' : 'text-danger' }}">{{ $row->amount > 0 ? '+' : '' }}{{ number_format($row->amount) }}</span> {{ $row->note ?: $row->label() }} <span class="admin-muted">· {{ number_format($row->balance_after) }} after · {{ $row->created_at?->diffForHumans(short: true) }}</span></li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-layouts.admin>
