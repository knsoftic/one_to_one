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
    public function __construct(private readonly ImageService $images) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config('chat.uploads.disk'));
    }

    /**
     * @return array{attachment:string, attachment_name:string, attachment_mime:string, attachment_size:int, attachment_meta:array}
     */
    public function store(UploadedFile $file, string $type, array $meta = [], ?UploadedFile $thumbnail = null, bool $hd = false): array
    {
        $directory = now()->format('Y/m');
        $basename = Str::uuid()->toString();
        $mime = (string) $file->getMimeType();
        $extension = $this->extensionFor($file, $type);

        $path = "{$directory}/{$basename}.{$extension}";
        $attributes = [];

        if ($type === Message::TYPE_IMAGE) {
            [$path, $mime, $attributes] = $this->storeImage($file, $directory, $basename, $extension, $mime, $hd);
        } else {
            $this->disk()->putFileAs($directory, $file, "{$basename}.{$extension}");
        }

        if ($type === Message::TYPE_VIDEO) {
            $mime = $this->videoMime($mime, $extension);

            // The poster frame is re-encoded like any image; its size gives the video's shape.
            $poster = $thumbnail ? $this->images->thumbnail((string) $thumbnail->getRealPath(), (int) config('chat.uploads.image.thumbnail_width')) : null;
            if ($poster) {
                $thumbPath = "{$directory}/{$basename}_thumb.webp";
                $this->disk()->put($thumbPath, $poster['binary']);
                $attributes = ['width' => $poster['width'], 'height' => $poster['height'], 'thumbnail' => $thumbPath];
            }
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
     * Copy a message's files for a new message (forwarding), so deleting one
     * message never removes the other's attachment.
     *
     * @return array{attachment:string, attachment_name:?string, attachment_mime:?string, attachment_size:?int, attachment_meta:array}
     *
     * @throws RuntimeException when the original file is missing
     */
    public function duplicate(Message $message): array
    {
        if (! $message->attachment || ! $this->disk()->exists($message->attachment)) {
            throw new RuntimeException('The file of this message is no longer available.');
        }

        // A forwarded photo stands on its own, outside the original album.
        $meta = array_diff_key($message->attachment_meta ?? [], ['album' => true, 'mentions' => true, 'mention_ids' => true]);
        $directory = now()->format('Y/m');
        $basename = Str::uuid()->toString();
        $extension = pathinfo($message->attachment, PATHINFO_EXTENSION);

        $path = "{$directory}/{$basename}".($extension !== '' ? ".{$extension}" : '');
        $this->disk()->copy($message->attachment, $path);

        if (! empty($meta['thumbnail']) && $this->disk()->exists($meta['thumbnail'])) {
            $thumbnail = "{$directory}/{$basename}_thumb.webp";
            $this->disk()->copy($meta['thumbnail'], $thumbnail);
            $meta['thumbnail'] = $thumbnail;
        } else {
            unset($meta['thumbnail']);
        }

        return [
            'attachment' => $path,
            'attachment_name' => $message->attachment_name,
            'attachment_mime' => $message->attachment_mime,
            'attachment_size' => $message->attachment_size,
            'attachment_meta' => $meta,
        ];
    }

    /**
     * Store a downloaded GIF (e.g. from GIF search) after checking it really is a GIF.
     *
     * @return array{attachment:string, attachment_name:string, attachment_mime:string, attachment_size:int, attachment_meta:array}
     *
     * @throws RuntimeException
     */
    public function storeGif(string $binary): array
    {
        $info = @getimagesizefromstring($binary);
        $max = (int) config('chat.uploads.image.max_dimension');

        if (! $info || $info[2] !== IMAGETYPE_GIF || $info[0] < 1 || $info[1] < 1 || $info[0] > $max || $info[1] > $max) {
            throw new RuntimeException('This GIF could not be sent.');
        }

        $path = now()->format('Y/m').'/'.Str::uuid()->toString().'.gif';
        $this->disk()->put($path, $binary);

        return [
            'attachment' => $path,
            'attachment_name' => 'GIF.gif',
            'attachment_mime' => 'image/gif',
            'attachment_size' => strlen($binary),
            'attachment_meta' => ['width' => (int) $info[0], 'height' => (int) $info[1], 'animated' => true],
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
    private function storeImage(UploadedFile $file, string $directory, string $basename, string $extension, string $mime, bool $hd = false): array
    {
        $source = $file->getRealPath();
        $path = "{$directory}/{$basename}.{$extension}";

        // Animated GIFs are kept as they are (re-encoding would drop the animation).
        if ($extension === 'gif') {
            $this->disk()->putFileAs($directory, $file, "{$basename}.gif");
            [$width, $height] = $this->images->dimensions($source) ?? [null, null];

            return [$path, 'image/gif', ['width' => $width, 'height' => $height, 'animated' => true]];
        }

        $maxEdge = (int) config($hd ? 'chat.uploads.image.hd_max_edge' : 'chat.uploads.image.max_edge', 1600);

        // Re-encode (removes metadata, caps resolution); fall back to the validated original.
        try {
            $encoded = $this->images->reencode($source, $maxEdge, $extension === 'png' ? 'png' : 'jpeg', $hd ? 90 : 82);
            $this->disk()->put($path, $encoded['binary']);
            [$width, $height] = [$encoded['width'], $encoded['height']];
            $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';
        } catch (RuntimeException) {
            $this->disk()->putFileAs($directory, $file, "{$basename}.{$extension}");
            [$width, $height] = $this->images->dimensions($source) ?? [null, null];
        }

        $attributes = ['width' => $width, 'height' => $height, 'hd' => $hd ?: null];

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
            Message::TYPE_IMAGE => match ($guessed) {
                'jpg', 'jpeg' => 'jpg',
                'gif' => 'gif',
                default => 'png',
            },
            Message::TYPE_DOCUMENT => in_array($client, config('chat.uploads.document.extensions'), true) ? $client : ($guessed ?: 'bin'),
            Message::TYPE_VIDEO => in_array($client, config('chat.uploads.video.extensions'), true)
                ? $client
                : (in_array($guessed, config('chat.uploads.video.extensions'), true) ? $guessed : 'mp4'),
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

        $fallback = match ($type) {
            Message::TYPE_IMAGE => 'Photo',
            Message::TYPE_VIDEO => 'Video',
            default => 'Document',
        };

        return ($name !== '' ? $name : $fallback).'.'.$extension;
    }

    /**
     * A content type browsers can play (libmagic reports some phone videos generically).
     */
    private function videoMime(string $detected, string $extension): string
    {
        if (str_starts_with($detected, 'video/')) {
            return $detected;
        }

        return match ($extension) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            '3gp' => 'video/3gpp',
            default => 'video/mp4',
        };
    }
}
