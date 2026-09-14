<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\User;
use App\Services\CommunityService;
use App\Services\ConversationService;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * G10 — Communities.
 */
class CommunityController extends Controller
{
    public function __construct(
        private readonly CommunityService $communities,
        private readonly ConversationService $conversations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => $this->communities->forUser($user)->map(fn (Community $community) => $this->communities->payload($community, $user))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.GroupService::MAX_NAME],
            'description' => ['nullable', 'string', 'max:'.GroupService::MAX_DESCRIPTION],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('chat.uploads.avatar.max_kb', 5120)],
            'group_ids' => ['nullable', 'array', 'max:'.CommunityService::MAX_GROUPS],
            'group_ids.*' => ['integer', 'distinct'],
        ], ['name.required' => 'Give the community a name.']);

        $community = $this->communities->create(
            $request->user(),
            $validated['name'],
            $validated['description'] ?? null,
            $request->file('avatar'),
            $validated['group_ids'] ?? [],
        );

        return response()->json($this->communities->payload($community, $request->user()), 201);
    }

    public function show(Request $request, Community $community): JsonResponse
    {
        abort_unless($this->communities->isMember($community, $request->user()), 404);

        return response()->json($this->communities->payload($community, $request->user()));
    }

    public function update(Request $request, Community $community): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:'.GroupService::MAX_NAME],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.GroupService::MAX_DESCRIPTION],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('chat.uploads.avatar.max_kb', 5120)],
        ]);

        $this->communities->update($community, $request->user(), array_intersect_key($validated, array_flip(['name', 'description'])), $request->file('avatar'));

        return response()->json($this->communities->payload($community->fresh(), $request->user()));
    }

    public function destroy(Request $request, Community $community): JsonResponse
    {
        $this->communities->delete($community, $request->user());

        return response()->json(['id' => $community->id, 'deleted' => true]);
    }

    public function leave(Request $request, Community $community): JsonResponse
    {
        $this->communities->leave($community, $request->user());

        return response()->json(['id' => $community->id, 'left' => true]);
    }

    public function removeMember(Request $request, Community $community, User $user): JsonResponse
    {
        $this->communities->removeMember($community, $request->user(), $user);

        return response()->json($this->communities->payload($community, $request->user()));
    }

    /** A new group inside the community. */
    public function storeGroup(Request $request, Community $community): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.GroupService::MAX_NAME],
            'member_ids' => ['nullable', 'array', 'max:255'],
            'member_ids.*' => ['integer', 'distinct'],
        ], ['name.required' => 'Give the group a name.']);

        $group = $this->communities->createGroup($community, $request->user(), $validated['name'], $validated['member_ids'] ?? []);

        return $this->groupResponse($request, $group, 201);
    }

    /** Add an existing group you run to the community. */
    public function linkGroup(Request $request, Community $community, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->isGroup() && $conversation->hasParticipant($request->user()), 404);
        $group = $this->communities->linkGroup($community, $request->user(), $conversation);

        return $this->groupResponse($request, $group);
    }

    public function unlinkGroup(Request $request, Community $community, Conversation $conversation): JsonResponse
    {
        $this->communities->unlinkGroup($community, $request->user(), $conversation);

        return response()->json($this->communities->payload($community, $request->user()));
    }

    public function joinGroup(Request $request, Community $community, Conversation $conversation): JsonResponse
    {
        $group = $this->communities->joinGroup($community, $request->user(), $conversation);

        return $this->groupResponse($request, $group);
    }

    public function invite(Request $request, Community $community): JsonResponse
    {
        $url = $this->communities->inviteLink($community, $request->user(), $request->isMethod('post'));

        return response()->json(['url' => $url, 'token' => $community->fresh()->invite_token]);
    }

    public function joinPage(Request $request, string $token): View
    {
        $community = $this->communities->findByInvite($token);

        return view('chat.index', [
            'user' => $request->user(),
            'initialConversationId' => null,
            'communityInvite' => $community
                ? ['valid' => true, 'token' => $token] + $this->communities->payload($community, $request->user(), withGroups: false)
                : ['valid' => false, 'token' => $token],
        ]);
    }

    public function join(Request $request, string $token): JsonResponse
    {
        $community = $this->communities->joinWithLink($token, $request->user());

        return response()->json($this->communities->payload($community, $request->user()));
    }

    private function groupResponse(Request $request, Conversation $group, int $status = 200): JsonResponse
    {
        return (new ConversationResource($this->conversations->loadForUser($group->fresh(), $request->user())))
            ->response()
            ->setStatusCode($status);
    }
}
