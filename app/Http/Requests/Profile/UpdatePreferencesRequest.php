<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use App\Services\PrivacyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePreferencesRequest extends FormRequest
{
    protected $errorBag = 'preferences';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'theme' => ['sometimes', 'required', Rule::in(User::THEMES)],
            'notifications_enabled' => ['sometimes', 'boolean'],
            'notification_sound' => ['sometimes', 'boolean'],
            // Phase 6 — privacy.
            'last_seen_privacy' => ['sometimes', 'required', Rule::in(PrivacyService::MODES)],
            'online_privacy' => ['sometimes', 'required', Rule::in(PrivacyService::ONLINE_MODES)],
            'photo_privacy' => ['sometimes', 'required', Rule::in(PrivacyService::MODES)],
            'about_privacy' => ['sometimes', 'required', Rule::in(PrivacyService::MODES)],
            'read_receipts' => ['sometimes', 'boolean'],
        ];
    }
}
