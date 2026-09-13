<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesProfileFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use ValidatesProfileFields;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeProfileInput();
    }

    public function rules(): array
    {
        return [
            'name' => $this->nameRules(),
            'username' => $this->usernameRules(),
            'email' => $this->emailRules(),
            'phone' => $this->phoneRules(),
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'profile_image' => $this->avatarRules(),
        ];
    }

    public function messages(): array
    {
        return $this->profileMessages();
    }

    public function attributes(): array
    {
        return [
            'name' => 'full name',
            'phone' => 'mobile number',
            'profile_image' => 'profile image',
        ];
    }
}
