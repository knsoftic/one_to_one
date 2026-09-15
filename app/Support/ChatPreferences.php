<?php

namespace App\Support;

use App\Models\ChatSetting;
use App\Models\User;

/**
 * Phase 8 — choices for how chats look and alert: wallpaper (D2), font size (D3),
 * notification tone and vibration (D4) and auto-download (D5).
 */
final class ChatPreferences
{
    /** The app's own dotted background (stored as null). */
    public const WALLPAPER_DEFAULT = 'default';

    /** A photo the person uploaded. */
    public const WALLPAPER_CUSTOM = 'custom';

    /** Colours and gradients (drawn by CSS, light and dark variants). */
    public const WALLPAPER_PRESETS = ['plain', 'lavender', 'mint', 'sky', 'peach', 'rose', 'sand', 'slate', 'aurora', 'sunset', 'ocean', 'forest', 'midnight'];

    /** Dark theme dimming for wallpapers, in percent. */
    public const MAX_WALLPAPER_DIM = 80;

    public const FONT_SIZES = ['small', 'medium', 'large'];

    /**
     * Notification sounds. "default" is the chime on the web and the phone's own sound on Android;
     * the others are generated with Web Audio on the web and play from res/raw/tone_*.wav on Android.
     */
    public const TONES = ['default', 'chime', 'bell', 'pop', 'chirp', 'marimba', 'pulse', 'glass'];

    /** A chat can also be silent. */
    public const TONE_NONE = 'none';

    public const VIBRATIONS = ['default', 'short', 'long', 'off'];

    /**
     * What auto-download covers: photos, GIFs and stickers, and video previews. Voice messages
     * and documents are only ever fetched when someone plays or opens them.
     */
    public const DOWNLOAD_KINDS = ['photos', 'gifs', 'videos'];

    public const DOWNLOAD_NETWORKS = ['wifi', 'mobile'];

    /** Everything on Wi-Fi, photos and GIFs on mobile data. */
    public const DEFAULT_AUTO_DOWNLOAD = [
        'wifi' => ['photos', 'gifs', 'videos'],
        'mobile' => ['photos', 'gifs'],
    ];

    /**
     * D4 — the sound and vibration for a new message: the chat's own choice, else the default
     * from Settings ("none" when notification sounds are off).
     *
     * @return array{tone: string, vibrate: string}
     */
    public static function alertFor(User $user, ?ChatSetting $setting = null): array
    {
        $tone = $setting?->notification_tone;
        if (! in_array($tone, [...self::TONES, self::TONE_NONE], true)) {
            $tone = $user->notification_sound ? $user->notification_tone : self::TONE_NONE;
        }

        $vibrate = $setting?->notification_vibrate;
        if (! in_array($vibrate, self::VIBRATIONS, true)) {
            $vibrate = $user->notification_vibrate;
        }

        return [
            'tone' => in_array($tone, [...self::TONES, self::TONE_NONE], true) ? $tone : 'default',
            'vibrate' => in_array($vibrate, self::VIBRATIONS, true) ? $vibrate : 'default',
        ];
    }

    /** @return list<string> */
    public static function wallpapers(): array
    {
        return [self::WALLPAPER_DEFAULT, ...self::WALLPAPER_PRESETS, self::WALLPAPER_CUSTOM];
    }

    /**
     * Saved auto-download choices with unknown values dropped and missing networks filled in.
     *
     * @return array{wifi: list<string>, mobile: list<string>}
     */
    public static function autoDownload(?array $saved): array
    {
        $result = [];
        foreach (self::DOWNLOAD_NETWORKS as $network) {
            $kinds = is_array($saved) && array_key_exists($network, $saved) && is_array($saved[$network])
                ? $saved[$network]
                : self::DEFAULT_AUTO_DOWNLOAD[$network];
            $result[$network] = array_values(array_intersect(self::DOWNLOAD_KINDS, $kinds));
        }

        return $result;
    }
}
