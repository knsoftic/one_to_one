<?php

namespace App\Services;

use App\Models\LinkPreview;
use App\Support\SafeFetcher;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Link previews like WhatsApp: title, description, site name and a small image
 * of the first link in a message. The server fetches the page (the other
 * person's device never contacts the site) and caches it per link.
 */
class LinkPreviewService
{
    /** A page's <head> is almost always inside the first few hundred KB. */
    private const MAX_HTML_BYTES = 512 * 1024;

    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    private const IMAGE_WIDTH = 480;

    /** Same rule as the browser's link detection in formatting.js. */
    private const URL_PATTERN = '~\bhttps?://[^\s<>"\'`]+[^\s<>"\'`.,:;!?)\]]~i';

    public function __construct(
        private readonly SafeFetcher $fetcher,
        private readonly ImageService $images,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('chat.link_previews.enabled', true);
    }

    /**
     * The first link of a text, ignoring links inside `code`.
     */
    /**
     * Every web link in a text, in order, without repeats (D1 — Links in the media gallery).
     *
     * @return list<string>
     */
    public function urls(?string $text): array
    {
        if (! $text) {
            return [];
        }

        $text = preg_replace(['/```[\s\S]*?```/u', '/`[^`\n]*`/u'], ' ', $text) ?? $text;
        preg_match_all(self::URL_PATTERN, $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    public function firstUrl(?string $text): ?string
    {
        if (! $text) {
            return null;
        }

        $text = preg_replace(['/```[\s\S]*?```/u', '/`[^`\n]*`/u'], ' ', $text) ?? $text;

        return preg_match(self::URL_PATTERN, $text, $match) ? $this->fetcher->normalize($match[0]) : null;
    }

    /**
     * A cached, still fresh preview without contacting the site.
     */
    public function cached(?string $url): ?LinkPreview
    {
        if ($url === null) {
            return null;
        }

        $preview = LinkPreview::query()->where('url_hash', LinkPreview::hashUrl($url))->first();

        return $preview?->isFresh() && $preview->isUsable() ? $preview : null;
    }

    /**
     * Whether the link still has to be fetched (nothing cached, or the cache is old).
     */
    public function needsFetch(string $url): bool
    {
        $preview = LinkPreview::query()->where('url_hash', LinkPreview::hashUrl($url))->first();

        return ! $preview?->isFresh();
    }

    /**
     * The preview of a link, fetching the page when the cache has none.
     * Returns null for pages without a title or description and for links
     * that may not be fetched.
     */
    public function preview(string $url): ?LinkPreview
    {
        $url = $this->fetcher->normalize($url);

        if ($url === null || ! $this->enabled()) {
            return null;
        }

        $existing = LinkPreview::query()->where('url_hash', LinkPreview::hashUrl($url))->first();

        if ($existing?->isFresh()) {
            return $existing->isUsable() ? $existing : null;
        }

        $data = $this->fetchPage($url);
        $preview = $this->save($url, $data, $existing);

        return $preview->isUsable() ? $preview : null;
    }

    /**
     * @return array{title:?string, description:?string, site_name:?string, image:?string, image_width:?int, image_height:?int}|null
     */
    private function fetchPage(string $url): ?array
    {
        try {
            $page = $this->fetcher->get($url, ['text/html', 'application/xhtml+xml'], self::MAX_HTML_BYTES, true);
        } catch (Throwable) {
            return null;
        }

        if ($page === null) {
            return null;
        }

        $meta = $this->parse($page['body'], $page['content_type']);

        if ($meta['title'] === null && $meta['description'] === null) {
            return null;
        }

        $image = $meta['image'] ? $this->storeImage($this->fetcher->absoluteUrl($page['url'], $meta['image'])) : null;

        return [
            'title' => $meta['title'],
            'description' => $meta['description'],
            'site_name' => $meta['site_name'],
            'image' => $image['path'] ?? null,
            'image_width' => $image['width'] ?? null,
            'image_height' => $image['height'] ?? null,
        ];
    }

    /**
     * Open Graph / Twitter card / plain HTML metadata.
     *
     * @return array{title:?string, description:?string, site_name:?string, image:?string}
     */
    public function parse(string $html, string $contentType = ''): array
    {
        // Only the <head> matters.
        if (($end = stripos($html, '</head>')) !== false) {
            $html = substr($html, 0, $end + 7);
        }

        $charset = preg_match('/charset=["\']?([\w-]+)/i', $contentType, $m) || preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', $html, $m)
            ? strtoupper($m[1])
            : 'UTF-8';

        if ($charset !== 'UTF-8' && in_array($charset, array_map('strtoupper', mb_list_encodings()), true)) {
            $html = mb_convert_encoding($html, 'UTF-8', $charset);
        }

        $html = mb_scrub($html, 'UTF-8');

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $meta = [];

        foreach ($xpath->query('//meta[@content]') ?: [] as $node) {
            $key = strtolower(trim($node->getAttribute('property') ?: $node->getAttribute('name') ?: $node->getAttribute('itemprop')));

            if ($key !== '' && ! isset($meta[$key])) {
                $meta[$key] = $node->getAttribute('content');
            }
        }

        $titleNode = $xpath->query('//title')?->item(0);
        $imageLink = $xpath->query('//link[@rel="image_src"][@href]')?->item(0);

        $pick = fn (array $keys) => collect($keys)->map(fn ($key) => $meta[$key] ?? null)->first(fn ($value) => $this->clean($value) !== null);

        return [
            'title' => $this->clean($pick(['og:title', 'twitter:title']) ?? $titleNode?->textContent, 300),
            'description' => $this->clean($pick(['og:description', 'twitter:description', 'description']), 500),
            'site_name' => $this->clean($pick(['og:site_name', 'application-name']), 120),
            'image' => $this->clean($pick(['og:image:secure_url', 'og:image', 'og:image:url', 'twitter:image', 'twitter:image:src', 'image']) ?? $imageLink?->getAttribute('href'), 2048),
        ];
    }

    /**
     * Download the page's image and keep a small WebP copy (re-encoded, so no
     * foreign file is ever served from this site).
     *
     * @return array{path:string, width:int, height:int}|null
     */
    private function storeImage(string $url): ?array
    {
        try {
            $image = $this->fetcher->get($url, ['image/jpeg', 'image/png', 'image/webp'], self::MAX_IMAGE_BYTES, false);
        } catch (Throwable) {
            return null;
        }

        if ($image === null) {
            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'lp');

        try {
            file_put_contents($temporary, $image['body']);
            $thumbnail = $this->images->thumbnail($temporary, self::IMAGE_WIDTH);
        } catch (RuntimeException) {
            $thumbnail = null;
        } finally {
            @unlink($temporary);
        }

        // Skip tracking pixels and icons too small to show.
        if (! $thumbnail || $thumbnail['width'] < 64 || $thumbnail['height'] < 64) {
            return null;
        }

        $path = 'link-previews/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.webp';
        Storage::disk(config('chat.uploads.disk'))->put($path, $thumbnail['binary']);

        return ['path' => $path, 'width' => $thumbnail['width'], 'height' => $thumbnail['height']];
    }

    /**
     * @param  array<string, mixed>|null  $data  null = the page could not be previewed
     */
    private function save(string $url, ?array $data, ?LinkPreview $existing): LinkPreview
    {
        $attributes = ($data ?? [
            'title' => null, 'description' => null, 'site_name' => null,
            'image' => null, 'image_width' => null, 'image_height' => null,
        ]) + ['url' => $url, 'failed' => $data === null, 'fetched_at' => now()];

        // A failed refresh keeps the last good preview (the site may be down briefly).
        if ($data === null && $existing?->isUsable()) {
            $existing->forceFill(['fetched_at' => now()])->save();

            return $existing;
        }

        $oldImage = $existing?->image;

        try {
            $preview = LinkPreview::query()->updateOrCreate(['url_hash' => LinkPreview::hashUrl($url)], $attributes);
        } catch (UniqueConstraintViolationException) {
            // Fetched by a parallel request at the same moment.
            $preview = LinkPreview::query()->where('url_hash', LinkPreview::hashUrl($url))->firstOrFail();
            $preview->update($attributes);
        }

        if ($oldImage && $oldImage !== $preview->image) {
            Storage::disk(config('chat.uploads.disk'))->delete($oldImage);
        }

        return $preview;
    }

    private function clean(?string $value, int $limit = 300): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $value) ?? '';
        $value = trim((string) Str::of($value)->squish());

        return $value === '' ? null : Str::limit($value, $limit - 3, '...');
    }
}
