<?php

namespace App\Http\Requests\Concerns;

use App\Support\Phone;
use Illuminate\Validation\Rule;

/**
 * Shared validation + normalisation for registration and profile updates.
 */
trait ValidatesProfileFields
{
    protected function normalizeProfileInput(): void
    {
        $data = [];

        if ($this->has('name')) {
            $data['name'] = preg_replace('/\s+/u', ' ', trim((string) $this->input('name')));
        }

        if ($this->has('username')) {
            $data['username'] = mb_strtolower(ltrim(trim((string) $this->input('username')), '@'));
        }

        if ($this->has('email')) {
            $data['email'] = mb_strtolower(trim((string) $this->input('email')));
        }

        if ($this->has('phone')) {
            $data['phone'] = Phone::normalize((string) $this->input('phone'));
        }

        $this->merge($data);
    }

    protected function nameRules(): array
    {
        return ['required', 'string', 'min:2', 'max:100', 'regex:/^[\pL\pM\s.\'-]+$/u'];
    }

    protected function usernameRules(?int $ignoreId = null): array
    {
        return [
            'required', 'string', 'min:3', 'max:30',
            // letters, numbers, dot and underscore; must start/end with a letter or number
            'regex:/^[a-z0-9](?:[a-z0-9._]*[a-z0-9])?$/',
            Rule::notIn(config('chat.reserved_usernames')),
            Rule::unique('users', 'username')->ignore($ignoreId),
        ];
    }

    protected function emailRules(?int $ignoreId = null): array
    {
        return ['required', 'string', 'email:rfc', 'max:191', Rule::unique('users', 'email')->ignore($ignoreId)];
    }

    protected function phoneRules(?int $ignoreId = null): array
    {
        return ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/', Rule::unique('users', 'phone')->ignore($ignoreId)];
    }

    protected function avatarRules(): array
    {
        $config = config('chat.uploads.avatar');

        return [
            'nullable', 'file', 'image',
            'mimes:'.implode(',', $config['extensions']),
            'extensions:'.implode(',', $config['extensions']),
            'max:'.$config['max_kb'],
            'dimensions:min_width=64,min_height=64,max_width=4096,max_height=4096',
        ];
    }

    protected function profileMessages(): array
    {
        return [
            'name.regex' => 'The name may only contain letters, spaces, dots, apostrophes and hyphens.',
            'username.regex' => 'The username may only contain lowercase letters, numbers, dots and underscores, and must start and end with a letter or number.',
            'username.not_in' => 'This username is reserved. Please choose another one.',
            'phone.regex' => 'Enter a valid mobile number (7–15 digits, optional leading +).',
            'profile_image.dimensions' => 'The profile image must be between 64×64 and 4096×4096 pixels.',
        ];
    }
}
