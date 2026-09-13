<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Concerns\ValidatesProfileFields;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    use ValidatesProfileFields;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = $this->avatarRules();
        $rules[array_search('nullable', $rules, true)] = 'required';

        return ['profile_image' => $rules];
    }

    public function messages(): array
    {
        return $this->profileMessages() + [
            'profile_image.required' => 'Choose a photo to upload, or skip this step.',
        ];
    }

    public function attributes(): array
    {
        return ['profile_image' => 'profile photo'];
    }
}
