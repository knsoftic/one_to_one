<?php

namespace App\Services;

use App\Exceptions\SmsException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Phase 7 — sends text messages through the SMS gateway set in config/services.php:
 * "log" (testing), "twilio" or "http" (any gateway with a web API).
 */
class SmsService
{
    /** Whether texts can reach real phones (or, outside production, the log). */
    public function available(): bool
    {
        $config = config('services.sms');

        return match ($config['driver'] ?? 'log') {
            'twilio' => filled($config['twilio']['sid'] ?? null) && filled($config['twilio']['token'] ?? null) && filled($config['twilio']['from'] ?? null),
            'http' => filled($config['http']['url'] ?? null),
            'log' => ! app()->isProduction(),
            default => false,
        };
    }

    /**
     * @throws SmsException when the gateway did not accept the text
     */
    public function send(string $to, string $message): void
    {
        $driver = (string) config('services.sms.driver', 'log');

        try {
            match ($driver) {
                'log' => Log::info("SMS to {$to}: {$message}"),
                'twilio' => $this->twilio($to, $message),
                'http' => $this->http($to, $message),
                default => throw new RuntimeException("Unknown SMS driver [{$driver}]."),
            };
        } catch (Throwable $e) {
            report($e);

            throw new SmsException('The SMS could not be sent.', previous: $e);
        }
    }

    private function twilio(string $to, string $message): void
    {
        $config = config('services.sms.twilio');
        $from = (string) $config['from'];
        $fromField = str_starts_with($from, 'MG') ? 'MessagingServiceSid' : 'From';

        Http::asForm()
            ->withBasicAuth((string) $config['sid'], (string) $config['token'])
            ->timeout(15)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$config['sid']}/Messages.json", [
                'To' => $this->e164($to),
                'Body' => $message,
                $fromField => $from,
            ])
            ->throw();
    }

    private function http(string $to, string $message): void
    {
        $config = config('services.sms.http');

        $number = ($config['phone_format'] ?? 'plus') === 'digits' ? ltrim($this->e164($to), '+') : $this->e164($to);
        $fields = [
            ...$this->pairs($config['params'] ?? null),
            (string) ($config['to_field'] ?: 'to') => $number,
            (string) ($config['message_field'] ?: 'message') => $message,
        ];

        $request = Http::timeout(15)->withHeaders($this->pairs($config['headers'] ?? null));
        if (filled($config['bearer'] ?? null)) {
            $request = $request->withToken((string) $config['bearer']);
        }

        $url = (string) $config['url'];
        $format = strtolower((string) ($config['format'] ?? 'form'));
        $response = match (true) {
            strtolower((string) ($config['method'] ?? 'post')) === 'get', $format === 'query' => $request->get($url, $fields),
            $format === 'json' => $request->asJson()->post($url, $fields),
            default => $request->asForm()->post($url, $fields),
        };

        $response->throw();

        $expected = (string) ($config['success_text'] ?? '');
        if ($expected !== '' && ! str_contains(strtolower($response->body()), strtolower($expected))) {
            throw new RuntimeException('The SMS gateway did not confirm the text: '.mb_substr($response->body(), 0, 200));
        }
    }

    /** "+92 300 1234567" / "923001234567" → "+923001234567". */
    private function e164(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        return '+'.(str_starts_with($phone, '00') ? substr((string) $digits, 2) : $digits);
    }

    /**
     * @return array<string, string>
     */
    private function pairs(?string $query): array
    {
        if (blank($query)) {
            return [];
        }

        parse_str((string) $query, $pairs);

        return array_map('strval', array_filter($pairs, 'is_scalar'));
    }
}
