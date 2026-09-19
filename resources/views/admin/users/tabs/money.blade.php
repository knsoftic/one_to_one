{{-- Person → Money (Y2): wallet, plan, badge, referrals, ledger, payments and promotions. --}}
@php
    $money = app(\App\Services\MonetisationService::class);
    $paymentBadge = ['pending' => 'badge-muted', 'review' => 'badge-warning', 'paid' => 'badge-warning', 'fulfilled' => 'badge-success', 'failed' => 'badge-danger', 'rejected' => 'badge-danger', 'cancelled' => 'badge-muted', 'refunded' => 'badge-danger'];
    $promoBadge = ['pending' => ['badge-warning', 'Under review'], 'active' => ['badge-success', 'Running'], 'completed' => ['badge-muted', 'Finished'], 'stopped' => ['badge-muted', 'Stopped'], 'rejected' => ['badge-danger', 'Not approved']];
    $referralBadge = ['pending' => 'badge-muted', 'rewarded' => 'badge-success', 'void' => 'badge-danger'];
    $active = $subscriptions->firstWhere('status', 'active');
    $queued = $subscriptions->firstWhere('status', 'queued');
    $ledgerTypes = \App\Models\CoinTransaction::TYPES;
@endphp
<div class="admin-stack" data-admin-wallet>
    <div class="admin-money-stats">
        <div class="admin-money-stat"><span class="admin-money-stat-label">Balance</span><span class="admin-money-stat-value tabular-nums" data-money-balance>{{ number_format($wallet['balance']) }}</span></div>
        <div class="admin-money-stat"><span class="admin-money-stat-label">Withdrawable (earned)</span><span class="admin-money-stat-value tabular-nums">{{ number_format($wallet['withdrawable']) }}</span></div>
        <div class="admin-money-stat"><span class="admin-money-stat-label">Lifetime earned</span><span class="admin-money-stat-value tabular-nums">{{ number_format($wallet['earned_total']) }}</span></div>
        <div class="admin-money-stat"><span class="admin-money-stat-label">Lifetime purchased</span><span class="admin-money-stat-value tabular-nums">{{ number_format($wallet['purchased_total']) }}</span></div>
        <div class="admin-money-stat"><span class="admin-money-stat-label">Lifetime spent</span><span class="admin-money-stat-value tabular-nums">{{ number_format($wallet['spent_total']) }}</span></div>
    </div>

    <div class="admin-grid admin-grid-even">
        {{-- Wallet: freeze + adjust --}}
        <section class="card admin-tool" id="wallet">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="wallet" /> Wallet
                    @if ($wallet['frozen'])
                        <span class="badge badge-danger"><x-icon name="lock" class="icon-xs" /> Frozen</span>
                    @else
                        <span class="badge badge-success">Active</span>
                    @endif
                </h3>
                <p class="admin-muted">A frozen wallet can still receive coins but cannot spend them or promote anything.</p>
                @can('manage', $user)
                    <form method="POST" action="{{ route('admin.users.wallet.freeze', $user) }}" class="admin-money-inline"
                          data-confirm="{{ $wallet['frozen'] ? $user->name.' will be able to spend coins again.' : $user->name.' will not be able to spend coins or promote anything until you unfreeze the wallet.' }}"
                          data-confirm-title="{{ $wallet['frozen'] ? 'Unfreeze the wallet?' : 'Freeze the wallet?' }}" data-confirm-label="{{ $wallet['frozen'] ? 'Unfreeze' : 'Freeze' }}">
                        @csrf
                        <input type="hidden" name="frozen" value="{{ $wallet['frozen'] ? 0 : 1 }}">
                        <button type="submit" class="btn btn-sm {{ $wallet['frozen'] ? 'btn-secondary' : 'btn-danger' }}" data-wallet-freeze>
                            <x-icon name="{{ $wallet['frozen'] ? 'undo-2' : 'lock' }}" /> {{ $wallet['frozen'] ? 'Unfreeze wallet' : 'Freeze wallet' }}
                        </button>
                    </form>

                    <h4 class="admin-section-title mt-4"><x-icon name="hand-coins" /> Adjust coins</h4>
                    <form method="POST" action="{{ route('admin.users.coins.adjust', $user) }}" class="admin-money-form" data-coins-adjust
                          data-confirm="The change is written to their coin history with your note." data-confirm-title="Adjust coins?" data-confirm-label="Apply">
                        @csrf
                        <input type="hidden" name="token" value="{{ old('token', (string) \Illuminate\Support\Str::uuid()) }}" data-coins-token>
                        <div class="form-group">
                            <label for="coins-amount" class="form-label">Amount <span class="optional">(negative removes)</span></label>
                            <input id="coins-amount" name="amount" type="number" step="1" min="-1000000" max="1000000" required class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount') }}" placeholder="+100 or -50">
                            @error('amount')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-group is-wide">
                            <label for="coins-note" class="form-label">Note <span class="optional">(shown in their history)</span></label>
                            <input id="coins-note" name="note" maxlength="160" required class="form-control @error('note') is-invalid @enderror" value="{{ old('note') }}" placeholder="Goodwill for the failed payment">
                            @error('note')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm"><x-icon name="coins" /> Apply</button>
                    </form>
                @endcan
            </div>
        </section>

        {{-- Plan: active + queued, grant / end --}}
        <section class="card admin-tool" id="plan">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="crown" /> Plan</h3>
                @if ($active)
                    <p class="admin-list-title">{{ $active->plan?->name ?? 'Plan' }} <span class="badge badge-success">Active</span></p>
                    <p class="admin-muted">{{ $active->starts_at?->format('j M Y') }} – {{ $active->ends_at?->format('j M Y') }} · {{ ucfirst($active->source) }}
                        @if ($active->payment_id && \Illuminate\Support\Facades\Route::has('admin.payments.show')) · <a class="admin-link" href="{{ route('admin.payments.show', $active->payment_id) }}">payment #{{ $active->payment_id }}</a>@endif
                    </p>
                    @php($benefits = $active->benefits ?? [])
                    <p class="admin-list-text">
                        @if (! empty($benefits['ads_off']))<span class="badge badge-muted">No ads</span>@endif
                        @if (! empty($benefits['verified_badge']))<span class="badge badge-muted">Verified</span>@endif
                        @if (! empty($benefits['monthly_coins']))<span class="badge badge-muted">+{{ number_format($benefits['monthly_coins']) }} coins / month</span>@endif
                    </p>
                @else
                    <p class="admin-muted">No active plan.</p>
                @endif
                @if ($queued)
                    <p class="admin-list-text mt-2">Next: <strong>{{ $queued->plan?->name ?? 'Plan' }}</strong> starts {{ $queued->starts_at?->format('j M Y') }} <span class="badge badge-muted">Queued</span></p>
                @endif

                @can('manage', $user)
                    @foreach ($subscriptions as $sub)
                        <form method="POST" action="{{ route('admin.users.plan.end', ['user' => $user, 'subscription' => $sub]) }}" class="admin-money-form" data-plan-end
                              data-confirm="{{ $user->name }} loses the {{ $sub->plan?->name ?? 'plan' }} benefits now. No refund is made here." data-confirm-title="End the {{ $sub->status === 'queued' ? 'queued ' : '' }}plan?" data-confirm-label="End plan">
                            @csrf
                            @method('DELETE')
                            <div class="form-group is-wide">
                                <label for="plan-end-note-{{ $sub->id }}" class="form-label">End {{ $sub->plan?->name ?? 'plan' }}{{ $sub->status === 'queued' ? ' (queued)' : '' }} — note</label>
                                <input id="plan-end-note-{{ $sub->id }}" name="note" maxlength="160" required class="form-control" placeholder="Why the plan ends">
                            </div>
                            <button type="submit" class="btn btn-danger btn-sm"><x-icon name="x" /> End</button>
                        </form>
                    @endforeach

                    @if ($plans->isNotEmpty())
                        <form method="POST" action="{{ route('admin.users.plan.grant', $user) }}" class="admin-money-form" data-plan-grant
                              data-confirm="The plan is granted without a payment and shows as an admin grant." data-confirm-title="Grant a plan?" data-confirm-label="Grant">
                            @csrf
                            <div class="form-group">
                                <label for="plan-id" class="form-label">Grant plan</label>
                                <select id="plan-id" name="plan_id" class="form-control" required>
                                    @foreach ($plans as $plan)
                                        <option value="{{ $plan->id }}" @selected((int) old('plan_id') === $plan->id)>{{ $plan->name }} ({{ $plan->period === 'year' ? '1 year' : '1 month' }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="plan-days" class="form-label">Days</label>
                                <input id="plan-days" name="days" type="number" min="1" max="3650" required class="form-control @error('days') is-invalid @enderror" value="{{ old('days', 30) }}">
                                @error('days')<p class="form-error">{{ $message }}</p>@enderror
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm"><x-icon name="crown" /> Grant</button>
                        </form>
                    @else
                        <p class="admin-muted mt-2">No active plans to grant — <a class="admin-link" href="{{ route('admin.plans') }}">create one</a>.</p>
                    @endif
                @endcan
            </div>
        </section>

        {{-- Verified badge --}}
        <section class="card admin-tool" id="badge">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="badge-check" /> Verified badge
                    @if ($badge['verified'])
                        <span class="badge badge-success">Verified</span>
                    @else
                        <span class="badge badge-muted">Not verified</span>
                    @endif
                </h3>
                <p class="admin-muted">
                    @if ($badge['source'] === 'plan')
                        Included in the active plan (ends with the plan).
                    @elseif ($badge['verified'] && $badge['lifetime'])
                        {{ ucfirst($badge['source']) }} badge · lifetime.
                    @elseif ($badge['verified'])
                        {{ ucfirst($badge['source']) }} badge · until {{ \Illuminate\Support\Carbon::parse($badge['until'])->format('j M Y') }}.
                    @else
                        Coin price {{ number_format($badge['price']) }} · {{ $badge['days'] > 0 ? $badge['days'].' days' : 'lifetime' }}.
                    @endif
                </p>
                @can('manage', $user)
                    <form method="POST" action="{{ route('admin.users.badge.grant', $user) }}" class="admin-money-form" data-badge-grant
                          data-confirm="The verified tick shows next to their name everywhere." data-confirm-title="Grant the verified badge?" data-confirm-label="Grant">
                        @csrf
                        <div class="form-group">
                            <label for="badge-days" class="form-label">Days <span class="optional">(blank = lifetime)</span></label>
                            <input id="badge-days" name="days" type="number" min="1" max="3650" class="form-control @error('days') is-invalid @enderror" value="{{ old('days') }}" placeholder="365">
                            @error('days')<p class="form-error">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm"><x-icon name="badge-check" /> {{ $user->verified_until ? 'Extend' : 'Grant' }}</button>
                    </form>
                    @if ($user->verified_until)
                        <form method="POST" action="{{ route('admin.users.badge.remove', $user) }}" class="admin-money-inline mt-2" data-badge-remove
                              data-confirm="{{ $user->name }} loses the coin/admin badge. A plan badge stays until the plan ends." data-confirm-title="Remove the verified badge?" data-confirm-label="Remove">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm"><x-icon name="x" /> Remove badge</button>
                        </form>
                    @endif
                @endcan
            </div>
        </section>

        {{-- Referrals --}}
        <section class="card admin-tool" id="referrals">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="gift" /> Referrals</h3>
                <p class="admin-muted">
                    Code <code>{{ $user->referral_code ?: '—' }}</code>
                    · {{ number_format($referralCounts->sum()) }} invited · {{ number_format($referralCounts['rewarded'] ?? 0) }} rewarded · {{ number_format($referralCounts['void'] ?? 0) }} void
                    @if ($referredBy) · invited by <a class="admin-link" href="{{ route('admin.users.show', $referredBy) }}">{{ $referredBy->name }}</a>@endif
                </p>
                @forelse ($referrals as $referral)
                    <div class="admin-list-row is-compact">
                        <span class="admin-list-body">
                            <span class="admin-list-title">
                                @if ($referral->referred)
                                    <a class="admin-link" href="{{ route('admin.users.show', $referral->referred) }}">{{ $referral->referred->name }}</a>
                                @else
                                    Deleted account
                                @endif
                                <span class="badge {{ $referralBadge[$referral->status] ?? 'badge-muted' }}">{{ ucfirst($referral->status) }}</span>
                            </span>
                            <span class="admin-list-text">
                                {{ $referral->created_at?->format('j M Y') }}
                                @if ($referral->status === 'rewarded') · +{{ number_format($referral->referrer_coins) }} coins @endif
                                @if ($referral->status === 'void') · {{ \App\Models\Referral::VOID_REASONS[$referral->void_reason] ?? $referral->void_reason }} @endif
                            </span>
                        </span>
                    </div>
                @empty
                    <p class="admin-muted">Nobody invited yet.</p>
                @endforelse
                <p class="admin-list-text mt-2"><a class="admin-link" href="{{ route('admin.referrals') }}">All referrals</a></p>
            </div>
        </section>
    </div>

    {{-- Ledger --}}
    <section class="card" id="ledger">
        <div class="card-header admin-card-head">
            <div>
                <h2 class="card-title">Coin history</h2>
                <p class="card-subtitle">{{ number_format($ledger->total()) }} entries, newest first.</p>
            </div>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>When</th><th>Type</th><th>Note</th><th class="admin-num-cell">Coins</th><th class="admin-num-cell hidden md:table-cell">Balance after</th><th class="hidden md:table-cell">By</th></tr>
                </thead>
                <tbody>
                    @forelse ($ledger as $row)
                        <tr>
                            <td class="text-sm text-muted tabular-nums">{{ $row->created_at?->format('j M Y H:i') }}</td>
                            <td>{{ $ledgerTypes[$row->type] ?? $row->type }}</td>
                            <td class="text-sm">{{ $row->note ?: '—' }}@if (! empty($row->meta['shortfall'])) <span class="badge badge-warning">short {{ number_format($row->meta['shortfall']) }}</span>@endif</td>
                            <td class="admin-num-cell tabular-nums admin-ledger-amount {{ $row->amount > 0 ? 'is-credit' : 'is-debit' }}">{{ $row->amount > 0 ? '+' : '' }}{{ number_format($row->amount) }}</td>
                            <td class="admin-num-cell tabular-nums hidden md:table-cell">{{ number_format($row->balance_after) }}</td>
                            <td class="hidden md:table-cell text-sm text-muted">{{ $row->creator?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty-state"><div class="empty-state-icon"><x-icon name="coins" /></div><div class="empty-state-title">No coin movements yet</div></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pager :items="$ledger" label="entries" />
    </section>

    <div class="admin-grid admin-grid-even">
        {{-- Payments --}}
        <section class="card" id="payments">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Payments</h2>
                    <p class="card-subtitle">The last {{ $payments->count() }} payment attempts.</p>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>#</th><th>Item</th><th>Gateway</th><th class="admin-num-cell">Amount</th><th>Status</th><th class="hidden md:table-cell">When</th></tr></thead>
                    <tbody>
                        @forelse ($payments as $payment)
                            <tr>
                                <td>@if (\Illuminate\Support\Facades\Route::has('admin.payments.show'))<a class="admin-link" href="{{ route('admin.payments.show', $payment) }}">#{{ $payment->id }}</a>@else #{{ $payment->id }}@endif</td>
                                <td>{{ $payment->itemLabel() }}</td>
                                <td class="text-sm">{{ \App\Models\Payment::GATEWAYS[$payment->gateway] ?? $payment->gateway }}</td>
                                <td class="admin-num-cell tabular-nums">{{ $money->formatMoney($payment->amount_minor, $payment->currency) }}</td>
                                <td><span class="badge {{ $paymentBadge[$payment->status] ?? 'badge-muted' }}">{{ ucfirst($payment->status) }}</span></td>
                                <td class="hidden md:table-cell text-sm text-muted tabular-nums">{{ $payment->created_at?->format('j M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><div class="empty-state"><div class="empty-state-icon"><x-icon name="receipt" /></div><div class="empty-state-title">No payments</div></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Promotions --}}
        <section class="card" id="promotions">
            <div class="card-header admin-card-head">
                <div>
                    <h2 class="card-title">Promotions</h2>
                    <p class="card-subtitle">The last {{ $promotions->count() }} promotions they paid for.</p>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Promotion</th><th>Status</th><th class="admin-num-cell">Coins</th><th class="admin-num-cell">Views</th><th class="hidden md:table-cell">When</th></tr></thead>
                    <tbody>
                        @forelse ($promotions as $promo)
                            @php([$pBadge, $pLabel] = $promoBadge[$promo->status] ?? ['badge-muted', ucfirst($promo->status)])
                            <tr>
                                <td>
                                    @if (\Illuminate\Support\Facades\Route::has('admin.promotions.show'))<a class="admin-link" href="{{ route('admin.promotions.show', $promo) }}">{{ $promo->title ?: $promo->name }}</a>@else{{ $promo->title ?: $promo->name }}@endif
                                    <span class="admin-list-text">{{ \App\Models\AdCampaign::KINDS[$promo->kind] ?? $promo->kind }}</span>
                                </td>
                                <td><span class="badge {{ $pBadge }}">{{ $pLabel }}</span></td>
                                <td class="admin-num-cell tabular-nums">{{ number_format($promo->coins_spent) }}@if ($promo->coins_refunded) <span class="text-muted text-sm">(−{{ number_format($promo->coins_refunded) }})</span>@endif</td>
                                <td class="admin-num-cell tabular-nums">{{ number_format($promo->impressions) }}@if ($promo->view_budget) / {{ number_format($promo->view_budget) }}@endif</td>
                                <td class="hidden md:table-cell text-sm text-muted tabular-nums">{{ $promo->created_at?->format('j M Y') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><div class="empty-state"><div class="empty-state-icon"><x-icon name="megaphone" /></div><div class="empty-state-title">No promotions</div></div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
