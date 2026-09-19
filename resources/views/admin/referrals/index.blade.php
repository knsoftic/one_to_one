<x-layouts.admin title="Referrals" heading="Referrals" subheading="Who invites the most, sign-ups that look like one person with many SIMs, and everything that was reversed." :back="route('admin.money')">
    @php
        $statusBadge = ['pending' => 'badge-muted', 'rewarded' => 'badge-success', 'void' => 'badge-danger'];
        $tabs = ['' => 'All', 'pending' => 'Pending', 'rewarded' => 'Rewarded', 'void' => 'Void'];
    @endphp

    @unless ($enabled)
        <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> Refer &amp; earn is switched off. New sign-ups are not attached to inviters and pending referrals are voided when the friend verifies. Turn it on under <a class="admin-link" href="{{ route('admin.settings') }}#paid">Paid features</a>.</p>
    @endunless

    <div class="admin-money-stats">
        <div class="admin-money-stat"><span class="admin-money-stat-label">Rewarded</span><span class="admin-money-stat-value tabular-nums">{{ number_format($counts['rewarded']) }}</span></div>
        <div class="admin-money-stat"><span class="admin-money-stat-label">Pending</span><span class="admin-money-stat-value tabular-nums">{{ number_format($counts['pending']) }}</span></div>
        <div class="admin-money-stat"><span class="admin-money-stat-label">Void</span><span class="admin-money-stat-value tabular-nums">{{ number_format($counts['void']) }}</span></div>
        <div class="admin-money-stat"><span class="admin-money-stat-label">Coins taken back</span><span class="admin-money-stat-value tabular-nums">{{ number_format($coinsVoided) }}</span></div>
    </div>

    <div class="admin-grid admin-grid-even">
        <section class="card admin-tool">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="trending-up" /> Top referrers</h3>
                @forelse ($top as $row)
                    <a href="{{ route('admin.users.show', ['user' => $row['user'], 'tab' => 'money']) }}" class="admin-list-row is-compact">
                        <x-avatar :user="$row['user']" size="sm" />
                        <span class="admin-list-body">
                            <span class="admin-list-title">{{ $row['user']->name }}</span>
                            <span class="admin-list-text">{{ number_format($row['rewarded']) }} rewarded · {{ number_format($row['coins']) }} coins</span>
                        </span>
                    </a>
                @empty
                    <p class="admin-muted">Nobody has been rewarded yet.</p>
                @endforelse
            </div>
        </section>

        <section class="card admin-tool">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="triangle-alert" /> Suspicious groups <span class="badge badge-muted">{{ $suspicious->count() }}</span></h3>
                <p class="admin-muted">{{ \App\Http\Controllers\Admin\ReferralController::SUSPICIOUS_MIN }} or more sign-ups from the same connection in the last 7 days. Rewarded ones can be reversed below.</p>
                @forelse ($suspicious as $group)
                    <details class="admin-details">
                        <summary>
                            <span class="admin-ip-hash">{{ substr($group['ip_hash'], 0, 12) }}…</span>
                            <span class="badge badge-warning">{{ $group['count'] }} sign-ups</span>
                            <span class="admin-list-text">last {{ \Illuminate\Support\Carbon::parse($group['last_at'])->diffForHumans() }}</span>
                        </summary>
                        @foreach ($group['rows'] as $referral)
                            <div class="admin-list-row is-compact">
                                <span class="admin-list-body">
                                    <span class="admin-list-title">
                                        {{ $referral->referred?->name ?? 'Deleted account' }}
                                        <span class="text-muted">← {{ $referral->referrer?->name ?? 'Deleted account' }}</span>
                                        <span class="badge {{ $statusBadge[$referral->status] ?? 'badge-muted' }}">{{ ucfirst($referral->status) }}</span>
                                    </span>
                                    <span class="admin-list-text">#{{ $referral->id }} · {{ $referral->created_at?->format('j M Y H:i') }}</span>
                                </span>
                            </div>
                        @endforeach
                    </details>
                @empty
                    <p class="admin-muted">Nothing suspicious in the last 7 days.</p>
                @endforelse
            </div>
        </section>
    </div>

    <section class="card">
        <div class="card-header admin-card-head">
            <div>
                <h2 class="card-title">All referrals</h2>
                <p class="card-subtitle">{{ number_format($rows->total()) }} {{ $status ? $status : '' }} referrals, newest first.</p>
            </div>
            <nav class="admin-tabs" aria-label="Referral status">
                @foreach ($tabs as $key => $label)
                    <a href="{{ route('admin.referrals', $key ? ['status' => $key] : []) }}" @class(['admin-tab', 'is-active' => ($status ?? '') === $key])>{{ $label }} <span class="badge badge-muted">{{ number_format($key ? $counts[$key] : $counts['all']) }}</span></a>
                @endforeach
            </nav>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>#</th><th>Invited</th><th>By</th><th>Status</th><th class="admin-num-cell hidden md:table-cell">Coins</th><th class="hidden md:table-cell">When</th><th></th></tr>
                </thead>
                <tbody>
                    @forelse ($rows as $referral)
                        <tr>
                            <td class="tabular-nums text-muted">{{ $referral->id }}</td>
                            <td>
                                @if ($referral->referred)
                                    <a class="admin-link" href="{{ route('admin.users.show', $referral->referred) }}">{{ $referral->referred->name }}</a>
                                @else
                                    <span class="text-muted">Deleted account</span>
                                @endif
                            </td>
                            <td>
                                @if ($referral->referrer)
                                    <a class="admin-link" href="{{ route('admin.users.show', ['user' => $referral->referrer, 'tab' => 'money']) }}">{{ $referral->referrer->name }}</a>
                                @else
                                    <span class="text-muted">Deleted account</span>
                                @endif
                                <span class="admin-list-text"><code>{{ $referral->code }}</code></span>
                            </td>
                            <td>
                                <span class="badge {{ $statusBadge[$referral->status] ?? 'badge-muted' }}">{{ ucfirst($referral->status) }}</span>
                                @if ($referral->status === 'void')
                                    <span class="admin-list-text">{{ $voidReasons[$referral->void_reason] ?? $referral->void_reason }} @if ($referral->voided_at)· {{ $referral->voided_at->format('j M Y') }}@endif</span>
                                @endif
                            </td>
                            <td class="admin-num-cell tabular-nums hidden md:table-cell">
                                @if ($referral->referrer_coins || $referral->referred_coins)
                                    {{ number_format($referral->referrer_coins) }}@if ($referral->referred_coins) + {{ number_format($referral->referred_coins) }}@endif
                                @else
                                    —
                                @endif
                            </td>
                            <td class="hidden md:table-cell text-sm text-muted tabular-nums">{{ $referral->created_at?->format('j M Y') }}@if ($referral->rewarded_at) · rewarded {{ $referral->rewarded_at->format('j M') }}@endif</td>
                            <td class="admin-num-cell">
                                @if ($referral->status !== 'void')
                                    <form method="POST" action="{{ route('admin.referrals.void', $referral) }}" class="admin-money-inline" data-referral-void
                                          data-confirm="{{ $referral->status === 'rewarded' ? 'The coins are taken back from both people (as far as their wallets allow).' : 'The referral is marked void and will never be rewarded.' }}"
                                          data-confirm-title="Reverse this referral?" data-confirm-label="Reverse">
                                        @csrf
                                        <input name="note" maxlength="160" required class="form-control form-control-sm" placeholder="Reason" aria-label="Reason">
                                        <button type="submit" class="btn btn-danger btn-sm"><x-icon name="undo-2" /> Void</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty-state"><div class="empty-state-icon"><x-icon name="gift" /></div><div class="empty-state-title">No referrals{{ $status ? ' with this status' : ' yet' }}</div></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pager :items="$rows" label="referrals" />
    </section>
</x-layouts.admin>
