<?php

namespace App\Http\Requests\Chat;

use App\Models\Call;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Start a voice or video call in a conversation. Same rules as sending a
 * message: participants only (404 otherwise) and nobody blocked (403).
 */
class StartCallRequest extends FormRequest
{
    public function authorize(): Response
    {
        if (! config('chat.calls.enabled', true)) {
            return Response::deny('Calls are not available.');
        }

        return Gate::inspect('sendMessage', $this->route('conversation'));
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Call::TYPES)],
            'client_id' => ClientIdRule::rules(),
        ];
    }
}
