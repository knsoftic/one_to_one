<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\StartConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    /**
     * Recent chats for the sidebar.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return ConversationResource::collection($this->conversations->recentFor($request->user()));
    }

    /**
     * Open (or create) the private conversation with another user.
     */
    public function store(StartConversationRequest $request): JsonResponse
    {
        $other = User::query()->findOrFail($request->integer('user_id'));

        $conversation = $this->conversations->findOrCreate($request->user(), $other);

        return (new ConversationResource($this->conversations->loadForUser($conversation, $request->user())))
            ->response()
            ->setStatusCode($conversation->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);

        return new ConversationResource($this->conversations->loadForUser($conversation, $request->user()));
    }
}
