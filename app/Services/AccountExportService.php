<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\Call;
use App\Models\ChatList;
use App\Models\CoinTransaction;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\StarredMessage;
use App\Models\Status;
use App\Models\Subscription;
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
            // Paid features (Y2): coins, payments, referrals and promotions — never proof images or provider payloads.
            'money' => [
                'wallet' => app(CoinService::class)->summary($user),
                'coin_history' => CoinTransaction::query()->where('user_id', $id)->orderByDesc('id')->limit(500)->get()
                    ->map(fn (CoinTransaction $row) => ['type' => $row->label(), 'coins' => $row->amount, 'note' => $row->note, 'at' => $this->date($row->created_at)])
                    ->all(),
                'payments' => Payment::query()->where('user_id', $id)->orderByDesc('id')->get()
                    ->map(fn (Payment $p) => ['item' => $p->itemLabel(), 'amount' => $p->amount_minor / 100, 'currency' => $p->currency, 'method' => Payment::GATEWAYS[$p->gateway] ?? $p->gateway, 'status' => $p->status, 'at' => $this->date($p->created_at)])
                    ->all(),
                'subscriptions' => Subscription::query()->where('user_id', $id)->with('plan:id,name')->orderByDesc('id')->get()
                    ->map(fn (Subscription $s) => ['plan' => $s->plan?->name, 'status' => $s->status, 'from' => $this->date($s->starts_at), 'until' => $this->date($s->ends_at)])
                    ->all(),
                'referrals' => [
                    'invited_by_me' => Referral::query()->where('referrer_id', $id)->with('referred:id,name')->orderByDesc('id')->get()
                        ->map(fn (Referral $r) => ['name' => $r->referred?->name, 'status' => $r->status, 'coins' => $r->referrer_coins, 'at' => $this->date($r->rewarded_at ?? $r->created_at)])
                        ->all(),
                    'i_was_invited' => $user->referred_by !== null,
                ],
                'promotions' => AdCampaign::query()->where('owner_id', $id)->orderByDesc('id')->get()
                    ->map(fn (AdCampaign $c) => ['kind' => $c->kind, 'title' => $c->title, 'status' => $c->status, 'coins' => $c->coins_spent, 'refunded' => $c->coins_refunded, 'views' => $c->impressions, 'taps' => $c->clicks, 'at' => $this->date($c->created_at)])
                    ->all(),
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
