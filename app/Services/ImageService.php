<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Image processing with GD.
 *
 * Re-encoding uploaded images strips metadata (EXIF / GPS) and neutralises
 * any non-image payload hidden inside the file.
 */
class ImageService
{
    /**
     * Crop, resize and store a square avatar on the public disk.
     *
     * @throws RuntimeException when the file cannot be decoded.
     */
    public function storeAvatar(UploadedFile $file, ?string $previousPath = null): string
    {
        $config = config('chat.uploads.avatar');
        $size = (int) $config['size'];

        $source = $this->load($file->getRealPath());
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);

        $canvas = $this->canvas($size, $size);
        imagecopyresampled(
            $canvas, $source,
            0, 0,
            intdiv($width - $side, 2), intdiv($height - $side, 2),
            $size, $size,
            $side, $side
        );

        $path = trim($config['directory'], '/').'/'.Str::uuid()->toString().'.webp';
        Storage::disk($config['disk'])->put($path, $this->encodeWebp($canvas, 85), 'public');

        unset($source);
        unset($canvas);

        if ($previousPath) {
            $this->deleteAvatar($previousPath);
        }

        return $path;
    }

    public function deleteAvatar(?string $path): void
    {
        if ($path) {
            Storage::disk(config('chat.uploads.avatar.disk'))->delete($path);
        }
    }

    /**
     * Build a resized WebP thumbnail. Returns null when the image cannot be
     * processed safely (the original is then used as a fallback).
     *
     * @return array{binary:string,width:int,height:int}|null
     */
    public function thumbnail(string $absolutePath, int $maxWidth): ?array
    {
        try {
            $source = $this->load($absolutePath);
        } catch (RuntimeException) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $targetWidth = min($maxWidth, $width);
        $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));

        $canvas = $this->canvas($targetWidth, $targetHeight);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $binary = $this->encodeWebp($canvas, 80);

        unset($source);
        unset($canvas);

        return ['binary' => $binary, 'width' => $targetWidth, 'height' => $targetHeight];
    }

    /**
     * Re-encode an image (drops metadata) and cap its longest edge.
     *
     * @param  'jpeg'|'png'  $format
     * @param  int  $quality  JPEG quality (0–100)
     * @return array{binary:string,width:int,height:int}
     *
     * @throws RuntimeException
     */
    public function reencode(string $absolutePath, int $maxEdge, string $format = 'jpeg', int $quality = 88): array
    {
        $source = $this->load($absolutePath);
        $width = imagesx($source);
        $height = imagesy($source);

        $scale = min(1, $maxEdge / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = $this->canvas($targetWidth, $targetHeight);

        if ($format === 'jpeg') {
            // JPEG has no alpha channel: flatten onto white.
            imagealphablending($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        }

        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        $format === 'png' ? imagepng($canvas, null, 6) : imagejpeg($canvas, null, max(0, min(100, $quality)));
        $binary = (string) ob_get_clean();

        unset($source, $canvas);

        return ['binary' => $binary, 'width' => $targetWidth, 'height' => $targetHeight];
    }

    /**
     * A square sticker: the image fitted inside size×size on a transparent
     * background, encoded as WebP (transparency kept).
     *
     * @throws RuntimeException
     */
    public function sticker(string $absolutePath, int $size): string
    {
        $source = $this->load($absolutePath);
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min($size / $width, $size / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = $this->canvas($size, $size);
        imagealphablending($canvas, true);
        imagecopyresampled(
            $canvas, $source,
            intdiv($size - $targetWidth, 2), intdiv($size - $targetHeight, 2),
            0, 0,
            $targetWidth, $targetHeight,
            $width, $height
        );
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $binary = $this->encodeWebp($canvas, 90);
        unset($source, $canvas);

        return $binary;
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public function dimensions(string $absolutePath): ?array
    {
        $info = @getimagesize($absolutePath);

        return $info ? [(int) $info[0], (int) $info[1]] : null;
    }

    private function load(string $path): GdImage
    {
        $info = @getimagesize($path);

        if (! $info || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException('The file is not a valid image.');
        }

        $this->ensureMemoryFor((int) $info[0], (int) $info[1]);

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        if (! $image instanceof GdImage) {
            throw new RuntimeException('The image could not be decoded.');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->applyExifOrientation($image, $path);
        }

        return $image;
    }

    private function canvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));

        return $canvas;
    }

    private function encodeWebp(GdImage $image, int $quality): string
    {
        ob_start();
        imagewebp($image, null, $quality);

        return (string) ob_get_clean();
    }

    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof GdImage) {
            unset($image);

            return $rotated;
        }

        return $image;
    }

    /**
     * Refuse to decode images whose bitmap would exhaust PHP's memory limit
     * (a fatal error cannot be caught).
     */
    private function ensureMemoryFor(int $width, int $height): void
    {
        $limit = $this->memoryLimitBytes();

        if ($limit < 0) {
            return;
        }

        // ~5 bytes per pixel for a true-colour bitmap plus a working canvas.
        $required = (int) ($width * $height * 5 * 1.7);
        $available = $limit - memory_get_usage(true);

        if ($required > $available) {
            throw new RuntimeException('The image dimensions are too large to process.');
        }
    }

    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
