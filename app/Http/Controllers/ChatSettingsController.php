<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Services\ChatLockService;
use App\Services\ChatSettingsService;
use App\Services\ConversationService;
use App\Support\ChatPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Phase 2 — pin, mute, archive, mark unread/read, favourite, clear and delete a chat (for me only).
 */
class ChatSettingsController extends Controller
{
    public function __construct(
        private readonly ChatSettingsService $settings,
        private readonly ConversationService $conversations,
    ) {}

    public function update(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);

        $changes = $request->validate([
            'pinned' => ['sometimes', 'boolean'],
            'archived' => ['sometimes', 'boolean'],
            'muted' => ['sometimes', 'nullable', Rule::in(ChatSetting::MUTE_DURATIONS)],
            'unread' => ['sometimes', 'boolean'],
            'favorite' => ['sometimes', 'boolean'],
            'locked' => ['sometimes', 'boolean'],
            // D4 — this chat's sound and vibration (null = the default from Settings).
            'notification_tone' => ['sometimes', 'nullable', Rule::in([...ChatPreferences::TONES, ChatPreferences::TONE_NONE])],
            'notification_vibrate' => ['sometimes', 'nullable', Rule::in(ChatPreferences::VIBRATIONS)],
        ]);

        abort_if($changes === [], 422, 'Nothing to change.');

        foreach (['pinned', 'archived', 'unread', 'favorite', 'locked'] as $key) {
            if (array_key_exists($key, $changes)) {
                $changes[$key] = filter_var($changes[$key], FILTER_VALIDATE_BOOL);
            }
        }

        abort_if(($changes['locked'] ?? false) && ! $request->user()->chat_lock_pin, 422, 'Create a secret code first.');
        // Taking a chat out of "Locked chats" needs the secret code first (C9).
        abort_if(array_key_exists('locked', $changes) && ! $changes['locked'] && ! app(ChatLockService::class)->isUnlocked()
            && ChatSetting::query()->where('user_id', $request->user()->getKey())->where('conversation_id', $conversation->getKey())->whereNotNull('locked_at')->exists(), 423, 'Enter your secret code to unlock chats first.');

        try {
            $this->settings->update($request->user(), $conversation, $changes);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return new ConversationResource($this->conversations->loadForUser($conversation, $request->user()));
    }

    /**
     * Clear chat: remove all messages so far for me.
     */
    public function clear(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);
        $validated = $request->validate(['keep_starred' => ['sometimes', 'boolean']]);

        $this->settings->clear($request->user(), $conversation, (bool) ($validated['keep_starred'] ?? false));

        return new ConversationResource($this->conversations->loadForUser($conversation, $request->user()));
    }

    /**
     * Delete chat: clear it and take it off my list until a new message arrives.
     */
    public function destroy(Request $request, Conversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);

        try {
            $this->settings->delete($request->user(), $conversation);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return response()->json(['id' => $conversation->id, 'deleted' => true]);
    }
}
