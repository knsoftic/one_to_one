@php
    $money = app(\App\Services\MonetisationService::class);
    $currencies = \App\Services\MonetisationService::CURRENCIES;
    $decimal = fn (?int $minor) => $minor === null ? '' : number_format($minor / 100, 2, '.', '');
@endphp
<x-layouts.admin title="Coin packs" heading="Coin packs" subheading="The bundles people can buy. Prices are what you charge; coins are what they get.">
    <div class="admin-dash-toolbar">
        <span class="admin-dash-updated">{{ number_format($packs->where('is_active', true)->count()) }} on sale · {{ number_format($packs->count()) }} total</span>
        <a href="{{ route('admin.settings') }}#paid" class="btn btn-secondary btn-sm"><x-icon name="settings" /> Payment settings</a>
    </div>

    @unless ((bool) \App\Models\AppSetting::get('paid_enabled'))
        <p class="admin-backup-note is-warning"><x-icon name="triangle-alert" /> Paid features are switched off. Turn on <a class="admin-link" href="{{ route('admin.settings') }}#paid">Paid features</a> for these to be for sale.</p>
    @endunless

    <section class="card">
        <div class="admin-table-wrap">
            <table class="admin-table money-packs-table">
                <thead>
                    <tr><th>Name</th><th class="admin-num-cell">Coins</th><th class="admin-num-cell">Bonus</th><th class="admin-num-cell">Price</th><th>Currency</th><th class="admin-num-cell">USD (PayPal)</th><th>Play product id</th><th class="admin-num-cell">Sort</th><th>Active</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($packs as $pack)
                        @php $f = 'pack-'.$pack->id; @endphp
                        <tr class="money-pack-row">
                            <td><input form="{{ $f }}" name="name" value="{{ $pack->name }}" maxlength="60" required class="form-control" aria-label="Name"></td>
                            <td><input form="{{ $f }}" name="coins" type="number" min="1" value="{{ $pack->coins }}" required class="form-control money-num" aria-label="Coins"></td>
                            <td><input form="{{ $f }}" name="bonus_coins" type="number" min="0" value="{{ $pack->bonus_coins }}" class="form-control money-num" aria-label="Bonus coins"></td>
                            <td><input form="{{ $f }}" name="price" type="number" min="0.01" step="0.01" value="{{ $decimal($pack->price_minor) }}" required class="form-control money-num" aria-label="Price"></td>
                            <td>
                                <select form="{{ $f }}" name="currency" class="form-control money-select" aria-label="Currency">
                                    @foreach ($currencies as $code => $symbol)<option value="{{ $code }}" @selected($pack->currency === $code)>{{ $code }}</option>@endforeach
                                </select>
                            </td>
                            <td><input form="{{ $f }}" name="price_usd" type="number" min="0.01" step="0.01" value="{{ $decimal($pack->price_usd_minor) }}" class="form-control money-num" placeholder="—" aria-label="USD price"></td>
                            <td><input form="{{ $f }}" name="play_product_id" value="{{ $pack->play_product_id }}" maxlength="80" class="form-control money-play" placeholder="coins_500" aria-label="Play product id"></td>
                            <td><input form="{{ $f }}" name="sort" type="number" min="0" value="{{ $pack->sort }}" class="form-control money-num is-short" aria-label="Sort"></td>
                            <td>
                                <label class="switch" title="{{ $pack->is_active ? 'On sale' : 'Hidden' }}">
                                    <input form="{{ $f }}" type="hidden" name="is_active" value="0">
                                    <input form="{{ $f }}" type="checkbox" name="is_active" value="1" @checked($pack->is_active) aria-label="Active">
                                    <span class="switch-track"></span>
                                </label>
                            </td>
                            <td class="money-pack-actions">
                                <form id="{{ $f }}" method="POST" action="{{ route('admin.coin-packs.update', $pack) }}">@csrf @method('PUT')<button type="submit" class="btn btn-primary btn-sm"><x-icon name="check" /> Save</button></form>
                                @if ($pack->payments_count > 0)
                                    <span class="admin-muted" title="{{ number_format($pack->payments_count) }} payment(s) reference this pack; untick Active to hide it">{{ number_format($pack->payments_count) }} sold</span>
                                @else
                                    <form method="POST" action="{{ route('admin.coin-packs.destroy', $pack) }}" data-confirm="The pack &quot;{{ $pack->name }}&quot; will be deleted." data-confirm-title="Delete this pack?" data-confirm-label="Delete" data-confirm-danger>@csrf @method('DELETE')<button type="submit" class="btn btn-ghost btn-sm" aria-label="Delete {{ $pack->name }}"><x-icon name="trash-2" /></button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    {{-- Add row --}}
                    <tr class="money-pack-row is-new">
                        <td><input form="pack-new" name="name" value="{{ old('name') }}" maxlength="60" required class="form-control @error('name') is-invalid @enderror" placeholder="Starter" aria-label="New pack name"></td>
                        <td><input form="pack-new" name="coins" type="number" min="1" value="{{ old('coins') }}" required class="form-control money-num @error('coins') is-invalid @enderror" placeholder="500" aria-label="New pack coins"></td>
                        <td><input form="pack-new" name="bonus_coins" type="number" min="0" value="{{ old('bonus_coins', 0) }}" class="form-control money-num" aria-label="New pack bonus"></td>
                        <td><input form="pack-new" name="price" type="number" min="0.01" step="0.01" value="{{ old('price') }}" required class="form-control money-num @error('price') is-invalid @enderror" placeholder="499.00" aria-label="New pack price"></td>
                        <td>
                            <select form="pack-new" name="currency" class="form-control money-select" aria-label="New pack currency">
                                @foreach ($currencies as $code => $symbol)<option value="{{ $code }}" @selected(old('currency', $currency) === $code)>{{ $code }}</option>@endforeach
                            </select>
                        </td>
                        <td><input form="pack-new" name="price_usd" type="number" min="0.01" step="0.01" value="{{ old('price_usd') }}" class="form-control money-num" placeholder="—" aria-label="New pack USD price"></td>
                        <td><input form="pack-new" name="play_product_id" value="{{ old('play_product_id') }}" maxlength="80" class="form-control money-play @error('play_product_id') is-invalid @enderror" placeholder="coins_500" aria-label="New pack Play product id"></td>
                        <td><input form="pack-new" name="sort" type="number" min="0" value="{{ old('sort', ($packs->max('sort') ?? 0) + 10) }}" class="form-control money-num is-short" aria-label="New pack sort"></td>
                        <td>
                            <label class="switch" title="On sale">
                                <input form="pack-new" type="hidden" name="is_active" value="0">
                                <input form="pack-new" type="checkbox" name="is_active" value="1" checked aria-label="New pack active">
                                <span class="switch-track"></span>
                            </label>
                        </td>
                        <td class="money-pack-actions">
                            <form id="pack-new" method="POST" action="{{ route('admin.coin-packs.store') }}">@csrf<button type="submit" class="btn btn-secondary btn-sm"><x-icon name="plus" /> Add</button></form>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        @if ($errors->any())
            <div class="card-footer"><p class="form-error"><x-icon name="circle-alert" /> {{ $errors->first() }}</p></div>
        @endif
    </section>

    <p class="admin-backup-note"><x-icon name="coins" /> <span>People receive <strong>coins + bonus</strong>. The USD price is only needed for PayPal (it cannot charge rupees). The Play product id must match a consumable in-app product in the Play Console; the app shows Play's own price there. Packs that were ever bought cannot be deleted — untick <strong>Active</strong> to take them off sale.</span></p>
</x-layouts.admin>
