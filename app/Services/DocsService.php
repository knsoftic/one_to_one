<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The guides in docs/ shown live in the admin panel: an index of guides, and each guide
 * with its table of contents. Only the files listed here are ever read.
 */
class DocsService
{
    /** @var array<string, array{file: string, title: string, text: string, icon: string}> */
    public const DOCS = [
        'play-store' => [
            'file' => 'docs/PLAY-STORE.md',
            'title' => 'Google Play release guide',
            'text' => 'Developer account, upload key, release bundle, store listing, app content, data safety and every update.',
            'icon' => 'store',
        ],
        'deployment' => [
            'file' => 'docs/DEPLOYMENT-AAPANEL.md',
            'title' => 'Server deployment (aaPanel)',
            'text' => 'Installing and updating the app on the server: PHP, database, queue, Reverb, SSL and the deploy script.',
            'icon' => 'server',
        ],
        'roadmap' => [
            'file' => 'docs/FEATURE-ROADMAP.md',
            'title' => 'Feature roadmap',
            'text' => 'Every phase and feature, and which ones are done.',
            'icon' => 'route',
        ],
    ];

    /**
     * The index: every guide with its sections and last change.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return collect(self::DOCS)
            ->filter(fn ($doc) => is_file(base_path($doc['file'])))
            ->map(function ($doc, $slug) {
                $page = $this->page($slug);

                return [
                    'slug' => $slug,
                    'title' => $doc['title'],
                    'text' => $doc['text'],
                    'icon' => $doc['icon'],
                    'sections' => collect($page['toc'])->where('level', 2)->values()->all(),
                    'minutes' => $page['minutes'],
                    'updated_at' => $page['updated_at'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One guide as HTML, with heading anchors and its table of contents.
     *
     * @return array{slug: string, title: string, file: string, html: string, toc: list<array{level: int, text: string, id: string}>, minutes: int, updated_at: Carbon}|null
     */
    public function page(string $slug): ?array
    {
        $doc = self::DOCS[$slug] ?? null;
        $path = $doc ? base_path($doc['file']) : null;
        if (! $path || ! is_file($path)) {
            return null;
        }

        $modified = (int) filemtime($path);
        $rendered = Cache::remember("docs:{$slug}:{$modified}:".filesize($path), now()->addDay(), fn () => $this->render((string) file_get_contents($path)));

        return $rendered + [
            'slug' => $slug,
            'title' => $doc['title'],
            'file' => $doc['file'],
            'updated_at' => Carbon::createFromTimestamp($modified),
        ];
    }

    /**
     * @return array{html: string, toc: list<array{level: int, text: string, id: string}>, minutes: int}
     */
    public function render(string $markdown): array
    {
        // Raw HTML in the file is shown as text, and javascript: links are dropped.
        $html = (string) Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        // The page heading already shows the guide's name.
        $html = (string) preg_replace('#^\s*<h1>.*?</h1>\s*#s', '', $html, 1);

        $toc = [];
        $used = [];
        $html = (string) preg_replace_callback('#<h([1-4])>(.*?)</h\1>#s', function (array $match) use (&$toc, &$used) {
            $level = (int) $match[1];
            $text = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5));
            $base = Str::slug($text) ?: 'section';
            $id = $base;
            for ($i = 2; isset($used[$id]); $i++) {
                $id = "{$base}-{$i}";
            }
            $used[$id] = true;
            if ($level >= 2) {
                $toc[] = ['level' => $level, 'text' => $text, 'id' => $id];
            }

            return sprintf('<h%1$d id="%2$s">%3$s <a class="doc-anchor" href="#%2$s" aria-label="Link to this section">#</a></h%1$d>', $level, e($id), $match[2]);
        }, $html);

        // Wide tables scroll on their own instead of pushing the page sideways.
        $html = str_replace(['<table>', '</table>'], ['<div class="doc-table"><table>', '</table></div>'], $html);
        // Links to other sites open in a new tab.
        $html = (string) preg_replace('#<a href="(https?://[^"]+)"#', '<a href="$1" target="_blank" rel="noopener noreferrer"', $html);

        $words = str_word_count(strip_tags($html));

        return ['html' => $html, 'toc' => $toc, 'minutes' => max(1, (int) ceil($words / 200))];
    }
}
