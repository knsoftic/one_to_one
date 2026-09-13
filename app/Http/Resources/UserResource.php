<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public profile of a user. Private fields (email, phone, preferences) are
 * only included when the viewer is the user themself.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isSelf = $request->user()?->is($this->resource) ?? false;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $this->avatar_url,
            'initials' => $this->initials,
            'avatar_hue' => $this->avatar_hue,
            'is_online' => $this->isOnlineNow(),
            'last_seen' => $this->last_seen?->toIso8601String(),
            $this->mergeWhen($isSelf, fn () => [
                'email' => $this->email,
                'phone' => $this->phone,
                'role' => $this->role,
                'theme' => $this->theme,
                'notifications_enabled' => $this->notifications_enabled,
                'notification_sound' => $this->notification_sound,
            ]),
        ];
    }
}
