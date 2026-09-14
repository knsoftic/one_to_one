<?php

namespace App\Http\Requests\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\PollVote;
use App\Rules\DocumentType;
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
            'message' => ['nullable', 'string', 'max:'.config('chat.max_message_length'), 'required_without_all:attachment,voice,sticker_id,gif_id,location,contact,poll'],
            // A poll (M20).
            'poll' => ['nullable', 'array:question,options,multiple', 'prohibits:attachment,voice,sticker_id,gif_id,location,contact'],
            'poll.question' => ['required_with:poll', 'string', 'max:255'],
            'poll.options' => ['required_with:poll', 'array', 'min:2', 'max:'.PollVote::MAX_OPTIONS],
            'poll.options.*' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'poll.multiple' => ['nullable', 'boolean'],
            // A contact card (M19).
            'contact' => ['nullable', 'array:name,phones', 'prohibits:attachment,voice,sticker_id,gif_id,location'],
            'contact.name' => ['required_with:contact', 'string', 'max:100'],
            'contact.phones' => ['required_with:contact', 'array', 'min:1', 'max:5'],
            'contact.phones.*' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-.]{5,32}$/'],
            // Current or live location (M18).
            'location' => ['nullable', 'array:lat,lng,accuracy,live_minutes', 'prohibits:attachment,voice,sticker_id,gif_id'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'location.live_minutes' => ['nullable', 'integer', Rule::in(Message::LIVE_LOCATION_MINUTES)],
            // One of the sender's own stickers (M17).
            'sticker_id' => [
                'nullable', 'integer', 'prohibits:attachment,voice,gif_id',
                Rule::exists('stickers', 'id')->where('user_id', $this->user()?->getKey()),
            ],
            // A GIF from GIF search, downloaded by the server.
            'gif_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', 'prohibits:attachment,voice'],
            'reply_to_id' => [
                'nullable', 'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($conversation) {
                    $reply = Message::query()->whereKey((int) $value)->where('deleted_for_everyone', false)->first();
                    $user = $this->user();

                    if ($reply && (int) $reply->conversation_id === (int) $conversation->getKey()) {
                        return;
                    }

                    // G7: "Reply privately" to someone's group message, in your chat with them.
                    $private = $reply
                        && $reply->isGroupMessage()
                        && ! $conversation->isGroup()
                        && ! $conversation->isSelf()
                        && ! $reply->isSentBy($user)
                        && $conversation->hasParticipant((int) $reply->sender_id)
                        && $reply->involves($user)
                        && ! $reply->isDeletedFor($user);

                    if (! $private) {
                        $fail('The message you are replying to is no longer available.');
                    }
                },
            ],
            'client_id' => ['nullable', 'string', 'max:64'],
            // @mentions in group chats (G4): who, and the name as written in the text.
            'mentions' => ['nullable', 'array', 'max:50'],
            'mentions.*.id' => ['required', 'integer'],
            'mentions.*.name' => ['required', 'string', 'max:100'],
            // false = the sender removed the link preview.
            'link_preview' => ['nullable', 'boolean'],
            // Photos/videos picked together share an id and are shown as an album.
            'album_id' => ['nullable', 'uuid'],
            // View once photo, video or voice message (M22).
            'view_once' => ['nullable', 'boolean'],
            // Photo quality: "hd" keeps more pixels.
            'quality' => ['nullable', Rule::in(['standard', 'hd'])],
            'attachment' => ['nullable', 'file', 'prohibits:voice'],
            'voice' => [
                'nullable', 'file',
                'mimetypes:'.implode(',', $uploads['voice']['mimetypes']),
                'max:'.$uploads['voice']['max_kb'],
            ],
            'duration' => ['nullable', 'numeric', 'min:0', 'max:'.($this->attachmentType() === Message::TYPE_VIDEO
                ? $uploads['video']['max_seconds']
                : $uploads['voice']['max_seconds'] + 5)],
            // Poster frame of a video, captured by the sender's browser (re-encoded on the server).
            'thumbnail' => [
                'nullable', 'file', 'prohibits:voice', 'image', 'mimes:jpg,jpeg,png,webp',
                'max:'.$uploads['video']['thumbnail_max_kb'], 'dimensions:max_width=4096,max_height=4096',
            ],
        ];

        if ($this->attachmentType() === Message::TYPE_VIDEO) {
            array_push($rules['attachment'],
                'mimetypes:'.implode(',', $uploads['video']['mimetypes']),
                'extensions:'.implode(',', $uploads['video']['extensions']),
                'max:'.$uploads['video']['max_kb'],
            );
        } elseif ($this->attachmentType() === Message::TYPE_IMAGE && $this->hasFile('attachment')) {
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
                'extensions:'.implode(',', array_keys($uploads['document']['types'])),
                new DocumentType,
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
        if ($this->filled('sticker_id')) {
            return Message::TYPE_STICKER;
        }

        if ($this->filled('location')) {
            return Message::TYPE_LOCATION;
        }

        if ($this->filled('contact')) {
            return Message::TYPE_CONTACT;
        }

        if ($this->filled('poll')) {
            return Message::TYPE_POLL;
        }

        if ($this->filled('gif_id')) {
            return Message::TYPE_IMAGE;
        }

        if ($this->hasFile('voice')) {
            return Message::TYPE_VOICE;
        }

        if ($file = $this->file('attachment')) {
            $extension = strtolower($file->getClientOriginalExtension());

            return match (true) {
                in_array($extension, config('chat.uploads.image.extensions'), true) => Message::TYPE_IMAGE,
                in_array($extension, config('chat.uploads.video.extensions'), true) => Message::TYPE_VIDEO,
                default => Message::TYPE_DOCUMENT,
            };
        }

        return Message::TYPE_TEXT;
    }

    public function messages(): array
    {
        $uploads = config('chat.uploads');

        $allowed = match ($this->attachmentType()) {
            Message::TYPE_VIDEO => 'Only MP4, WEBM, MOV and 3GP videos can be sent.',
            Message::TYPE_IMAGE => 'Only JPG, JPEG, PNG and GIF images can be sent.',
            default => DocumentType::MESSAGE,
        };

        return [
            'message.required_without_all' => 'Type a message or attach a file before sending.',
            'reply_to_id.exists' => 'The message you are replying to is no longer available.',
            'attachment.mimes' => $allowed,
            'attachment.mimetypes' => $allowed,
            'attachment.extensions' => $allowed,
            'attachment.image' => 'The image file is not valid.',
            'attachment.max' => match ($this->attachmentType()) {
                Message::TYPE_IMAGE => 'Images may not be larger than '.round($uploads['image']['max_kb'] / 1024).' MB.',
                Message::TYPE_VIDEO => 'Videos may not be larger than '.round($uploads['video']['max_kb'] / 1024).' MB.',
                default => 'Documents may not be larger than '.round($uploads['document']['max_kb'] / 1024).' MB.',
            },
            'thumbnail.*' => 'The video preview image is not valid.',
            'sticker_id.exists' => 'This sticker is no longer in your stickers.',
            'contact.phones.*.regex' => 'Enter a valid phone number.',
            'poll.options.min' => 'A poll needs at least 2 options.',
            'poll.options.*.distinct' => 'Poll options must be different.',
            'voice.mimetypes' => 'The voice message format is not supported.',
            'voice.max' => 'Voice messages may not be larger than '.round($uploads['voice']['max_kb'] / 1024).' MB.',
        ];
    }
}
