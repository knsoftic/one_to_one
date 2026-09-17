<?php

namespace App\Services;

use App\Models\AppSetting;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Admin → App settings → App name & icon.
 *
 * The name replaces APP_NAME everywhere on the server (pages, emails, the installed web app).
 * The icon is saved in every size the web needs (favicon, home-screen web app, notifications)
 * and the Android launcher sizes, which `php artisan app:android-brand` copies into the app
 * before an APK is built (a phone's home-screen name and icon come from the installed APK).
 */
class BrandService
{
    public const SETTING = 'brand';

    public const DEFAULT_COLOR = '#4338CA';

    public const NAME_MAX = 30;

    /** Web sizes (square PNGs, the logo filling the square). */
    public const WEB_SIZES = [32, 96, 180, 192, 512];

    /** Android launcher sizes per density: legacy icon (48dp) and adaptive foreground (108dp). */
    public const ANDROID_DENSITIES = ['mdpi' => 1, 'hdpi' => 1.5, 'xhdpi' => 2, 'xxhdpi' => 3, 'xxxhdpi' => 4];

    /** Share of an adaptive icon layer that is always visible (66dp safe zone of 108dp). */
    private const SAFE_ZONE = 66 / 108;

    private const DIR = 'brand';

    /** The name from .env, to fall back to (a singleton: read before the saved name is applied). */
    private readonly string $originalName;

    public function __construct()
    {
        $this->originalName = (string) config('app.name');
    }

    /**
     * @return array{name: ?string, color: string, icon: ?string, gd: bool}
     */
    public function settings(): array
    {
        $saved = AppSetting::get(self::SETTING);
        $saved = is_array($saved) ? $saved : [];

        return [
            'name' => filled($saved['name'] ?? null) ? (string) $saved['name'] : null,
            'color' => $this->validColor($saved['color'] ?? null) ?? self::DEFAULT_COLOR,
            'icon' => filled($saved['icon'] ?? null) ? (string) $saved['icon'] : null,
            'gd' => (bool) ($saved['gd'] ?? false),
        ];
    }

    /** Put the saved name into the configuration (each request and queued job). */
    public function apply(): void
    {
        $name = $this->settings()['name'] ?? $this->originalName;

        // Emails sent "from" the app follow the name, unless a different sender name was chosen.
        if (config('mail.from.name') === config('app.name') || config('mail.from.name') === $this->originalName) {
            config(['mail.from.name' => $name]);
        }
        config(['app.name' => $name]);
    }

    public function name(): string
    {
        return (string) config('app.name');
    }

    public function defaultName(): string
    {
        return $this->originalName;
    }

    public function hasIcon(): bool
    {
        return $this->settings()['icon'] !== null;
    }

    /**
     * Public URL of the icon in a size; the app's own icons when none was uploaded.
     *
     * @param  int|'maskable'|'favicon'  $size
     */
    public function iconUrl(int|string $size): string
    {
        $brand = $this->settings();
        if ($brand['icon'] === null) {
            return match ($size) {
                'favicon' => asset('favicon.svg'),
                'maskable' => asset('icons/maskable-512.png'),
                180 => asset('icons/apple-touch-icon.png'),
                512 => asset('icons/icon-512.png'),
                default => asset('icons/icon-192.png'),
            };
        }

        $file = match (true) {
            ! $brand['gd'] => $this->originalFile($brand['icon']),
            $size === 'favicon' => 'icon-32.png',
            $size === 'maskable' => 'maskable-512.png',
            in_array($size, self::WEB_SIZES, true) => "icon-{$size}.png",
            default => 'icon-192.png',
        };

        return Storage::disk('public')->url(self::DIR.'/'.$brand['icon'].'/'.$file);
    }

    public function faviconType(): string
    {
        return $this->hasIcon() ? 'image/png' : 'image/svg+xml';
    }

    /**
     * Save the name, colour and (optionally) a new icon or its removal.
     *
     * @param  array{name?: ?string, color?: ?string}  $values
     */
    public function update(array $values, ?UploadedFile $icon = null, bool $removeIcon = false): void
    {
        $brand = $this->settings();
        $name = trim((string) ($values['name'] ?? ''));
        $brand['name'] = $name === '' || $name === $this->defaultName() ? null : Str::limit($name, self::NAME_MAX, '');
        $brand['color'] = $this->validColor($values['color'] ?? null) ?? self::DEFAULT_COLOR;

        $previous = $brand['icon'];
        $colorChanged = $brand['color'] !== $this->settings()['color'];

        if ($icon !== null) {
            [$brand['icon'], $brand['gd']] = $this->storeIcon($icon, $brand['color']);
        } elseif ($removeIcon) {
            $brand['icon'] = null;
            $brand['gd'] = false;
        } elseif ($previous !== null && $brand['gd'] && $colorChanged) {
            // The background colour is part of the maskable and Android icons: make them again.
            $source = Storage::disk('public')->path(self::DIR.'/'.$previous.'/icon-512.png');
            [$brand['icon'], $brand['gd']] = $this->storeIcon(new UploadedFile($source, 'icon.png', 'image/png', null, true), $brand['color']);
        }

        AppSetting::put([self::SETTING => $brand]);

        if ($previous !== null && $previous !== $brand['icon']) {
            Storage::disk('public')->deleteDirectory(self::DIR.'/'.$previous);
        }

        $this->apply();
    }

    /**
     * What `php artisan app:android-brand --url=…` reads (public: it is on every page anyway).
     *
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        $brand = $this->settings();
        $android = null;
        if ($brand['icon'] !== null && $brand['gd']) {
            $base = self::DIR.'/'.$brand['icon'].'/';
            foreach (array_keys(self::ANDROID_DENSITIES) as $density) {
                foreach (['launcher', 'round', 'foreground', 'splash'] as $kind) {
                    $android[$density][$kind] = Storage::disk('public')->url($base."android-{$kind}-{$density}.png");
                }
            }
        }

        return [
            'name' => $this->name(),
            'color' => $brand['color'],
            'icon' => $brand['icon'] === null ? null : [
                'version' => $brand['icon'],
                'url' => $this->iconUrl(512),
                'android' => $android,
            ],
        ];
    }

    /** Local path of an Android icon file of the saved icon (for the build command). */
    public function androidIconPath(string $kind, string $density): ?string
    {
        $brand = $this->settings();
        if ($brand['icon'] === null || ! $brand['gd']) {
            return null;
        }
        $path = Storage::disk('public')->path(self::DIR.'/'.$brand['icon']."/android-{$kind}-{$density}.png");

        return is_file($path) ? $path : null;
    }

    /* ------------------------------------------------------------------ */
    /* Icon files */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: string, 1: bool} the icon's version folder, and whether all sizes were made
     */
    private function storeIcon(UploadedFile $file, string $color): array
    {
        $version = Str::lower(Str::random(12));
        $dir = self::DIR.'/'.$version;
        $disk = Storage::disk('public');

        if (! function_exists('imagecreatefromstring')) {
            // Without PHP's GD extension the picture is used as it is, in every place.
            $disk->putFileAs($dir, $file, 'original.'.($file->guessExtension() ?: 'png'));

            return [$version, false];
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if (! $source instanceof GdImage) {
            throw new RuntimeException('The picture could not be read.');
        }
        $square = $this->squared($source);
        imagedestroy($source);

        $disk->makeDirectory($dir);
        $path = fn (string $name) => $disk->path($dir.'/'.$name);

        foreach (self::WEB_SIZES as $size) {
            $this->savePng($this->resized($square, $size), $path("icon-{$size}.png"));
        }
        // A finished icon fills its square (its own background); a logo has see-through corners
        // and is placed on the background colour wherever the phone shapes the icon.
        $fills = $this->fillsSquare($square);

        // Installed web app on Android: the phone cuts it to a shape.
        $this->savePng($fills ? $this->resized($square, 512) : $this->padded($square, 512, 0.7, $color), $path('maskable-512.png'));

        foreach (self::ANDROID_DENSITIES as $density => $scale) {
            // Older phones: a square (and a round) 48dp icon.
            $launcher = (int) round(48 * $scale);
            $legacy = fn () => $fills ? $this->resized($square, $launcher) : $this->padded($square, $launcher, 0.8, $color);
            $this->savePng($legacy(), $path("android-launcher-{$density}.png"));
            $this->savePng($this->rounded($legacy()), $path("android-round-{$density}.png"));
            // Android 8+: a 108dp layer on the background colour; the phone shows the middle 72dp.
            $this->savePng($this->padded($square, (int) round(108 * $scale), $fills ? 72 / 108 : self::SAFE_ZONE * 0.9, null), $path("android-foreground-{$density}.png"));
            // Splash screen: 288dp, the phone shows a 192dp circle.
            $this->savePng($this->padded($square, (int) round(288 * $scale), $fills ? 192 / 288 : 0.55, null), $path("android-splash-{$density}.png"));
        }
        imagedestroy($square);

        return [$version, true];
    }

    private function originalFile(string $version): string
    {
        $files = Storage::disk('public')->files(self::DIR.'/'.$version);

        return basename($files[0] ?? 'original.png');
    }

    /** True when the four corners are (nearly) solid: a finished icon rather than a see-through logo. */
    private function fillsSquare(GdImage $square): bool
    {
        $last = imagesx($square) - 1;
        foreach ([[0, 0], [$last, 0], [0, $last], [$last, $last]] as [$x, $y]) {
            // GD alpha: 0 = solid, 127 = fully see-through.
            if (((imagecolorat($square, $x, $y) >> 24) & 0x7F) > 40) {
                return false;
            }
        }

        return true;
    }

    /** The picture cut to a centred square. */
    private function squared(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $side = min($width, $height);

        $square = $this->canvas($side);
        imagecopyresampled($square, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $side, $side, $side, $side);

        return $square;
    }

    private function resized(GdImage $square, int $size): GdImage
    {
        $image = $this->canvas($size);
        imagecopyresampled($image, $square, 0, 0, 0, 0, $size, $size, imagesx($square), imagesy($square));

        return $image;
    }

    /** The logo at a share of the size, centred on a colour (or transparent). */
    private function padded(GdImage $square, int $size, float $share, ?string $color): GdImage
    {
        $image = $this->canvas($size);
        if ($color !== null) {
            [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
            imagefill($image, 0, 0, imagecolorallocatealpha($image, $r, $g, $b, 0));
        }
        $inner = (int) round($size * $share);
        $offset = intdiv($size - $inner, 2);
        imagecopyresampled($image, $square, $offset, $offset, 0, 0, $inner, $inner, imagesx($square), imagesy($square));

        return $image;
    }

    /** Transparent outside the circle (Android's round launcher icon). */
    private function rounded(GdImage $image): GdImage
    {
        $size = imagesx($image);
        $radius = $size / 2;
        $clear = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagealphablending($image, false);
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                if ((($x + 0.5 - $radius) ** 2) + (($y + 0.5 - $radius) ** 2) > $radius ** 2) {
                    imagesetpixel($image, $x, $y, $clear);
                }
            }
        }
        imagesavealpha($image, true);

        return $image;
    }

    private function canvas(int $size): GdImage
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagesavealpha($image, true);
        imagealphablending($image, true);

        return $image;
    }

    private function savePng(GdImage $image, string $path): void
    {
        imagesavealpha($image, true);
        imagepng($image, $path, 9);
        imagedestroy($image);
    }

    private function validColor(mixed $color): ?string
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtoupper($color) : null;
    }
}
