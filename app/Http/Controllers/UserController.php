<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\PrivacyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    /**
     * Search active users by name, username, email or mobile number.
     */
    public function search(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        $term = trim($validated['q']);
        $viewer = $request->user();

        $users = User::query()
            ->active()
            ->whereKeyNot($viewer->getKey())
            ->search(ltrim($term, '@'))
            // Exact username / name-prefix matches first, then alphabetical.
            ->orderByRaw('CASE WHEN username = ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [
                mb_strtolower(ltrim($term, '@')),
                addcslashes($term, '%_\\').'%',
            ])
            ->orderBy('name')
            ->limit(20)
            ->get();

        return UserResource::collection($users);
    }

    /**
     * Users currently online — contacts first.
     */
    public function online(Request $request): AnonymousResourceCollection
    {
        $viewer = $request->user();

        $contactIds = Conversation::query()
            ->forUser($viewer)
            ->where('type', Conversation::TYPE_DIRECT)
            ->get(['type', 'user_one_id', 'user_two_id'])
            ->map(fn (Conversation $c) => $c->otherParticipantId($viewer))
            ->unique()
            ->values();

        $users = User::query()
            ->active()
            ->online()
            ->whereKeyNot($viewer->getKey())
            ->when($contactIds->isNotEmpty(), fn ($q) => $q->orderByRaw(
                'CASE WHEN id IN ('.$contactIds->map(fn () => '?')->implode(',').') THEN 0 ELSE 1 END',
                $contactIds->all()
            ))
            ->orderByDesc('last_seen')
            ->limit(60)
            ->get()
            // Only people who share their online status with the viewer (P1).
            ->filter(fn (User $user) => app(PrivacyService::class)->canSeeOnline($user, $viewer))
            ->take(30)
            ->values();

        return UserResource::collection($users);
    }
}
