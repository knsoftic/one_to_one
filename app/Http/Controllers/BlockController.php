<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\BlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    public function __construct(private readonly BlockService $blocks) {}

    public function store(Request $request, User $user): JsonResponse|RedirectResponse
    {
        abort_if($request->user()->is($user), 422, 'You cannot block yourself.');

        $this->blocks->block($request->user(), $user);

        return $request->expectsJson()
            ? response()->json(['blocked' => true, 'user_id' => $user->id], 201)
            : back()->with('status', "{$user->name} has been blocked.");
    }

    public function destroy(Request $request, User $user): JsonResponse|RedirectResponse
    {
        $this->blocks->unblock($request->user(), $user);

        return $request->expectsJson()
            ? response()->json(['blocked' => false, 'user_id' => $user->id])
            : back()->with('status', "{$user->name} has been unblocked.");
    }
}
