<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stores chat attachments on the private "chat" disk.
 *
 * - Random, server-generated file names (never the client's name or path).
 * - Extension derived from the detected content type, not from the client.
 * - Images are re-encoded (strips EXIF/GPS metadata) and get a WebP thumbnail.
 */
class AttachmentService
{
    private const IMAGE_MAX_EDGE = 2560;

    public function __construct(private readonly ImageService $images) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config('chat.uploads.disk'));
    }

    /**
     * @return array{attachment:string, attachment_name:string, attachment_mime:string, attachment_size:int, attachment_meta:array}
     */
    public function store(UploadedFile $file, string $type, array $meta = []): array
    {
        $directory = now()->format('Y/m');
        $basename = Str::uuid()->toString();
        $mime = (string) $file->getMimeType();
        $extension = $this->extensionFor($file, $type);

        $path = "{$directory}/{$basename}.{$extension}";
        $attributes = [];

        if ($type === Message::TYPE_IMAGE) {
            [$path, $mime, $attributes] = $this->storeImage($file, $directory, $basename, $extension, $mime);
        } else {
            $this->disk()->putFileAs($directory, $file, "{$basename}.{$extension}");
        }

        return [
            'attachment' => $path,
            'attachment_name' => $this->safeName($file, $extension, $type),
            'attachment_mime' => $mime,
            'attachment_size' => (int) $this->disk()->size($path),
            'attachment_meta' => array_filter($attributes + $meta, fn ($value) => $value !== null),
        ];
    }

    /**
     * Remove the stored file and its thumbnail.
     */
    public function delete(Message $message): void
    {
        $paths = array_filter([
            $message->attachment,
            $message->attachment_meta['thumbnail'] ?? null,
        ]);

        if ($paths) {
            $this->disk()->delete($paths);
        }
    }

    /**
     * Absolute path of an attachment variant, or null when missing.
     */
    public function path(Message $message, string $variant = 'original'): ?string
    {
        $relative = $variant === 'thumbnail'
            ? ($message->attachment_meta['thumbnail'] ?? null)
            : $message->attachment;

        if (! $relative || ! $this->disk()->exists($relative)) {
            return null;
        }

        return $this->disk()->path($relative);
    }

    /**
     * @return array{0:string, 1:string, 2:array}
     */
    private function storeImage(UploadedFile $file, string $directory, string $basename, string $extension, string $mime): array
    {
        $source = $file->getRealPath();
        $path = "{$directory}/{$basename}.{$extension}";

        // Re-encode (removes metadata, caps resolution); fall back to the validated original.
        try {
            $encoded = $this->images->reencode($source, self::IMAGE_MAX_EDGE, $extension === 'png' ? 'png' : 'jpeg');
            $this->disk()->put($path, $encoded['binary']);
            [$width, $height] = [$encoded['width'], $encoded['height']];
            $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';
        } catch (RuntimeException) {
            $this->disk()->putFileAs($directory, $file, "{$basename}.{$extension}");
            [$width, $height] = $this->images->dimensions($source) ?? [null, null];
        }

        $attributes = ['width' => $width, 'height' => $height];

        $thumbnail = $this->images->thumbnail($this->disk()->path($path), (int) config('chat.uploads.image.thumbnail_width'));
        if ($thumbnail) {
            $thumbPath = "{$directory}/{$basename}_thumb.webp";
            $this->disk()->put($thumbPath, $thumbnail['binary']);
            $attributes['thumbnail'] = $thumbPath;
        }

        return [$path, $mime, $attributes];
    }

    private function extensionFor(UploadedFile $file, string $type): string
    {
        $guessed = strtolower((string) $file->guessExtension());
        $client = strtolower($file->getClientOriginalExtension());

        return match ($type) {
            Message::TYPE_IMAGE => in_array($guessed, ['jpg', 'jpeg'], true) ? 'jpg' : 'png',
            Message::TYPE_DOCUMENT => in_array($client, config('chat.uploads.document.extensions'), true) ? $client : ($guessed ?: 'bin'),
            Message::TYPE_VOICE => match (true) {
                str_contains((string) $file->getMimeType(), 'ogg') => 'ogg',
                str_contains((string) $file->getMimeType(), 'mpeg') => 'mp3',
                str_contains((string) $file->getMimeType(), 'mp4') => 'm4a',
                str_contains((string) $file->getMimeType(), 'wav') => 'wav',
                default => 'webm',
            },
            default => 'bin',
        };
    }

    /**
     * A display-only file name: no path, no control characters, bounded length.
     */
    private function safeName(UploadedFile $file, string $extension, string $type): string
    {
        if ($type === Message::TYPE_VOICE) {
            return 'Voice message.'.$extension;
        }

        $name = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $name = preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}\/\\\\:*?"<>|]+/u', '', (string) $name) ?? '';
        $name = trim(Str::limit(trim($name), 120, ''));

        return ($name !== '' ? $name : ($type === Message::TYPE_IMAGE ? 'Photo' : 'Document')).'.'.$extension;
    }
}
