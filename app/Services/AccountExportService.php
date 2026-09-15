<?php

namespace App\Services;

use App\Models\Call;
use App\Models\ChatList;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\StarredMessage;
use App\Models\Status;
use App\Models\User;
use App\Models\UserReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A5 — "Download my account data": a report of the account, its settings and what it
 * is part of. Like WhatsApp's account report, messages and files are not included.
 */
class AccountExportService
{
    public function __construct(private readonly SessionService $sessions) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, Request $request): array
    {
        $id = (int) $user->getKey();
        $memberships = ConversationMember::query()
            ->where('user_id', $id)
            ->whereNull('left_at')
            ->whereHas('conversation', fn ($q) => $q->whereIn('type', [Conversation::TYPE_GROUP, Conversation::TYPE_CHANNEL])->whereNull('ended_at'))
            ->with(['conversation' => fn ($q) => $q->withCount('activeMembers')->with('community:id,name')])
            ->orderBy('joined_at')
            ->get();

        return [
            'report' => [
                'app' => config('app.name'),
                'generated_at' => now()->toIso8601String(),
                'note' => 'Your messages, photos, videos and files are not part of this report.',
            ],
            'account' => [
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null,
                'mobile_number' => $user->phone,
                'mobile_number_verified' => $user->phone_verified_at !== null,
                'about' => $user->about,
                'has_profile_photo' => $user->profile_image !== null,
                'created_at' => $this->date($user->created_at),
                'last_seen' => $this->date($user->last_seen),
            ],
            'settings' => [
                'theme' => $user->theme,
                'notifications' => (bool) $user->notifications_enabled,
                'notification_sound' => (bool) $user->notification_sound,
                'notification_tone' => $user->notification_tone,
                'notification_vibrate' => $user->notification_vibrate,
                'font_size' => $user->font_size,
                'wallpaper' => $user->wallpaper ?? 'default',
                'privacy' => [
                    'last_seen' => $user->last_seen_privacy,
                    'online' => $user->online_privacy,
                    'profile_photo' => $user->photo_privacy,
                    'about' => $user->about_privacy,
                    'status' => $user->status_privacy,
                    'read_receipts' => (bool) $user->read_receipts,
                ],
                'two_step_verification' => [
                    'on' => $user->two_step_pin !== null,
                    'since' => $this->date($user->two_step_enabled_at),
                ],
                'chat_lock' => $user->chat_lock_pin !== null,
            ],
            'contacts' => Contact::query()->where('user_id', $id)->with('contactUser:id,username')->orderBy('name')->get()
                ->map(fn (Contact $contact) => ['name' => $contact->name, 'mobile_number' => $contact->phone, 'username' => $contact->contactUser?->username])
                ->all(),
            'blocked' => $user->blockedUsers()->orderBy('name')->get()
                ->map(fn (User $blocked) => ['name' => $blocked->name, 'username' => $blocked->username, 'blocked_at' => $this->date($blocked->pivot->created_at)])
                ->all(),
            'groups' => $memberships->filter(fn (ConversationMember $m) => $m->conversation->isGroup() && ! $m->conversation->is_announcement)
                ->map(fn (ConversationMember $m) => [
                    'name' => $m->conversation->name,
                    'role' => $m->role,
                    'members' => (int) $m->conversation->active_members_count,
                    'community' => $m->conversation->community?->name,
                    'joined_at' => $this->date($m->joined_at),
                ])->values()->all(),
            'communities' => $memberships->filter(fn (ConversationMember $m) => $m->conversation->is_announcement && $m->conversation->community)
                ->map(fn (ConversationMember $m) => [
                    'name' => $m->conversation->community->name,
                    'role' => $m->role,
                    'members' => (int) $m->conversation->active_members_count,
                    'joined_at' => $this->date($m->joined_at),
                ])->values()->all(),
            'channels' => $memberships->filter(fn (ConversationMember $m) => $m->conversation->isChannel())
                ->map(fn (ConversationMember $m) => [
                    'name' => $m->conversation->name,
                    'role' => $m->isAdmin() ? 'owner' : 'follower',
                    'followers' => (int) $m->conversation->active_members_count,
                    'since' => $this->date($m->joined_at),
                ])->values()->all(),
            'broadcast_lists' => Conversation::query()->where('type', Conversation::TYPE_BROADCAST)->where('created_by', $id)->withCount('broadcastRecipients')->orderBy('name')->get()
                ->map(fn (Conversation $list) => ['name' => $list->name, 'recipients' => (int) $list->broadcast_recipients_count])
                ->all(),
            'chat_lists' => ChatList::query()->where('user_id', $id)->withCount('conversations')->orderBy('position')->get()
                ->map(fn (ChatList $list) => ['name' => $list->name, 'chats' => (int) $list->conversations_count])
                ->all(),
            'activity' => [
                'one_to_one_chats' => Conversation::query()->where('type', Conversation::TYPE_DIRECT)->whereNotNull('last_message_id')
                    ->where(fn ($q) => $q->where('user_one_id', $id)->orWhere('user_two_id', $id))->count(),
                'messages_sent' => Message::query()->where('sender_id', $id)->count(),
                'messages_received' => Message::query()->where('receiver_id', $id)->count(),
                'starred_messages' => StarredMessage::query()->where('user_id', $id)->count(),
                'calls_made' => Call::query()->where('caller_id', $id)->count(),
                'calls_received' => Call::query()->where('callee_id', $id)->count(),
                'status_updates_now' => Status::query()->where('user_id', $id)->active()->count(),
                'saved_stickers' => $user->stickers()->count(),
                'reports_sent' => UserReport::query()->where('reporter_id', $id)->count(),
            ],
            'devices' => [
                'signed_in' => $this->sessions->list($user, $request)
                    ->map(fn (array $session) => ['device' => $session['device'], 'network_address' => $session['ip'], 'last_active' => $session['last_active'], 'this_device' => $session['current']])
                    ->values()->all(),
                'two_step_trusted_browsers' => $user->trustedDevices()->latest('last_used_at')->get()
                    ->map(fn ($device) => ['name' => $device->name, 'network_address' => $device->ip_address, 'last_used' => $this->date($device->last_used_at)])
                    ->all(),
            ],
        ];
    }

    private function date(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
