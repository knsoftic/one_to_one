<?php

namespace App\Http\Controllers;

use App\Services\AccountDeletionService;
use App\Services\AccountExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A3 — Delete my account, A5 — Download my account data.
 */
class AccountController extends Controller
{
    public function export(Request $request, AccountExportService $export): Response
    {
        $user = $request->user();
        $data = $export->build($user, $request);
        $name = 'account-'.$user->username.'-'.now()->format('Y-m-d');

        if ($request->query('format') === 'json') {
            return response()->json($data, 200, [
                'Content-Disposition' => "attachment; filename=\"{$name}.json\"",
                'Cache-Control' => 'no-store, private',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return response(view('account.report', ['data' => $data])->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}.html\"",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function destroy(Request $request, AccountDeletionService $deletion): RedirectResponse
    {
        $user = $request->user();

        $request->validateWithBag('deleteAccount', [
            'current_password' => ['required', 'current_password'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Tick the box to confirm that you want to delete your account.']);

        if ($user->isAdmin()) {
            throw ValidationException::withMessages(['current_password' => "Administrator accounts can't be deleted from settings."])->errorBag('deleteAccount');
        }

        Auth::guard('web')->logout();
        $deletion->delete($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Your account has been deleted.');
    }
}
