<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Sticker;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Each person's sticker collection: stickers made from a photo or emoji, and
 * stickers saved from chats. Files live on the private chat disk.
 */
class StickerService
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly ImageService $images,
    ) {}

    /**
     * Make a sticker from an uploaded image (re-encoded to a 512×512 WebP).
     *
     * @throws RuntimeException when the image cannot be decoded
     */
    public function create(User $user, UploadedFile $file): Sticker
    {
        return $this->remember($user, $this->images->sticker((string) $file->getRealPath(), Sticker::SIZE));
    }

    /**
     * Add a sticker someone sent to the user's own collection.
     */
    public function saveFromMessage(User $user, Message $message): Sticker
    {
        $path = $this->attachments->path($message);

        if ($message->message_type !== Message::TYPE_STICKER || $path === null) {
            throw new RuntimeException('This sticker is no longer available.');
        }

        return $this->remember($user, (string) file_get_contents($path));
    }

    /**
     * Copy a sticker into a new message's attachment (messages never share files).
     *
     * @return array{attachment:string, attachment_name:string, attachment_mime:string, attachment_size:int, attachment_meta:array}
     */
    public function attachmentFor(Sticker $sticker): array
    {
        $disk = $this->attachments->disk();

        if (! $disk->exists($sticker->path)) {
            throw new RuntimeException('This sticker is no longer available.');
        }

        $path = now()->format('Y/m').'/'.Str::uuid()->toString().'.webp';
        $disk->copy($sticker->path, $path);
        $sticker->forceFill(['used_at' => now()])->save();

        return [
            'attachment' => $path,
            'attachment_name' => 'Sticker.webp',
            'attachment_mime' => 'image/webp',
            'attachment_size' => (int) $disk->size($path),
            'attachment_meta' => ['width' => Sticker::SIZE, 'height' => Sticker::SIZE],
        ];
    }

    public function delete(Sticker $sticker): void
    {
        $this->attachments->disk()->delete($sticker->path);
        $sticker->delete();
    }

    private function remember(User $user, string $binary): Sticker
    {
        $hash = sha1($binary);
        $existing = $user->stickers()->where('hash', $hash)->first();

        if ($existing) {
            $existing->forceFill(['used_at' => now()])->save();

            return $existing;
        }

        $path = "stickers/{$user->getKey()}/".Str::uuid()->toString().'.webp';
        $this->attachments->disk()->put($path, $binary);

        try {
            $sticker = $user->stickers()->create(['path' => $path, 'hash' => $hash, 'used_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            // Saved by a parallel request.
            $this->attachments->disk()->delete($path);

            return $user->stickers()->where('hash', $hash)->firstOrFail();
        }

        $this->prune($user);

        return $sticker;
    }

    private function prune(User $user): void
    {
        $user->stickers()
            ->orderByDesc('used_at')
            ->orderByDesc('id')
            ->skip(Sticker::MAX_PER_USER)
            ->take(PHP_INT_MAX)
            ->get()
            ->each(fn (Sticker $old) => $this->delete($old));
    }
}
