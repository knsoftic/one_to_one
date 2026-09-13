<?php

namespace App\Http\Requests\Chat;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DeleteMessageRequest extends FormRequest
{
    public const SCOPE_ME = 'me';

    public const SCOPE_EVERYONE = 'everyone';

    public function authorize(): Response
    {
        $ability = $this->input('scope') === self::SCOPE_EVERYONE ? 'deleteForEveryone' : 'deleteForMe';

        return Gate::inspect($ability, $this->route('message'));
    }

    public function rules(): array
    {
        return [
            'scope' => ['required', 'in:'.self::SCOPE_ME.','.self::SCOPE_EVERYONE],
        ];
    }

    public function forEveryone(): bool
    {
        return $this->input('scope') === self::SCOPE_EVERYONE;
    }
}
