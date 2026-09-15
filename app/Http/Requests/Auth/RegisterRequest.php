<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\ValidatesProfileFields;
use App\Models\AppSetting;
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
        // Short sign-up: name, mobile number and password. The username is made from the
        // name (changeable later) and the email follows the admin panel setting.
        $email = AppSetting::get('signup_email');

        return [
            'name' => $this->nameRules(),
            'username' => array_values(array_map(fn ($rule) => $rule === 'required' ? 'nullable' : $rule, $this->usernameRules())),
            'email' => $email === 'hidden' ? ['prohibited'] : $this->emailRules(required: $email === 'required'),
            'phone' => $this->phoneRules(),
            'password' => ['required', 'string', Password::defaults()],
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
