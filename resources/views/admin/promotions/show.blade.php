@php
    $statusBadge = [
        'pending' => ['badge-warning', 'Under review'],
        'active' => ['badge-success', 'Running'],
        'completed' => ['badge-muted', 'Finished'],
        'stopped' => ['badge-muted', 'Stopped'],
        'rejected' => ['badge-danger', 'Rejected'],
    ];
    [$badge, $label] = $statusBadge[$campaign->status] ?? ['badge-muted', ucfirst($campaign->status)];
    $kind = \App\Models\AdCampaign::KINDS[$campaign->kind] ?? ucfirst((string) $campaign->kind);
@endphp
<x-layouts.admin :title="$campaign->title" :heading="$campaign->title" :subheading="$kind.' promotion by '.($campaign->owner?->name ?? 'a deleted account')" :back="route('admin.promotions', ['tab' => in_array($campaign->status, ['completed', 'stopped'], true) ? 'completed' : $campaign->status])">
    <div class="admin-ad-layout" data-admin-promotion>
        <div class="admin-stack">
            {{-- The card as people see it --}}
            <section class="card">
                <div class="card-body">
                    <div class="admin-card-head-inline">
                        <h3 class="admin-section-title"><x-icon name="megaphone" /> The card</h3>
                        <span class="badge {{ $badge }}">{{ $label }}</span>
                    </div>
                    <div class="admin-ad-stage admin-ad-stage-row">
                        <div class="admin-ad-ghost-row" aria-hidden="true"></div>
                        @include('admin.partials.ad-card', ['campaign' => $campaign, 'format' => 'row'])
                        <div class="admin-ad-ghost-row" aria-hidden="true"></div>
                    </div>
                    <dl class="admin-kv mt-4">
                        <div><dt>Kind</dt><dd><span class="badge badge-muted">{{ $kind }}</span></dd></div>
                        @if ($target)
                            <div><dt>Target</dt><dd><a class="admin-link" href="{{ $target['url'] }}" @if ($target['external']) target="_blank" rel="noopener noreferrer nofollow" @endif>{{ $target['label'] }} @if ($target['external'])<x-icon name="square-arrow-out-up-right" class="icon-xs" />@endif</a></dd></div>
                        @endif
                        @unless ($campaign->isInternal())
                            <div><dt>Link</dt><dd><a class="admin-link" href="{{ $campaign->target_url }}" target="_blank" rel="noopener noreferrer nofollow">{{ \Illuminate\Support\Str::limit($campaign->target_url, 80) }}</a></dd></div>
                        @endunless
                        <div><dt>Placements</dt><dd>
                            @forelse ($campaign->placementList() as $placement)
                                <span class="badge badge-muted">{{ \App\Support\AdPlacement::label($placement) }}</span>
                            @empty
                                <span class="badge badge-warning"><x-icon name="triangle-alert" class="icon-xs" /> Nowhere</span>
                            @endforelse
                        </dd></div>
                        <div><dt>Submitted</dt><dd>{{ $campaign->created_at?->format('M j, Y g:i A') }}</dd></div>
                        @if ($campaign->ends_at)
                            <div><dt>Ends</dt><dd>{{ $campaign->ends_at->format('M j, Y g:i A') }}</dd></div>
                        @endif
                        @if ($campaign->reviewed_at)
                            <div><dt>Reviewed</dt><dd>{{ $campaign->reviewed_at->format('M j, Y g:i A') }} by {{ $campaign->reviewer?->name ?? 'an admin' }}</dd></div>
                        @endif
                        @if ($campaign->review_note)
                            <div><dt>Note</dt><dd>{{ $campaign->review_note }}</dd></div>
                        @endif
                        @if ($campaign->stop_reason)
                            <div><dt>Stopped because</dt><dd>{{ ['budget' => 'the budget was used up', 'user' => 'the owner stopped it', 'admin' => 'an admin stopped it', 'target_gone' => 'what it promoted is gone', 'rejected' => 'it was rejected', 'owner_deleted' => 'the account was deleted'][$campaign->stop_reason] ?? $campaign->stop_reason }}</dd></div>
                        @endif
                    </dl>
                </div>
            </section>

            {{-- Performance: the same numbers the owner sees --}}
            <section class="card">
                <div class="card-body">
                    <div class="admin-card-head-inline">
                        <h3 class="admin-section-title"><x-icon name="chart-no-axes-column" /> Performance</h3>
                        <span class="admin-muted">{{ number_format($stats['views']) }} views · {{ number_format($stats['taps']) }} taps · {{ $stats['ctr'] }}% CTR</span>
                    </div>
                    @php $pct = min(100, (int) round($stats['views'] / max(1, $stats['budget']) * 100)); @endphp
                    <div class="admin-promo-budget is-wide">
                        <span class="admin-promo-budget-bar"><span style="width: {{ $pct }}%"></span></span>
                        <small class="tabular-nums">{{ number_format($stats['views']) }} of {{ number_format($stats['budget']) }} views · {{ number_format($stats['remaining']) }} left</small>
                    </div>
                    @if ($stats['views'] > 0)
                        <x-admin.bars :series="$days" label="Views per day" unit="views" />
                    @else
                        <p class="admin-muted">No views yet.</p>
                    @endif
                    @if ($stats['placements'])
                        <div class="admin-chips is-wrap mt-3">
                            @foreach ($stats['placements'] as $row)
                                <span class="badge badge-muted">{{ $row['label'] }} · {{ number_format($row['views']) }} views · {{ number_format($row['taps']) }} taps</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>
        </div>

        <div class="admin-stack">
            {{-- Owner and coins --}}
            <section class="card">
                <div class="card-body">
                    <h3 class="admin-section-title"><x-icon name="coins" /> Owner &amp; coins</h3>
                    @if ($campaign->owner)
                        <a href="{{ route('admin.users.show', $campaign->owner) }}" class="admin-person">
                            <x-avatar :user="$campaign->owner" size="md" />
                            <span class="min-w-0">
                                <span class="admin-person-name">{{ $campaign->owner->name }}</span>
                                <span class="admin-person-meta">{{ $campaign->owner->phone }}</span>
                            </span>
                        </a>
                    @else
                        <p class="admin-muted">The account was deleted.</p>
                    @endif
                    <dl class="admin-kv mt-3">
                        <div><dt>Coins held</dt><dd class="tabular-nums">{{ number_format($campaign->coins_spent) }}</dd></div>
                        <div><dt>Rate</dt><dd class="tabular-nums">{{ number_format((int) $campaign->rate_per_1000) }} per 1,000 views</dd></div>
                        <div><dt>View budget</dt><dd class="tabular-nums">{{ number_format((int) $campaign->view_budget) }}</dd></div>
                        <div><dt>Refunded</dt><dd class="tabular-nums">{{ number_format($campaign->coins_refunded) }}{{ $campaign->refunded_at && $campaign->coins_refunded === 0 ? ' (nothing to return)' : '' }}</dd></div>
                    </dl>
                </div>
            </section>

            {{-- Actions --}}
            @if (in_array($campaign->status, ['pending', 'active'], true))
                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="list-checks" /> Review</h3>
                        @if ($campaign->status === 'pending')
                            <p class="admin-muted">Approving puts the card live right away. Rejecting sends the note to the person and gives every coin back.</p>
                            <div class="admin-ad-actions">
                                <form method="POST" action="{{ route('admin.promotions.approve', $campaign) }}" data-loading-form>
                                    @csrf
                                    <button type="submit" class="btn btn-primary"><x-icon name="check" /> Approve</button>
                                </form>
                                <form method="POST" action="{{ route('admin.promotions.reject', $campaign) }}" data-promotion-reject data-loading-form>
                                    @csrf
                                    <input type="hidden" name="note" value="{{ old('note') }}">
                                    <button type="submit" class="btn btn-secondary is-danger"><x-icon name="x" /> Reject…</button>
                                </form>
                            </div>
                            @error('note')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                        @else
                            <p class="admin-muted">Stopping ends the promotion now. Unused coins go back unless you untick the refund (policy breach).</p>
                            <form method="POST" action="{{ route('admin.promotions.stop', $campaign) }}" data-promotion-stop data-loading-form data-confirm="The card stops showing right away." data-confirm-title="Stop this promotion?" data-confirm-label="Stop">
                                @csrf
                                <label class="checkbox mb-3"><input type="checkbox" name="refund" value="1" checked> Refund the unused coins ({{ number_format(max(0, (int) $campaign->coins_spent - (int) ceil((int) $campaign->impressions * (int) $campaign->rate_per_1000 / 1000))) }} right now)</label>
                                <button type="submit" class="btn btn-secondary is-danger"><x-icon name="circle-stop" /> Stop promotion</button>
                            </form>
                        @endif
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-layouts.admin>
