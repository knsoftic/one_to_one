<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The mobile app asking for a background-notification token.
 */
class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::in(['android', 'ios'])],
            'app_version' => ['nullable', 'string', 'max:20', 'regex:/^[\w.\-+ ]+$/'],
        ];
    }
}
