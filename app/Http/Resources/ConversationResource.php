<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatLockService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation as seen by one participant.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        // A locked chat (C9) shows nothing but its place in "Locked chats" until the code is entered.
        if (($this->my_settings['locked'] ?? false) && ! app(ChatLockService::class)->isUnlocked()) {
            return [
                'id' => $this->id,
                'is_self' => false,
                'participant' => null,
                'last_message' => null,
                'unread_count' => (int) ($this->unread_count ?? 0),
                'settings' => $this->my_settings,
                'is_locked_out' => true,
            ];
        }

        $other = $this->resource->otherParticipant($viewer);
        $latest = $this->relationLoaded('latestMessage') ? $this->getRelation('latestMessage') : null;

        $participant = $other ? (new UserResource($other))->resolve($request) : null;

        // Someone who blocked you does not share their online status / last seen.
        if ($participant && ($this->blocked_me ?? false)) {
            $participant['is_online'] = false;
            $participant['last_seen'] = null;
        }

        // Name as saved in the viewer's phone book (like WhatsApp), if any.
        if ($participant) {
            $participant['saved_name'] = $this->saved_name ?? null;
        }

        return [
            'id' => $this->id,
            'participant' => $participant,
            'is_self' => $this->resource->isSelf(),
            'last_message' => $latest ? [
                'id' => $latest->id,
                'sender_id' => $latest->sender_id,
                'is_mine' => (int) $latest->sender_id === (int) $viewer->getKey(),
                'type' => $latest->message_type,
                'preview' => $latest->message_type === Message::TYPE_CALL
                    ? $latest->callPreview(outgoing: (int) $latest->sender_id === (int) $viewer->getKey())
                    : $latest->preview(80),
                'is_deleted' => (bool) $latest->deleted_for_everyone,
                'status' => $latest->status(),
                'created_at' => $latest->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => (int) ($this->unread_count ?? 0),
            'disappearing_seconds' => $this->disappearing_seconds,
            // The viewer's own settings for this chat (Phase 2).
            'settings' => $this->when(isset($this->my_settings), fn () => $this->my_settings),
            'pinned_messages' => $this->when(isset($this->pinned_messages), fn () => $this->pinned_messages),
            'blocked_by_me' => $this->when(isset($this->blocked_by_me), fn () => (bool) $this->blocked_by_me),
            'blocked_me' => $this->when(isset($this->blocked_me), fn () => (bool) $this->blocked_me),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
