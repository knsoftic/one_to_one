<?php

namespace App\Http\Middleware;

use App\Services\GifService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for every web response, including a nonce-based
 * Content-Security-Policy for HTML pages (defence in depth against XSS).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $cspEnabled = (bool) config('chat.security.csp') && ! Vite::isRunningHot();

        if ($cspEnabled) {
            Vite::useCspNonce();
        }

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Microphone (voice notes, calls), camera (calls, in-app camera) and location sharing; everything else is disabled.
            'Permissions-Policy' => 'microphone=(self), camera=(self), geolocation=(self), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($cspEnabled
            && ! $response->headers->has('Content-Security-Policy')
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Content-Security-Policy', $this->policy($request));
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(Request $request): string
    {
        $nonce = Vite::cspNonce();
        $connect = ["'self'"];

        // Allow the Reverb WebSocket endpoint.
        $reverb = config('broadcasting.connections.reverb.options', []);
        if (config('broadcasting.default') === 'reverb') {
            $host = $reverb['host'] ?: $request->getHost();
            $scheme = ($reverb['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws';
            $connect[] = "{$scheme}://{$host}:{$reverb['port']}";
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            // GIF search previews come from Tenor's media host (only when GIF search is on).
            "img-src 'self' data: blob:".(filled(config('chat.gifs.tenor_key')) ? ' https://'.GifService::MEDIA_HOST : ''),
            "media-src 'self' blob:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', $connect),
            "worker-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
