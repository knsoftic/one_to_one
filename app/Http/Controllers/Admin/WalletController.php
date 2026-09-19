<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientCoinsException;
use App\Exceptions\WalletFrozenException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\CoinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin → person → Money (Y2): add or remove coins, freeze the wallet.
 */
class WalletController extends Controller
{
    public function __construct(private readonly CoinService $coins) {}

    public function adjust(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['required', 'string', 'max:160'],
            'token' => ['required', 'string', 'uuid'],
        ]);

        try {
            $row = $this->coins->adjust($request->user(), $user, (int) $data['amount'], $data['note'], $data['token']);
        } catch (InsufficientCoinsException $e) {
            return back()->withErrors(['amount' => "They only have {$e->balance} coins."])->withInput();
        } catch (WalletFrozenException) {
            return back()->withErrors(['amount' => 'The wallet is frozen — unfreeze it first.'])->withInput();
        }

        $verb = $row->amount > 0 ? 'Added' : 'Removed';

        return redirect()->route('admin.users.show', ['user' => $user, 'tab' => 'money'])
            ->with('status', sprintf('%s %s coins. Balance is now %s.', $verb, number_format(abs($row->amount)), number_format($row->balance_after)));
    }

    public function freeze(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['frozen' => ['required', 'boolean']]);
        $wallet = $this->coins->freeze($request->user(), $user, (bool) $data['frozen']);

        return redirect()->route('admin.users.show', ['user' => $user, 'tab' => 'money'])
            ->with('status', $wallet->frozen ? "{$user->name}'s wallet is frozen." : "{$user->name}'s wallet is active again.");
    }
}
