<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCoinsException;
use App\Exceptions\PaymentException;
use App\Exceptions\WalletFrozenException;
use App\Services\BadgeService;
use App\Services\CoinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Buy the verified badge with coins (Y2). The client token makes a double tap charge once.
 */
class BadgeController extends Controller
{
    public function __construct(private readonly BadgeService $badges, private readonly CoinService $coins) {}

    public function buy(Request $request): JsonResponse
    {
        $data = $request->validate(['client_token' => ['required', 'string', 'uuid']]);

        try {
            $user = $this->badges->buy($request->user(), $data['client_token']);
        } catch (InsufficientCoinsException|WalletFrozenException $e) {
            return response()->json($e->toArray(), 422);
        } catch (PaymentException $e) {
            return response()->json($e->toArray(), $e->status);
        }

        return response()->json([
            'badge' => $this->badges->state($user),
            'wallet' => $this->coins->summary($user),
        ]);
    }
}
