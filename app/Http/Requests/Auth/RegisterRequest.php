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
        // A code typed in lower case still matches (codes are upper-case letters and digits).
        if (is_string($this->input('ref'))) {
            $this->merge(['ref' => strtoupper(trim($this->input('ref'))) ?: null]);
        }
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
            // Refer & earn (Y2): the inviter's code from /r/{code}.
            'ref' => ['nullable', 'string', 'size:8', 'regex:/^[A-Z2-9]{8}$/'],
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
