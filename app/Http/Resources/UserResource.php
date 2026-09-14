<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\PrivacyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public profile of a user. Private fields (email, phone, preferences) are
 * only included when the viewer is the user themself. Last seen, online,
 * photo and About follow the user's privacy settings (Phase 6).
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isSelf = $viewer?->is($this->resource) ?? false;
        $privacy = app(PrivacyService::class);
        $presence = $privacy->presenceFor($this->resource, $viewer);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar_url' => $privacy->canSeePhoto($this->resource, $viewer) ? $this->avatar_url : null,
            'initials' => $this->initials,
            'avatar_hue' => $this->avatar_hue,
            'is_online' => $presence['is_online'],
            'last_seen' => $presence['last_seen'],
            'about' => $privacy->canSeeAbout($this->resource, $viewer) ? $this->about : null,
            $this->mergeWhen($isSelf, fn () => [
                'email' => $this->email,
                'phone' => $this->phone,
                'role' => $this->role,
                'theme' => $this->theme,
                'notifications_enabled' => $this->notifications_enabled,
                'notification_sound' => $this->notification_sound,
                'last_seen_privacy' => $this->last_seen_privacy,
                'online_privacy' => $this->online_privacy,
                'photo_privacy' => $this->photo_privacy,
                'about_privacy' => $this->about_privacy,
                'read_receipts' => (bool) $this->read_receipts,
                'two_step_enabled' => $this->two_step_pin !== null,
            ]),
        ];
    }
}
