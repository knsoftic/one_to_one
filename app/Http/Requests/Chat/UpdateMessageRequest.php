<?php

namespace App\Http\Requests\Chat;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateMessageRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->route('message'));
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.config('chat.max_message_length')],
        ];
    }
}
