<?php

namespace App\Services;

use App\Models\User;

/**
 * The limits that apply to one person (Y2): the app defaults, raised by an active plan's
 * snapshot. A plan can only ever raise a limit, never lower it.
 */
class LimitService
{
    public function __construct(private readonly PlanService $plans) {}

    /** @return array{upload_mb: int, group_members: int, broadcast_recipients: int, storage_mb: int} */
    public function for(User $user): array
    {
        $defaults = $this->defaults();
        $plan = $this->plans->benefits($user)['limits'] ?? [];

        foreach ($defaults as $key => $value) {
            if (isset($plan[$key]) && (int) $plan[$key] > $value) {
                $defaults[$key] = (int) $plan[$key];
            }
        }

        return $defaults;
    }

    /** @return array{upload_mb: int, group_members: int, broadcast_recipients: int, storage_mb: int} */
    public function defaults(): array
    {
        return [
            'upload_mb' => (int) ceil(max(
                (int) config('chat.uploads.image.max_kb', 0),
                (int) config('chat.uploads.video.max_kb', 0),
                (int) config('chat.uploads.document.max_kb', 0),
            ) / 1024),
            'group_members' => (int) config('chat.groups.max_members', 256),
            'broadcast_recipients' => (int) config('chat.broadcasts.max_recipients', 256),
            'storage_mb' => (int) config('chat.storage.default_mb', 0),
        ];
    }

    /** The biggest upload of one type, in KB: the app's own limit or the plan's, whichever is larger. */
    public function uploadKb(User $user, string $type): int
    {
        $base = (int) config("chat.uploads.{$type}.max_kb", 0);
        $planMb = (int) ($this->plans->benefits($user)['limits']['upload_mb'] ?? 0);

        return max($base, $planMb * 1024);
    }

    public function groupMembers(User $user): int
    {
        return $this->for($user)['group_members'];
    }

    public function broadcastRecipients(User $user): int
    {
        return $this->for($user)['broadcast_recipients'];
    }

    /** 0 = unlimited. */
    public function storageMb(User $user): int
    {
        return $this->for($user)['storage_mb'];
    }
}
