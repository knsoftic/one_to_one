<?php

namespace App\Http\Requests\Profile;

use App\Models\User;
use App\Services\PrivacyService;
use App\Support\ChatPreferences;
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
            // Phase 8.
            'font_size' => ['sometimes', 'required', Rule::in(ChatPreferences::FONT_SIZES)],
            'notification_tone' => ['sometimes', 'required', Rule::in(ChatPreferences::TONES)],
            'notification_vibrate' => ['sometimes', 'required', Rule::in(ChatPreferences::VIBRATIONS)],
            'auto_download' => ['sometimes', 'array:wifi,mobile'],
            'auto_download.wifi' => ['sometimes', 'array'],
            'auto_download.wifi.*' => [Rule::in(ChatPreferences::DOWNLOAD_KINDS)],
            'auto_download.mobile' => ['sometimes', 'array'],
            'auto_download.mobile.*' => [Rule::in(ChatPreferences::DOWNLOAD_KINDS)],
            // Phase 6 — privacy.
            'last_seen_privacy' => ['sometimes', 'required', Rule::in(PrivacyService::MODES)],
            'online_privacy' => ['sometimes', 'required', Rule::in(PrivacyService::ONLINE_MODES)],
            'photo_privacy' => ['sometimes', 'required', Rule::in(PrivacyService::MODES)],
            'about_privacy' => ['sometimes', 'required', Rule::in(PrivacyService::MODES)],
            'read_receipts' => ['sometimes', 'boolean'],
        ];
    }
}
