<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Renders the chat dashboard shell. Conversations and messages are then
 * loaded over AJAX by the frontend.
 */
class ChatController extends Controller
{
    public function index(Request $request): View
    {
        return view('chat.index', [
            'user' => $request->user(),
            'initialConversationId' => null,
        ]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        Gate::authorize('view', $conversation);

        return view('chat.index', [
            'user' => $request->user(),
            'initialConversationId' => $conversation->getKey(),
        ]);
    }
}
