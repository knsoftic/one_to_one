<?php

namespace App\Http\Requests\Chat;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Text, image, document or voice message.
 *
 * Files are validated by detected content type ("mimes"/"mimetypes") AND by
 * client extension ("extensions"), with per-type size limits from config/chat.php.
 */
class SendMessageRequest extends FormRequest
{
    /**
     * Authorization runs before validation, so non-participants learn nothing
     * about the conversation (404) and blocked users get a clear message (403).
     */
    public function authorize(): Response
    {
        return Gate::inspect('sendMessage', $this->route('conversation'));
    }

    public function rules(): array
    {
        /** @var Conversation $conversation */
        $conversation = $this->route('conversation');
        $uploads = config('chat.uploads');

        $rules = [
            'message' => ['nullable', 'string', 'max:'.config('chat.max_message_length'), 'required_without_all:attachment,voice'],
            'reply_to_id' => [
                'nullable', 'integer',
                Rule::exists('messages', 'id')
                    ->where('conversation_id', $conversation->getKey())
                    ->where('deleted_for_everyone', false),
            ],
            'client_id' => ['nullable', 'string', 'max:64'],
            'attachment' => ['nullable', 'file', 'prohibits:voice'],
            'voice' => [
                'nullable', 'file',
                'mimetypes:'.implode(',', $uploads['voice']['mimetypes']),
                'max:'.$uploads['voice']['max_kb'],
            ],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:'.($uploads['voice']['max_seconds'] + 5)],
        ];

        if ($this->attachmentType() === Message::TYPE_IMAGE) {
            $extensions = implode(',', $uploads['image']['extensions']);
            $max = $uploads['image']['max_dimension'];

            array_push($rules['attachment'],
                'image',
                'mimes:'.$extensions,
                'extensions:'.$extensions,
                'max:'.$uploads['image']['max_kb'],
                "dimensions:max_width={$max},max_height={$max}",
            );
        } elseif ($this->hasFile('attachment')) {
            array_push($rules['attachment'],
                'mimetypes:'.implode(',', $uploads['document']['mimetypes']),
                'extensions:'.implode(',', $uploads['document']['extensions']),
                'max:'.$uploads['document']['max_kb'],
            );
        }

        return $rules;
    }

    /**
     * The kind of message being sent, derived from the uploaded field.
     */
    public function attachmentType(): string
    {
        if ($this->hasFile('voice')) {
            return Message::TYPE_VOICE;
        }

        if ($file = $this->file('attachment')) {
            $extension = strtolower($file->getClientOriginalExtension());

            return in_array($extension, config('chat.uploads.image.extensions'), true)
                ? Message::TYPE_IMAGE
                : Message::TYPE_DOCUMENT;
        }

        return Message::TYPE_TEXT;
    }

    public function messages(): array
    {
        $uploads = config('chat.uploads');

        return [
            'message.required_without_all' => 'Type a message or attach a file before sending.',
            'reply_to_id.exists' => 'The message you are replying to is no longer available.',
            'attachment.mimes' => 'Only JPG, JPEG, PNG, PDF, DOC and DOCX files are allowed.',
            'attachment.mimetypes' => 'Only JPG, JPEG, PNG, PDF, DOC and DOCX files are allowed.',
            'attachment.extensions' => 'Only JPG, JPEG, PNG, PDF, DOC and DOCX files are allowed.',
            'attachment.image' => 'The image file is not valid.',
            'attachment.max' => $this->attachmentType() === Message::TYPE_IMAGE
                ? 'Images may not be larger than '.round($uploads['image']['max_kb'] / 1024).' MB.'
                : 'Documents may not be larger than '.round($uploads['document']['max_kb'] / 1024).' MB.',
            'voice.mimetypes' => 'The voice message format is not supported.',
            'voice.max' => 'Voice messages may not be larger than '.round($uploads['voice']['max_kb'] / 1024).' MB.',
        ];
    }
}
