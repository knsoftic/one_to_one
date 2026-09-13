<?php

namespace Tests\Fakes;

use App\Support\HostResolver;
use App\Support\SafeFetcher;

/**
 * A pretend internet for SafeFetcher: DNS answers and HTTP responses by URL.
 * The real address checks and redirect handling still run.
 */
class FakeWeb extends SafeFetcher
{
    /** @var array<string, list<string>> */
    public array $dns = [];

    /** @var array<string, array{status:int, headers:array<string,string>, body:string}> */
    public array $responses = [];

    /** @var list<array{url:string, ip:string}> */
    public array $requests = [];

    public function __construct()
    {
        $web = $this;

        parent::__construct(new class($web) extends HostResolver
        {
            public function __construct(private readonly FakeWeb $web) {}

            public function resolve(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->web->dns[$host] ?? []);
            }
        });
    }

    public function host(string $host, string ...$ips): static
    {
        $this->dns[$host] = $ips ?: ['93.184.216.34'];

        return $this;
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function respond(string $url, string $body, string $contentType = 'text/html; charset=utf-8', int $status = 200, array $headers = []): static
    {
        $this->responses[$url] = [
            'status' => $status,
            'headers' => array_change_key_case($headers + ['Content-Type' => $contentType]),
            'body' => $body,
        ];

        return $this;
    }

    public function redirect(string $from, string $location, int $status = 302): static
    {
        return $this->respond($from, '', 'text/html', $status, ['Location' => $location]);
    }

    public function page(string $url, string $head): static
    {
        return $this->respond($url, "<!doctype html><html><head>{$head}</head><body>Hello</body></html>");
    }

    public static function png(int $width = 320, int $height = 180): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected function transfer(string $url, string $host, int $port, string $ip, int $maxBytes, bool $allowPartial, int $timeoutSeconds): ?array
    {
        $this->requests[] = ['url' => $url, 'ip' => $ip];
        $response = $this->responses[$url] ?? null;

        if ($response === null) {
            return null;
        }

        if (strlen($response['body']) > $maxBytes) {
            return $allowPartial ? ['body' => substr($response['body'], 0, $maxBytes)] + $response : null;
        }

        return $response;
    }
}
