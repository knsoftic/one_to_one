<?php

namespace App\Http\Resources;

use App\Models\Conversation;
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
        $other = $this->resource->otherParticipant($viewer);
        $latest = $this->relationLoaded('latestMessage') ? $this->getRelation('latestMessage') : null;

        $participant = $other ? (new UserResource($other))->resolve($request) : null;

        // Someone who blocked you does not share their online status / last seen.
        if ($participant && ($this->blocked_me ?? false)) {
            $participant['is_online'] = false;
            $participant['last_seen'] = null;
        }

        return [
            'id' => $this->id,
            'participant' => $participant,
            'last_message' => $latest ? [
                'id' => $latest->id,
                'sender_id' => $latest->sender_id,
                'is_mine' => (int) $latest->sender_id === (int) $viewer->getKey(),
                'type' => $latest->message_type,
                'preview' => $latest->preview(80),
                'is_deleted' => (bool) $latest->deleted_for_everyone,
                'status' => $latest->status(),
                'created_at' => $latest->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => (int) ($this->unread_count ?? 0),
            'blocked_by_me' => $this->when(isset($this->blocked_by_me), fn () => (bool) $this->blocked_by_me),
            'blocked_me' => $this->when(isset($this->blocked_me), fn () => (bool) $this->blocked_me),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
