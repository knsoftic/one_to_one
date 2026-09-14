<?php

namespace App\View\Composers;

use App\Http\Resources\UserResource;
use App\Services\CallLogService;
use App\Services\ChatLockService;
use App\Services\GifService;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Configuration for the chat frontend: endpoint templates, limits and
 * realtime connection details. `__ID__` placeholders are replaced client-side.
 */
class ChatConfigComposer
{
    public function compose(View $view): void
    {
        $request = request();
        $user = $request->user();
        $id = '__ID__';

        $routes = [
            'chat' => route('chat.index'),
            'chatShow' => $this->template('chat.show', ['conversation' => $id]),
            'conversations' => route('conversations.index'),
            'conversationsStore' => route('conversations.store'),
            'conversationShow' => $this->template('conversations.show', ['conversation' => $id]),
            'messages' => $this->template('messages.index', ['conversation' => $id]),
            'messagesStore' => $this->template('messages.store', ['conversation' => $id]),
            'search' => route('users.search'),
            'online' => route('users.online'),
            'settings' => route('profile.edit'),
            'preferences' => route('profile.preferences'),
        ];

        // Endpoints added by later phases are included when registered.
        $optional = [
            'seen' => ['conversations.seen', ['conversation' => $id]],
            'typing' => ['conversations.typing', ['conversation' => $id]],
            'delivered' => ['messages.delivered', []],
            'messageUpdate' => ['messages.update', ['message' => $id]],
            'messageDestroy' => ['messages.destroy', ['message' => $id]],
            'messageForward' => ['messages.forward', ['message' => $id]],
            'messagesSearch' => ['messages.search', ['conversation' => $id]],
            'messageStar' => ['messages.star', ['message' => $id]],
            'messagePin' => ['messages.pin', ['message' => $id]],
            'starred' => ['starred.index', []],
            'linkPreview' => ['link-previews.show', []],
            'messageLocation' => ['messages.location.update', ['message' => $id]],
            'messageVote' => ['messages.vote', ['message' => $id]],
            'disappearing' => ['conversations.disappearing', ['conversation' => $id]],
            'conversationSettings' => ['conversations.settings', ['conversation' => $id]],
            'chatLockPin' => ['chat-lock.pin.store', []],
            'chatLockPinDestroy' => ['chat-lock.pin.destroy', []],
            'chatLockUnlock' => ['chat-lock.unlock', []],
            'chatLockLock' => ['chat-lock.lock', []],
            'chatLists' => ['chat-lists.index', []],
            'chatListsStore' => ['chat-lists.store', []],
            'chatListUpdate' => ['chat-lists.update', ['chatList' => $id]],
            'chatListDestroy' => ['chat-lists.destroy', ['chatList' => $id]],
            'conversationClear' => ['conversations.clear', ['conversation' => $id]],
            'conversationDestroy' => ['conversations.destroy', ['conversation' => $id]],
            'viewOnce' => ['messages.view-once', ['message' => $id]],
            'stickers' => ['stickers.index', []],
            'stickersStore' => ['stickers.store', []],
            'stickerDestroy' => ['stickers.destroy', ['sticker' => $id]],
            'messageSaveSticker' => ['messages.sticker.save', ['message' => $id]],
            'gifs' => ['gifs.index', []],
            'messageReaction' => ['messages.reaction.update', ['message' => $id]],
            'heartbeat' => ['presence.heartbeat', []],
            'offline' => ['presence.offline', []],
            'sync' => ['chat.sync', []],
            'block' => ['blocks.store', ['user' => $id]],
            'unblock' => ['blocks.destroy', ['user' => $id]],
            'notifications' => ['notifications.index', []],
            'contacts' => ['contacts.index', []],
            'contactsSync' => ['contacts.sync', []],
            'contactDestroy' => ['contacts.destroy', ['contact' => $id]],
            'notificationsRead' => ['notifications.read', []],
            'groupsStore' => ['groups.store', []],
            'broadcastsStore' => ['broadcasts.store', []],
            'communities' => ['communities.index', []],
            'communitiesStore' => ['communities.store', []],
            'communityShow' => ['communities.show', ['community' => $id]],
            'communityUpdate' => ['communities.update', ['community' => $id]],
            'communityDestroy' => ['communities.destroy', ['community' => $id]],
            'communityLeave' => ['communities.leave', ['community' => $id]],
            'communityGroupsStore' => ['communities.groups.store', ['community' => $id]],
            'communityGroupLink' => ['communities.groups.link', ['community' => $id, 'conversation' => '__GROUP__']],
            'communityGroupUnlink' => ['communities.groups.unlink', ['community' => $id, 'conversation' => '__GROUP__']],
            'communityGroupJoin' => ['communities.groups.join', ['community' => $id, 'conversation' => '__GROUP__']],
            'communityInvite' => ['communities.invite', ['community' => $id]],
            'communityInviteReset' => ['communities.invite.reset', ['community' => $id]],
            'communityJoin' => ['communities.join', ['token' => $id]],
            'reportUser' => ['users.report', ['user' => $id]],
            'sessions' => ['sessions.index', []],
            'sessionDestroy' => ['sessions.destroy', ['key' => $id]],
            'sessionsOthers' => ['sessions.others', []],
            'linkedLookup' => ['linked-devices.lookup', []],
            'linkedApprove' => ['linked-devices.approve', []],
            'statuses' => ['statuses.index', []],
            'statusesStore' => ['statuses.store', []],
            'statusDestroy' => ['statuses.destroy', ['status' => $id]],
            'statusView' => ['statuses.view', ['status' => $id]],
            'statusViewers' => ['statuses.viewers', ['status' => $id]],
            'statusReply' => ['statuses.reply', ['status' => $id]],
            'statusReact' => ['statuses.react', ['status' => $id]],
            'statusPrivacy' => ['statuses.privacy', []],
            'statusPrivacyUpdate' => ['statuses.privacy.update', []],
            'statusMute' => ['statuses.mute', ['user' => $id]],
            'statusUnmute' => ['statuses.unmute', ['user' => $id]],
            'channels' => ['channels.index', []],
            'channelsStore' => ['channels.store', []],
            'channelShow' => ['channels.show', ['conversation' => $id]],
            'channelUpdate' => ['channels.update', ['conversation' => $id]],
            'channelDestroy' => ['channels.destroy', ['conversation' => $id]],
            'channelFollow' => ['channels.follow', ['conversation' => $id]],
            'channelUnfollow' => ['channels.unfollow', ['conversation' => $id]],
            'broadcastUpdate' => ['broadcasts.update', ['conversation' => $id]],
            'broadcastDestroy' => ['broadcasts.destroy', ['conversation' => $id]],
            'messageReceipts' => ['messages.receipts', ['message' => $id]],
            'groupUpdate' => ['groups.update', ['conversation' => $id]],
            'groupDestroy' => ['groups.destroy', ['conversation' => $id]],
            'groupSettings' => ['groups.settings', ['conversation' => $id]],
            'groupMembersStore' => ['groups.members.store', ['conversation' => $id]],
            'groupMemberUpdate' => ['groups.members.update', ['conversation' => $id, 'user' => '__USER__']],
            'groupMemberDestroy' => ['groups.members.destroy', ['conversation' => $id, 'user' => '__USER__']],
            'groupLeave' => ['groups.leave', ['conversation' => $id]],
            'groupInvite' => ['groups.invite', ['conversation' => $id]],
            'groupInviteReset' => ['groups.invite.reset', ['conversation' => $id]],
            'groupJoin' => ['groups.join', ['token' => $id]],
            'callsStore' => ['calls.store', ['conversation' => $id]],
            'callsActive' => ['calls.active', []],
            'callLog' => ['calls.log', []],
            'callLogSeen' => ['calls.log.seen', []],
            'callLogClear' => ['calls.log.clear', []],
            'callLogDestroy' => ['calls.log.destroy', ['call' => $id]],
            'callShow' => ['calls.show', ['call' => $id]],
            'callRinging' => ['calls.ringing', ['call' => $id]],
            'callAccept' => ['calls.accept', ['call' => $id]],
            'callDecline' => ['calls.decline', ['call' => $id]],
            'callEnd' => ['calls.end', ['call' => $id]],
            'callHeartbeat' => ['calls.heartbeat', ['call' => $id]],
            'callVideo' => ['calls.video', ['call' => $id]],
            'callParticipantsStore' => ['calls.participants.store', ['call' => $id]],
            'callRoomsStore' => ['call-rooms.store', []],
            'callLinks' => ['call-links.index', []],
            'callLinksStore' => ['call-links.store', []],
            'callLinkDestroy' => ['call-links.destroy', ['callLink' => $id]],
            'callLinkJoin' => ['call-links.join', ['token' => $id]],
            'callRoomShow' => ['call-rooms.show', ['room' => $id]],
            'callRoomInvite' => ['call-rooms.invite', ['room' => $id]],
            'callRoomLeave' => ['call-rooms.leave', ['room' => $id]],
            'callRoomHeartbeat' => ['call-rooms.heartbeat', ['room' => $id]],
            'callRoomSignalsStore' => ['call-rooms.signals.store', ['room' => $id]],
            'callRoomSignals' => ['call-rooms.signals', ['room' => $id]],
            'callSignalsStore' => ['calls.signals.store', ['call' => $id]],
            'callSignals' => ['calls.signals', ['call' => $id]],
        ];

        foreach ($optional as $key => [$name, $params]) {
            if (Route::has($name)) {
                $routes[$key] = $this->template($name, $params);
            }
        }

        // GIF search is offered only when a Tenor key is configured.
        if (! app(GifService::class)->enabled()) {
            unset($routes['gifs']);
        }

        $reverb = config('broadcasting.connections.reverb');

        $view->with('chatConfig', [
            'appName' => config('app.name'),
            'invite' => ['url' => config('chat.invite.url') ?: route('register')],
            'chatLock' => [
                'enabled' => $user->chat_lock_pin !== null,
                'unlockedUntil' => app(ChatLockService::class)->unlockedUntil()?->toIso8601String(),
            ],
            'user' => (new UserResource($user))->resolve($request),
            'initialConversationId' => $view->getData()['initialConversationId'] ?? null,
            // Opened from a call link (K7).
            'callLink' => $view->getData()['callLink'] ?? null,
            // Opened from a group invite link (G3) or a community invite link (G10).
            'groupInvite' => $view->getData()['groupInvite'] ?? null,
            'communityInvite' => $view->getData()['communityInvite'] ?? null,
            // Opened from a channel link (G11).
            'channelInvite' => $view->getData()['channelInvite'] ?? null,
            // Opened from a linked-device QR code (P10).
            'linkDevice' => $view->getData()['linkDevice'] ?? null,
            // Status (Phase 5).
            'statuses' => [
                'maxVideoSeconds' => (int) config('chat.statuses.max_video_seconds', 60),
                'lifetimeHours' => (int) config('chat.statuses.lifetime_hours', 24),
            ],
            'groups' => [
                'maxMembers' => (int) config('chat.groups.max_members', 256),
                'maxBroadcastRecipients' => (int) config('chat.groups.max_broadcast_recipients', 256),
            ],
            'routes' => $routes,
            'limits' => [
                'messageLength' => config('chat.max_message_length'),
                'perPage' => config('chat.messages_per_page'),
                'image' => config('chat.uploads.image'),
                'document' => config('chat.uploads.document'),
                'video' => [
                    'extensions' => config('chat.uploads.video.extensions'),
                    'max_kb' => config('chat.uploads.video.max_kb'),
                ],
                'voice' => [
                    'max_kb' => config('chat.uploads.voice.max_kb'),
                    'max_seconds' => config('chat.uploads.voice.max_seconds'),
                ],
                'editWindowMinutes' => config('chat.edit_window_minutes'),
                'deleteWindowMinutes' => config('chat.delete_for_everyone_window_minutes'),
            ],
            'presence' => [
                'onlineThresholdSeconds' => config('chat.online_threshold_seconds'),
                'heartbeatSeconds' => config('chat.heartbeat_interval_seconds'),
                'typingTtlSeconds' => config('chat.typing_ttl_seconds'),
            ],
            'realtime' => [
                'enabled' => config('broadcasting.default') === 'reverb' && ! empty($reverb['key']),
                'key' => $reverb['key'] ?? null,
                'host' => $reverb['options']['host'] ?? null,
                'port' => (int) ($reverb['options']['port'] ?? 443),
                'scheme' => $reverb['options']['scheme'] ?? 'https',
                'pollingIntervalMs' => config('chat.polling_interval_ms'),
            ],
            'calls' => [
                'enabled' => (bool) config('chat.calls.enabled', true) && Route::has('calls.store'),
                'ringTimeoutSeconds' => (int) config('chat.calls.ring_timeout_seconds', 45),
                'maxGroupParticipants' => (int) config('chat.calls.max_group_participants', 4),
                // Badge on the Calls tab (K1).
                'unseenMissed' => app(CallLogService::class)->unseenMissed($user),
                'heartbeatSeconds' => (int) config('chat.calls.heartbeat_seconds', 20),
            ],
        ]);
    }

    private function template(string $name, array $params): string
    {
        return str_replace(['__ID__', '%5F%5FID%5F%5F'], '__ID__', route($name, $params));
    }
}
