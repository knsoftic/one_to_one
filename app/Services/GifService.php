<?php

namespace App\Services;

use App\Support\SafeFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * GIF search through Tenor (optional: needs CHAT_TENOR_KEY). The key stays on
 * the server; a chosen GIF is downloaded by the server and stored like any
 * other attachment, so the other person never loads it from Tenor.
 */
class GifService
{
    public const API = 'https://tenor.googleapis.com/v2/';

    public const MEDIA_HOST = 'media.tenor.com';

    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly AttachmentService $attachments,
    ) {}

    public function enabled(): bool
    {
        return filled(config('chat.gifs.tenor_key'));
    }

    /**
     * Trending GIFs, or results for a search.
     *
     * @return array{results: list<array{id:string, title:string, preview_url:string, width:?int, height:?int}>, next: ?string}
     */
    public function search(?string $query, ?string $position = null): array
    {
        $response = Http::timeout(6)->acceptJson()->get(self::API.(filled($query) ? 'search' : 'featured'), array_filter([
            'q' => $query,
            'pos' => $position,
            'key' => config('chat.gifs.tenor_key'),
            'client_key' => 'one2one-chat',
            'limit' => 24,
            'media_filter' => 'tinygif,gif',
            'contentfilter' => config('chat.gifs.content_filter', 'medium'),
        ]));

        if ($response->failed()) {
            throw new RuntimeException('GIF search is not available right now.');
        }

        return [
            'results' => collect($response->json('results', []))
                ->map(fn ($result) => $this->present(is_array($result) ? $result : []))
                ->filter()
                ->values()
                ->all(),
            'next' => filled($response->json('next')) ? (string) $response->json('next') : null,
        ];
    }

    /**
     * Download a GIF by its Tenor id and store it as a message attachment.
     *
     * @return array{attachment:string, attachment_name:string, attachment_mime:string, attachment_size:int, attachment_meta:array}
     *
     * @throws RuntimeException
     */
    public function download(string $id): array
    {
        $response = Http::timeout(6)->acceptJson()->get(self::API.'posts', [
            'ids' => $id,
            'key' => config('chat.gifs.tenor_key'),
            'client_key' => 'one2one-chat',
            'media_filter' => 'gif',
        ]);

        $url = $response->successful() ? (string) $response->json('results.0.media_formats.gif.url') : '';

        // Only Tenor's own media host, fetched with the same private-network protection as link previews.
        if ($url === '' || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== self::MEDIA_HOST) {
            throw new RuntimeException('This GIF is no longer available.');
        }

        $file = $this->fetcher->get($url, ['image/gif'], (int) config('chat.gifs.max_kb', 8192) * 1024);

        if ($file === null) {
            throw new RuntimeException('This GIF could not be downloaded.');
        }

        return $this->attachments->storeGif($file['body']);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function present(array $result): ?array
    {
        $preview = $result['media_formats']['tinygif'] ?? null;
        $url = (string) ($preview['url'] ?? '');

        if (empty($result['id']) || parse_url($url, PHP_URL_HOST) !== self::MEDIA_HOST || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return null;
        }

        return [
            'id' => (string) $result['id'],
            'title' => Str::limit((string) ($result['content_description'] ?? 'GIF'), 80),
            'preview_url' => $url,
            'width' => isset($preview['dims'][0]) ? (int) $preview['dims'][0] : null,
            'height' => isset($preview['dims'][1]) ? (int) $preview['dims'][1] : null,
        ];
    }
}
