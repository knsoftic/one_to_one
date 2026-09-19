<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoinPack;
use App\Services\AdminAuditService;
use App\Services\MonetisationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Money → Coin packs (Y2): the bundles people can buy, edited inline in one table.
 * Prices are typed as money (499.00) and stored in minor units.
 */
class CoinPackController extends Controller
{
    public function __construct(private readonly AdminAuditService $audit, private readonly MonetisationService $money) {}

    public function index(): View
    {
        return view('admin.coin-packs.index', [
            'packs' => CoinPack::query()->withCount('payments')->orderBy('sort')->orderBy('id')->get(),
            'currency' => $this->money->currency(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $pack = CoinPack::query()->create($this->validated($request));
        $this->audit->record($request->user(), 'coin_pack.created', $pack, "Created the coin pack \"{$pack->name}\" ({$pack->coins} coins, ".$this->money->formatMoney($pack->price_minor, $pack->currency).')', $this->meta($pack));

        return redirect()->route('admin.coin-packs')->with('status', 'Coin pack added.');
    }

    public function update(Request $request, CoinPack $pack): RedirectResponse
    {
        $pack->update($this->validated($request, $pack));
        $this->audit->record($request->user(), 'coin_pack.updated', $pack, "Edited the coin pack \"{$pack->name}\"", $this->meta($pack));

        return redirect()->route('admin.coin-packs')->with('status', 'Coin pack saved.');
    }

    /** Refused while payments reference the pack: deactivate it instead, the history must stay readable. */
    public function destroy(Request $request, CoinPack $pack): RedirectResponse
    {
        if ($pack->payments()->exists()) {
            return redirect()->route('admin.coin-packs')->with('error', "\"{$pack->name}\" has payments and cannot be deleted — untick Active instead.");
        }

        $name = $pack->name;
        $meta = $this->meta($pack);
        $pack->delete();
        $this->audit->record($request->user(), 'coin_pack.deleted', null, "Deleted the coin pack \"{$name}\"", $meta);

        return redirect()->route('admin.coin-packs')->with('status', 'Coin pack deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CoinPack $pack = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'coins' => ['required', 'integer', 'between:1,10000000'],
            'bonus_coins' => ['nullable', 'integer', 'between:0,10000000'],
            'price' => ['required', 'numeric', 'between:0.01,10000000'],
            'currency' => ['required', Rule::in(array_keys(MonetisationService::CURRENCIES))],
            'price_usd' => ['nullable', 'numeric', 'between:0.01,1000000'],
            'play_product_id' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.]+$/', Rule::unique('coin_packs', 'play_product_id')->ignore($pack)],
            'is_active' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'integer', 'between:0,65535'],
        ]);

        return [
            'name' => $data['name'],
            'coins' => (int) $data['coins'],
            'bonus_coins' => (int) ($data['bonus_coins'] ?? 0),
            'price_minor' => (int) round((float) $data['price'] * 100),
            'currency' => $data['currency'],
            'price_usd_minor' => isset($data['price_usd']) && $data['price_usd'] !== '' ? (int) round((float) $data['price_usd'] * 100) : null,
            'play_product_id' => filled($data['play_product_id'] ?? null) ? $data['play_product_id'] : null,
            'is_active' => $request->boolean('is_active'),
            'sort' => (int) ($data['sort'] ?? 0),
        ];
    }

    private function meta(CoinPack $pack): array
    {
        return ['pack' => $pack->getKey(), 'coins' => $pack->coins, 'bonus' => $pack->bonus_coins, 'price_minor' => $pack->price_minor, 'currency' => $pack->currency, 'active' => (bool) $pack->is_active];
    }
}
