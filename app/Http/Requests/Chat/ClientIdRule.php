<?php

namespace App\Http\Requests\Chat;

/**
 * Validation for the id a browser tab or phone generates for itself during a call.
 */
final class ClientIdRule
{
    /**
     * @return list<string>
     */
    public static function rules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'];
    }
}
