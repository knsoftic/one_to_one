<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TurnServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin → App settings → Call server (TURN): check the app's own TURN server.
 */
class TurnController extends Controller
{
    public function __construct(private readonly TurnServerService $turn) {}

    /** From the server: ask each TURN address for a relay with the app's credentials. */
    public function check(): RedirectResponse
    {
        if (! $this->turn->status()['configured']) {
            return redirect()->to(route('admin.settings').'#turn')->with('error', 'Set up the TURN server first.');
        }

        $check = $this->turn->check();

        return redirect()->to(route('admin.settings').'#turn')->with($check['ok'] ? 'status' : 'error', $check['ok']
            ? 'The TURN server answers and the secret matches. This check runs on the server itself, so it can\'t see your hosting provider\'s firewall: also press Test from this browser on a phone with Wi-Fi off.'
            : 'The TURN server check found a problem — see Call server (TURN).');
    }

    /** For the check in the admin's own browser: short-lived TURN credentials only. */
    public function servers(Request $request): JsonResponse
    {
        return response()->json(['ice_servers' => $this->turn->browserTestServers('admin-'.$request->user()->getKey())], 200, ['Cache-Control' => 'no-store']);
    }
}
