@php
    $editing = $plan->exists;
    $money = app(\App\Services\MonetisationService::class);
    $limits = old('limits', $plan->limits ?? []);
    $limitFields = [
        'upload_mb' => ['Upload size', 'MB per file', (int) ceil(max((int) config('chat.uploads.image.max_kb', 0), (int) config('chat.uploads.video.max_kb', 0), (int) config('chat.uploads.document.max_kb', 0)) / 1024)],
        'group_members' => ['Group members', 'people per group', (int) config('chat.groups.max_members', 256)],
        'broadcast_recipients' => ['Broadcast recipients', 'people per list', (int) config('chat.groups.max_broadcast_recipients', 256)],
        'storage_mb' => ['Storage', 'MB of sent files', (int) config('chat.storage.default_mb', 0)],
    ];
    $coinsOn = (int) old('monthly_coins', $plan->monthly_coins) > 0;
    $limitsOn = (bool) array_filter($limits, fn ($v) => $v !== null && $v !== '');
    $price = old('price', $plan->exists ? $plan->price_minor / 100 : '');
    $priceUsd = old('price_usd', $plan->price_usd_minor !== null ? $plan->price_usd_minor / 100 : '');
@endphp
<x-layouts.admin :title="$editing ? $plan->name : 'New plan'" :heading="$editing ? $plan->name : 'New plan'" subheading="A paid period with the benefits it includes." :back="route('admin.plans')">
    @if ($editing && ($subscriptions ?? 0) > 0)
        <p class="admin-backup-note"><x-icon name="info" /> {{ number_format($activeSubscriptions) }} active of {{ number_format($subscriptions) }} {{ $subscriptions === 1 ? 'subscription' : 'subscriptions' }} on this plan. Changes here apply to new purchases only — everyone already subscribed keeps exactly what they bought. <a class="admin-link" href="{{ route('admin.subscriptions', ['plan' => $plan->id]) }}">See them</a></p>
    @endif

    <form method="POST" action="{{ $editing ? route('admin.plans.update', $plan) : route('admin.plans.store') }}" class="admin-plan-editor" data-plan-editor data-loading-form novalidate>
        @csrf
        @if ($editing) @method('PUT') @endif

        <div class="admin-ad-layout">
            <div class="admin-stack">
                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="crown" /> The plan</h3>
                        <div class="admin-form">
                            <div class="business-grid">
                                <div class="form-group">
                                    <label for="plan-name" class="form-label">Name</label>
                                    <input id="plan-name" name="name" maxlength="60" required class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $plan->name) }}" placeholder="Pro" data-plan-name>
                                    @error('name')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                </div>
                                <div class="form-group">
                                    <label for="plan-slug" class="form-label">Slug <span class="optional">(in links and reports)</span></label>
                                    <input id="plan-slug" name="slug" maxlength="40" required class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug', $plan->slug) }}" placeholder="pro-monthly" pattern="[a-z0-9]+(-[a-z0-9]+)*" data-plan-slug>
                                    @error('slug')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="plan-description" class="form-label">Description <span class="optional">(optional, shown under the name)</span></label>
                                <input id="plan-description" name="description" maxlength="200" class="form-control @error('description') is-invalid @enderror" value="{{ old('description', $plan->description) }}" placeholder="Everything for power users">
                                @error('description')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                            </div>
                            <div class="business-grid">
                                <div class="form-group">
                                    <label for="plan-period" class="form-label">Period</label>
                                    <select id="plan-period" name="period" class="form-control">
                                        @foreach (\App\Models\Plan::PERIODS as $value => $label)
                                            <option value="{{ $value }}" @selected(old('period', $plan->period) === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="plan-price" class="form-label">Price</label>
                                    <div class="admin-plan-price">
                                        <input id="plan-price" type="number" name="price" min="0" step="0.01" required class="form-control @error('price') is-invalid @enderror" value="{{ $price }}" placeholder="499">
                                        <input id="plan-currency" name="currency" maxlength="3" required class="form-control admin-plan-currency @error('currency') is-invalid @enderror" value="{{ old('currency', $plan->currency ?: $money->currency()) }}" aria-label="Currency" placeholder="PKR">
                                    </div>
                                    <p class="form-hint">Per period, in the currency's main unit (499 or 4.99).</p>
                                    @error('price')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                    @error('currency')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                </div>
                                <div class="form-group">
                                    <label for="plan-price-usd" class="form-label">PayPal price (USD) <span class="optional">(optional)</span></label>
                                    <input id="plan-price-usd" type="number" name="price_usd" min="0" step="0.01" class="form-control @error('price_usd') is-invalid @enderror" value="{{ $priceUsd }}" placeholder="4.99">
                                    <p class="form-hint">PayPal cannot charge PKR. Leave empty to hide PayPal for this plan.</p>
                                    @error('price_usd')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="sparkles" /> Benefits</h3>
                        <p class="admin-muted">What a subscriber gets. Copied onto every subscription at purchase, so editing this later never changes a running one.</p>
                        <div class="admin-placements">
                            <label class="admin-setting-row">
                                <span><strong>No ads</strong><small>No sponsored cards and no promotions anywhere in the app.</small></span>
                                <span class="switch"><input type="checkbox" name="ads_off" value="1" @checked(old('ads_off', $plan->ads_off)) aria-label="No ads"><span class="switch-track"></span></span>
                            </label>
                            <label class="admin-setting-row">
                                <span><strong>Verified badge</strong><small>A tick next to their name while the plan runs.</small></span>
                                <span class="switch"><input type="checkbox" name="verified_badge" value="1" @checked(old('verified_badge', $plan->verified_badge)) aria-label="Verified badge"><span class="switch-track"></span></span>
                            </label>
                            <label class="admin-setting-row">
                                <span><strong>Free coins every month</strong><small>Credited on the day the plan starts and every month after; a yearly plan gets 12 grants.</small></span>
                                <span class="switch"><input type="checkbox" value="1" @checked($coinsOn) aria-label="Free coins every month" data-plan-toggle="coins"><span class="switch-track"></span></span>
                            </label>
                            <div class="admin-plan-fields" data-plan-fields="coins" @unless ($coinsOn) hidden @endunless>
                                <div class="form-group">
                                    <label for="plan-coins" class="form-label">Coins per month</label>
                                    <input id="plan-coins" type="number" name="monthly_coins" min="0" max="1000000" class="form-control @error('monthly_coins') is-invalid @enderror" value="{{ old('monthly_coins', $plan->monthly_coins ?: '') }}" placeholder="100">
                                    @error('monthly_coins')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                </div>
                            </div>
                            <label class="admin-setting-row">
                                <span><strong>Bigger limits</strong><small>Raise the app's limits for subscribers. A plan can only raise a limit, never lower it.</small></span>
                                <span class="switch"><input type="checkbox" value="1" @checked($limitsOn) aria-label="Bigger limits" data-plan-toggle="limits"><span class="switch-track"></span></span>
                            </label>
                            <div class="admin-plan-fields" data-plan-fields="limits" @unless ($limitsOn) hidden @endunless>
                                <div class="business-grid">
                                    @foreach ($limitFields as $key => [$label, $unit, $default])
                                        <div class="form-group">
                                            <label for="plan-limit-{{ $key }}" class="form-label">{{ $label }} <span class="optional">({{ $unit }})</span></label>
                                            <input id="plan-limit-{{ $key }}" type="number" name="limits[{{ $key }}]" min="1" class="form-control @error('limits.'.$key) is-invalid @enderror" value="{{ $limits[$key] ?? '' }}" placeholder="{{ $default ?: 'unlimited' }}">
                                            <p class="form-hint">Blank = app default ({{ $default ? number_format($default) : 'unlimited' }}).</p>
                                            @error('limits.'.$key)<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <div class="admin-stack">
                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="smartphone" /> Google Play</h3>
                        <div class="admin-form">
                            <div class="form-group">
                                <label for="plan-play" class="form-label">Play product id <span class="optional">(optional)</span></label>
                                <input id="plan-play" name="play_product_id" maxlength="80" class="form-control @error('play_product_id') is-invalid @enderror" value="{{ old('play_product_id', $plan->play_product_id) }}" placeholder="plan_pro_month" autocapitalize="off" spellcheck="false">
                                <p class="form-hint">A consumable in-app product with the same id in Play Console. Without it, the plan is not for sale in the Android app.</p>
                                @error('play_product_id')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <div class="card-body">
                        <h3 class="admin-section-title"><x-icon name="sliders-horizontal" /> Availability</h3>
                        <div class="admin-placements">
                            <label class="admin-setting-row">
                                <span><strong>On sale</strong><small>Off = nobody new can buy it; running subscriptions carry on.</small></span>
                                <span class="switch"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $plan->is_active)) aria-label="On sale"><span class="switch-track"></span></span>
                            </label>
                        </div>
                        <div class="admin-form mt-3">
                            <div class="form-group">
                                <label for="plan-sort" class="form-label">Order</label>
                                <input id="plan-sort" type="number" name="sort" min="0" max="65535" class="form-control @error('sort') is-invalid @enderror" value="{{ old('sort', $plan->sort ?? 0) }}">
                                <p class="form-hint">Lower numbers are listed first.</p>
                                @error('sort')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                            </div>
                        </div>
                        <div class="admin-ad-actions">
                            <button type="submit" class="btn btn-primary"><x-icon name="check" /> {{ $editing ? 'Save plan' : 'Create plan' }}</button>
                            <a href="{{ route('admin.plans') }}" class="btn btn-ghost">Cancel</a>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </form>

    @if ($editing)
        @if (($subscriptions ?? 0) > 0)
            <p class="admin-muted mt-3"><x-icon name="lock" class="icon-xs" /> This plan has subscriptions, so it cannot be deleted — switch "On sale" off instead.</p>
        @else
            <form method="POST" action="{{ route('admin.plans.destroy', $plan) }}" class="mt-3" data-confirm="The plan &quot;{{ $plan->name }}&quot; will be deleted." data-confirm-title="Delete this plan?" data-confirm-label="Delete" data-confirm-danger>
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-ghost is-danger"><x-icon name="trash-2" /> Delete plan</button>
            </form>
        @endif
    @endif
</x-layouts.admin>
