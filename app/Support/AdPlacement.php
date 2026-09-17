<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Where an ad can appear in the app (Y1). The admin switches each placement on or off in
 * Admin → App settings → Ads, and every campaign says which of them it may run in.
 *
 * `container` / `item` are the CSS hooks the app uses to slot the card into that screen;
 * `format` decides how the card is drawn (a list row, or a full-width banner).
 */
final class AdPlacement
{
    public const ALL = [
        'chat_list' => [
            'label' => 'Chats list',
            'text' => 'A sponsored card between the chats.',
            'format' => 'row',
            'container' => '[data-conversation-list]',
            'item' => '.conversation-item',
        ],
        'status_list' => [
            'label' => 'Status updates',
            'text' => 'Between the status updates in the Status tab.',
            'format' => 'row',
            'container' => '[data-status-body]',
            'item' => '.status-row',
        ],
        'channels' => [
            'label' => 'Channels',
            'text' => 'Between the channels people follow.',
            'format' => 'row',
            'container' => '[data-status-body]',
            'item' => '.channel-row',
        ],
        'calls' => [
            'label' => 'Calls',
            'text' => 'Between the entries in the call history.',
            'format' => 'row',
            'container' => '[data-calls-list]',
            'item' => '.calls-entry',
        ],
        'chat_top' => [
            'label' => 'Inside a chat',
            'text' => 'A single banner at the top of an open conversation.',
            'format' => 'banner',
            'container' => '[data-message-list]',
            'item' => null,
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::ALL);
    }

    public static function label(string $key): string
    {
        return self::ALL[$key]['label'] ?? $key;
    }

    public static function format(string $key): string
    {
        return self::ALL[$key]['format'] ?? 'row';
    }

    /**
     * Placements the admin has switched on. Unset means every placement is on.
     *
     * @return list<string>
     */
    public static function enabled(): array
    {
        $saved = AppSetting::get('ad_placements');

        if (! is_array($saved)) {
            return self::keys();
        }

        return array_values(array_intersect(self::keys(), $saved));
    }

    public static function isEnabled(string $key): bool
    {
        return in_array($key, self::enabled(), true);
    }

    /**
     * The switched-on placements as the app needs them: key, format and where to slot the card.
     *
     * @return array<string, array{format: string, container: string, item: ?string}>
     */
    public static function forApp(): array
    {
        $out = [];
        foreach (self::enabled() as $key) {
            $out[$key] = [
                'format' => self::ALL[$key]['format'],
                'container' => self::ALL[$key]['container'],
                'item' => self::ALL[$key]['item'],
            ];
        }

        return $out;
    }
}
