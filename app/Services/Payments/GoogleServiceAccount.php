<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * An OAuth access token for the Google Play Developer API (Y2) from the service-account JSON the
 * admin pasted in App settings: an RS256 JWT signed with the account's private key (openssl),
 * exchanged at Google's token endpoint and cached until shortly before it expires. Same recipe
 * as PushService's Firebase token, with the androidpublisher scope.
 */
class GoogleServiceAccount
{
    public const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    public const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** The decoded service-account JSON, or null when it is missing or not usable. */
    public function credentials(): ?array
    {
        $raw = config('services.play.service_account');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return null;
        }

        return $decoded;
    }

    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    /** A bearer token for the Play Developer API; `$fresh` skips the cache (admin Test connection). */
    public function accessToken(bool $fresh = false): string
    {
        $credentials = $this->credentials() ?? throw new PaymentException('gateway_unavailable', 'The Google Play service account is not set up.', 503);
        $key = 'play:token:'.md5((string) $credentials['client_email']);

        if (! $fresh && is_string($cached = Cache::get($key)) && $cached !== '') {
            return $cached;
        }

        $tokenUri = is_string($credentials['token_uri'] ?? null) && $credentials['token_uri'] !== '' ? $credentials['token_uri'] : self::TOKEN_URI;

        try {
            $response = Http::asForm()->timeout(10)->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->signedJwt($credentials, $tokenUri),
            ]);
        } catch (PaymentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PaymentException('google_unavailable', 'Google did not answer the token request.', 503);
        }

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            $detail = (string) ($response->json('error_description') ?? $response->json('error') ?? 'HTTP '.$response->status());
            throw new PaymentException('google_unavailable', 'Google refused the service account: '.$detail, 503);
        }

        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 300));

        return $token;
    }

    /** The signed assertion: header.claims.signature, base64url, RS256 over the private key. */
    public function signedJwt(array $credentials, string $audience): string
    {
        $now = time();
        $payload = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$this->base64Url((string) json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $audience,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

        $key = openssl_pkey_get_private((string) $credentials['private_key']);
        if ($key === false || ! openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new PaymentException('gateway_unavailable', 'The Google Play service-account private key is not valid.', 503);
        }

        return $payload.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
