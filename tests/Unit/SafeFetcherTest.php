<?php

namespace Tests\Unit;

use App\Support\SafeFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeWeb;

/**
 * M11 — link previews never let users reach the server's private network.
 */
class SafeFetcherTest extends TestCase
{
    public function test_links_are_normalised_and_unsafe_forms_refused(): void
    {
        $web = new FakeWeb;

        $this->assertSame('https://example.com/a/b?q=1', $web->normalize('HTTPS://Example.COM/a/b?q=1#top'));
        $this->assertSame('http://example.com/', $web->normalize('http://example.com'));
        $this->assertSame('http://93.184.216.34/', $web->normalize('http://93.184.216.34'));

        foreach ([
            'ftp://example.com/file', 'file:///etc/passwd', 'javascript:alert(1)', 'gopher://example.com',
            'http://user:secret@example.com/', 'http://example.com:8080/', 'http://example.com:22/',
            'http://localhost/', 'http://api.localhost/', 'http://printer.local/', 'http://intranet/',
            'http://metadata.google.internal/', 'http://127.1/', 'http://0x7f.0.0.1/', 'http://2130706433/',
            "http://example.com/a\nb", 'http://exa mple.com/',
        ] as $url) {
            $this->assertNull($web->normalize($url), $url);
        }
    }

    #[DataProvider('addresses')]
    public function test_only_public_addresses_are_allowed(string $ip, bool $public): void
    {
        $this->assertSame($public, SafeFetcher::isPublicIp($ip), $ip);
    }

    public static function addresses(): array
    {
        return [
            ['8.8.8.8', true], ['93.184.216.34', true], ['2606:4700:4700::1111', true],
            ['127.0.0.1', false], ['10.1.2.3', false], ['172.16.5.4', false], ['192.168.1.10', false],
            ['169.254.169.254', false], ['100.64.0.1', false], ['0.0.0.0', false], ['224.0.0.1', false],
            ['198.18.0.1', false], ['255.255.255.255', false],
            ['::1', false], ['::', false], ['fc00::1', false], ['fd12:3456::1', false], ['fe80::1', false],
            ['ff02::1', false], ['::ffff:127.0.0.1', false], ['::ffff:10.0.0.1', false], ['64:ff9b::a9fe:a9fe', false],
            ['2002:7f00:1::', false], ['2001:db8::1', false], ['::ffff:8.8.8.8', true],
        ];
    }

    public function test_hosts_resolving_to_private_addresses_are_never_contacted(): void
    {
        $web = (new FakeWeb)
            ->host('internal.example.com', '10.0.0.7')
            ->host('mixed.example.com', '93.184.216.34', '127.0.0.1')
            ->page('http://internal.example.com/', '<title>Secret</title>')
            ->page('http://mixed.example.com/', '<title>Secret</title>');

        $this->assertNull($web->get('http://internal.example.com/', ['text/html'], 1000));
        $this->assertNull($web->get('http://mixed.example.com/', ['text/html'], 1000));
        $this->assertNull($web->get('http://unknown.example.com/', ['text/html'], 1000));
        $this->assertSame([], $web->requests);
    }

    public function test_redirects_are_checked_hop_by_hop_and_connections_pinned(): void
    {
        $web = (new FakeWeb)
            ->host('short.example', '93.184.216.34')
            ->host('site.example', '151.101.1.1')
            ->redirect('https://short.example/x', '/landing')
            ->redirect('https://short.example/landing', 'https://site.example/article')
            ->page('https://site.example/article', '<title>Article</title>')
            ->redirect('https://short.example/evil', 'http://169.254.169.254/latest/meta-data/');

        $page = $web->get('https://short.example/x', ['text/html'], 1000);

        $this->assertSame('https://site.example/article', $page['url']);
        $this->assertSame(
            [['https://short.example/x', '93.184.216.34'], ['https://short.example/landing', '93.184.216.34'], ['https://site.example/article', '151.101.1.1']],
            array_map(fn ($r) => [$r['url'], $r['ip']], $web->requests),
        );

        $web->requests = [];
        $this->assertNull($web->get('https://short.example/evil', ['text/html'], 1000));
        $this->assertCount(1, $web->requests);
    }

    public function test_content_type_and_size_limits(): void
    {
        $web = (new FakeWeb)
            ->host('site.example')
            ->respond('https://site.example/file.zip', 'PK...', 'application/zip')
            ->respond('https://site.example/big.png', str_repeat('x', 2000), 'image/png')
            ->page('https://site.example/long', str_repeat('<meta name="x" content="y">', 200));

        $this->assertNull($web->get('https://site.example/file.zip', ['text/html'], 1000));
        $this->assertNull($web->get('https://site.example/big.png', ['image/png'], 1000));
        $this->assertSame(1000, strlen($web->get('https://site.example/long', ['text/html'], 1000, true)['body']));

        foreach (range(1, SafeFetcher::MAX_REDIRECTS + 1) as $i) {
            $web->redirect("https://site.example/loop{$i}", 'https://site.example/loop'.($i + 1));
        }
        $this->assertNull($web->get('https://site.example/loop1', ['text/html'], 1000));
    }
}
