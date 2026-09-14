<?php

namespace App\Http\Requests\Chat;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Your own id opens "Message yourself" (C7).
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('status', User::STATUS_ACTIVE),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'This user is not available.',
        ];
    }
}
