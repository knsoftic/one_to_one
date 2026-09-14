<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\BroadcastService;
use App\Services\ContactService;
use App\Services\ConversationService;
use App\Services\GroupReceiptService;
use App\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Phase 4 — Group chats.
 */
class GroupController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
        private readonly ConversationService $conversations,
    ) {}

    /** G1: create a group with a name, people, and optionally a description and icon. */
    public function store(Request $request): JsonResponse
    {
        $max = $this->groups->maxMembers() - 1;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:'.GroupService::MAX_NAME],
            'description' => ['nullable', 'string', 'max:'.GroupService::MAX_DESCRIPTION],
            'member_ids' => ['required', 'array', 'min:1', "max:{$max}"],
            'member_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('status', User::STATUS_ACTIVE)],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('chat.uploads.avatar.max_kb', 5120)],
        ], [
            'member_ids.required' => 'Add at least one person to the group.',
            'member_ids.max' => "A group can have up to {$this->groups->maxMembers()} people.",
            'name.required' => 'Give the group a name.',
        ]);

        $group = $this->groups->create(
            $request->user(),
            $validated['name'],
            $validated['member_ids'],
            $validated['description'] ?? null,
            $request->file('avatar'),
        );

        return $this->respond($request, $group, 201);
    }

    /** G1: name, description, icon. */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $group = $this->group($conversation);
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:'.GroupService::MAX_NAME],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.GroupService::MAX_DESCRIPTION],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('chat.uploads.avatar.max_kb', 5120)],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $this->groups->updateInfo(
            $group,
            $request->user(),
            array_intersect_key($validated, array_flip(['name', 'description'])),
            $request->file('avatar'),
            $request->boolean('remove_avatar'),
        );

        return $this->respond($request, $group);
    }

    /** G1: add people. */
    public function addMembers(Request $request, Conversation $conversation): JsonResponse
    {
        $group = $this->group($conversation);
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:'.$this->groups->maxMembers()],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        $this->groups->addMembers($group, $request->user(), $validated['user_ids']);

        return $this->respond($request, $group);
    }

    /** G2: make or dismiss an admin. */
    public function updateMember(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $group = $this->group($conversation);
        $validated = $request->validate(['role' => ['required', Rule::in(['admin', 'member'])]]);

        $this->groups->setAdmin($group, $request->user(), $user, $validated['role'] === 'admin');

        return $this->respond($request, $group);
    }

    /** G2: remove someone. */
    public function removeMember(Request $request, Conversation $conversation, User $user): JsonResponse
    {
        $group = $this->group($conversation);
        $this->groups->removeMember($group, $request->user(), $user);

        return $this->respond($request, $group);
    }

    /** G5: who can send messages and edit the group's info. */
    public function settings(Request $request, Conversation $conversation): JsonResponse
    {
        $group = $this->group($conversation);
        $validated = $request->validate([
            'only_admins_send' => ['sometimes', 'boolean'],
            'only_admins_edit' => ['sometimes', 'boolean'],
        ]);
        abort_if($validated === [], 422, 'Nothing to change.');

        $this->groups->updateSettings($group, $request->user(), array_map('boolval', $validated));

        return $this->respond($request, $group);
    }

    /** G8: exit group. */
    public function leave(Request $request, Conversation $conversation): JsonResponse
    {
        $group = $this->group($conversation);
        $this->groups->leave($group, $request->user());

        return $this->respond($request, $group);
    }

    /** G8: delete the group for everyone (admins). */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        $group = $this->group($conversation);
        $this->groups->end($group, $request->user());

        return $this->respond($request, $group);
    }

    /** G3: the invite link (admins); `reset` makes a new one and the old one stops working. */
    public function invite(Request $request, Conversation $conversation): JsonResponse
    {
        $group = $this->group($conversation);
        $url = $this->groups->inviteLink($group, $request->user(), $request->isMethod('post'));

        return response()->json(['url' => $url, 'token' => $group->invite_token]);
    }

    /** G3: the page an invite link opens (the chat app asks to join). */
    public function joinPage(Request $request, string $token): View
    {
        $group = $this->groups->findByInvite($token);

        return view('chat.index', [
            'user' => $request->user(),
            'initialConversationId' => null,
            'groupInvite' => $group
                ? ['valid' => true] + $this->groups->invitePreview($group, $request->user())
                : ['valid' => false, 'token' => $token],
        ]);
    }

    public function join(Request $request, string $token): JsonResponse
    {
        $group = $this->groups->joinWithLink($token, $request->user());

        return $this->respond($request, $group);
    }

    /** G6: who read a group message you sent, and who it was delivered to. */
    public function receipts(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('view', $message);
        // Channels (G11) never show who read an update.
        abort_unless($message->isGroupMessage() && $message->isSentBy($request->user()) && ! $message->conversation?->isChannel(), 404);

        // Broadcast lists (G9) report on the copies in each recipient's chat.
        $receipts = $message->conversation?->isBroadcast()
            ? app(BroadcastService::class)->receiptsFor($message)
            : app(GroupReceiptService::class)->receiptsFor($message);
        $users = User::query()->whereKey($receipts->pluck('user_id'))->get()->keyBy('id');
        $saved = app(ContactService::class)->savedNames($request->user(), $receipts->pluck('user_id')->all());

        return response()->json([
            'data' => $receipts
                ->filter(fn (array $receipt) => $users->has($receipt['user_id']))
                ->map(fn (array $receipt) => [
                    'user' => (new UserResource($users[$receipt['user_id']]))->resolve($request) + ['saved_name' => $saved[$receipt['user_id']] ?? null],
                    'delivered_at' => $receipt['delivered_at'],
                    'seen_at' => $receipt['seen_at'],
                ])
                ->values(),
        ]);
    }

    private function group(Conversation $conversation): Conversation
    {
        abort_unless($conversation->isGroup(), 404);
        Gate::authorize('participate', $conversation);

        return $conversation;
    }

    private function respond(Request $request, Conversation $group, int $status = 200): JsonResponse
    {
        $group->unsetRelation('members');

        return (new ConversationResource($this->conversations->loadForUser($group->fresh(), $request->user())))
            ->response()
            ->setStatusCode($status);
    }
}
