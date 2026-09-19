<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Name and picture of chats as one person sees them (saved contact names, photo privacy),
 * for lists outside the chat screen: Manage storage (D6), export (D7) and backups (D8).
 */
class ChatCardService
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly PrivacyService $privacy,
    ) {}

    /**
     * @param  iterable<int>  $ids
     * @return array<int, array{id: int, type: string, name: string, avatar_url: ?string, initials: string, avatar_hue: int}>
     */
    public function for(User $user, iterable $ids): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, Conversation> $conversations */
        $conversations = Conversation::query()->whereKey($ids)->with(['userOne', 'userTwo', 'community'])->get();
        $others = $conversations->filter(fn (Conversation $c) => $c->type === Conversation::TYPE_DIRECT)
            ->map(fn (Conversation $c) => $this->other($c, $user))
            ->filter();
        $saved = $this->contacts->savedNames($user, $others->pluck('id')->unique()->values()->all());

        return $conversations->mapWithKeys(function (Conversation $chat) use ($user, $saved) {
            if ($chat->type === Conversation::TYPE_DIRECT) {
                $self = (int) $chat->user_one_id === (int) $chat->user_two_id;
                $other = $this->other($chat, $user);

                return [$chat->id => [
                    'id' => $chat->id,
                    'type' => $chat->type,
                    'name' => $self ? $user->name.' (You)' : ($other ? ($saved[$other->id] ?? $other->name) : 'Deleted account'),
                    'avatar_url' => $other && ($self || $this->privacy->canSeePhoto($other, $user)) ? $other->avatar_url : null,
                    'initials' => $other?->initials ?? '?',
                    'avatar_hue' => (int) ($other?->avatar_hue ?? 0),
                    'verified' => $other ? app(BadgeService::class)->isVerified($other) : false,
                ]];
            }

            return [$chat->id => [
                'id' => $chat->id,
                'type' => $chat->type,
                'name' => $chat->is_announcement ? ($chat->community?->name ?? $chat->name ?? 'Community') : ($chat->name ?: 'Broadcast list'),
                'avatar_url' => $chat->groupAvatarUrl(),
                'initials' => $chat->groupInitials(),
                'avatar_hue' => $chat->groupHue(),
            ]];
        })->all();
    }

    private function other(Conversation $chat, User $user): ?User
    {
        return (int) $chat->user_one_id === (int) $user->getKey() ? $chat->userTwo : $chat->userOne;
    }
}
