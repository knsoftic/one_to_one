<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Concerns\ValidatesProfileFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\NotIn;

class UpdateProfileRequest extends FormRequest
{
    use ValidatesProfileFields;

    /**
     * Use a dedicated error bag so profile and password forms on the same
     * settings page show their own errors.
     */
    protected $errorBag = 'profile';

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeProfileInput();
    }

    public function rules(): array
    {
        $id = $this->user()->getKey();

        // The seeded administrator may keep a reserved username.
        $usernameRules = $this->user()->isAdmin()
            ? array_values(array_filter($this->usernameRules($id), fn ($rule) => ! $rule instanceof NotIn))
            : $this->usernameRules($id);

        return [
            'name' => $this->nameRules(),
            'username' => $usernameRules,
            'email' => $this->emailRules($id),
            'phone' => $this->phoneRules($id),
            'profile_image' => $this->avatarRules(),
            'about' => ['nullable', 'string', 'max:139'],
            'remove_profile_image' => ['nullable', 'boolean'],
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
