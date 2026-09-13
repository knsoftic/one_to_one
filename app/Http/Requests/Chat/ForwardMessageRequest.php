<?php

namespace App\Http\Requests\Chat;

use App\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Forward a message to up to five chats (WhatsApp's limit per forward).
 */
class ForwardMessageRequest extends FormRequest
{
    public const MAX_CHATS = 5;

    private ?Collection $targets = null;

    public function authorize(): Response
    {
        return Gate::inspect('forward', $this->route('message'));
    }

    public function rules(): array
    {
        return [
            'conversation_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_CHATS],
            'conversation_ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'conversation_ids.required' => 'Choose at least one chat.',
            'conversation_ids.max' => 'You can forward to at most '.self::MAX_CHATS.' chats at a time.',
        ];
    }

    /**
     * Every chat must be the user's own and allow sending (not blocked).
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $ids = collect($this->input('conversation_ids'))->map(fn ($id) => (int) $id);
                $conversations = Conversation::query()->forUser($this->user())->whereKey($ids)->get();

                if ($conversations->count() !== $ids->count()) {
                    $validator->errors()->add('conversation_ids', 'One of the chats is not available.');

                    return;
                }

                foreach ($conversations as $conversation) {
                    $response = Gate::inspect('sendMessage', $conversation);
                    if ($response->denied()) {
                        $validator->errors()->add('conversation_ids', $response->message() ?: 'You cannot send messages to one of these chats.');

                        return;
                    }
                }

                // Keep the order the user chose.
                $this->targets = $conversations->sortBy(fn ($c) => $ids->search($c->id))->values();
            },
        ];
    }

    /**
     * @return Collection<int, Conversation>
     */
    public function conversations(): Collection
    {
        return $this->targets ?? new Collection;
    }
}
