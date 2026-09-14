<?php

namespace App\Http\Resources;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\BroadcastService;
use App\Services\ChannelService;
use App\Services\ChatLockService;
use App\Services\GroupService;
use App\Services\ReadReceiptService;
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
                'type' => $this->type ?? Conversation::TYPE_DIRECT,
                'is_self' => false,
                'participant' => null,
                'last_message' => null,
                'unread_count' => (int) ($this->unread_count ?? 0),
                'settings' => $this->my_settings,
                'is_locked_out' => true,
            ];
        }

        $isGroup = $this->resource->isGroup();
        $other = $this->resource->hasMembers() ? null : $this->resource->otherParticipant($viewer);
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
            'type' => $this->resource->type ?: Conversation::TYPE_DIRECT,
            // Channels (G11): name, icon, followers, my role.
            'channel' => $this->when($this->resource->isChannel(), fn () => app(ChannelService::class)->payload($this->resource, $viewer)),
            // Broadcast lists (G9): name and recipients.
            'broadcast' => $this->when($this->resource->isBroadcast(), fn () => app(BroadcastService::class)->payload(
                $this->resource, $viewer, (bool) ($this->with_group_members ?? false), $request,
            )),
            'participant' => $participant,
            'is_self' => $this->resource->isSelf(),
            // Group chats (Phase 4): name, icon, my role, settings; members when a single chat is loaded.
            'group' => $this->when($isGroup, fn () => app(GroupService::class)->payload(
                $this->resource, $viewer, (bool) ($this->with_group_members ?? false), $request,
            )),
            'last_message' => $latest ? [
                'id' => $latest->id,
                'sender_id' => $latest->sender_id,
                'sender_name' => $isGroup && $latest->relationLoaded('sender') ? $latest->sender?->name : null,
                'is_mine' => (int) $latest->sender_id === (int) $viewer->getKey(),
                'type' => $latest->message_type,
                'preview' => $latest->message_type === Message::TYPE_CALL
                    ? $latest->callPreview(outgoing: (int) $latest->sender_id === (int) $viewer->getKey())
                    : $latest->preview(80),
                'is_deleted' => (bool) $latest->deleted_for_everyone,
                // App notices are worded for the reader ("You added Sara").
                'system' => $latest->message_type === Message::TYPE_SYSTEM ? array_filter([
                    'event' => $latest->attachment_meta['event'] ?? null,
                    'actor' => $latest->attachment_meta['actor'] ?? null,
                    'users' => $latest->attachment_meta['users'] ?? null,
                    'name' => $latest->attachment_meta['name'] ?? null,
                    'seconds' => $latest->attachment_meta['seconds'] ?? null,
                    'only_admins_send' => $latest->attachment_meta['only_admins_send'] ?? null,
                    'only_admins_edit' => $latest->attachment_meta['only_admins_edit'] ?? null,
                    'text' => $latest->systemText(),
                ], fn ($value) => $value !== null) : null,
                'status' => app(ReadReceiptService::class)->statusOf($latest),
                'created_at' => $latest->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => (int) ($this->unread_count ?? 0),
            'unread_mentions' => (int) ($this->unread_mentions ?? 0),
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
