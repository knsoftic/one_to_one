<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Settings that used to be typed into .env, now edited in the admin panel and stored
 * in MySQL (app_settings): SMS gateway, email (SMTP), GIF search, call relay (TURN),
 * invite link and sign-up options. A value saved in the admin panel wins; an empty one
 * falls back to .env. Passwords and API keys are stored encrypted with APP_KEY and are
 * never shown again.
 *
 * Only the server basics stay in .env (database, APP_KEY, APP_URL, Reverb), because
 * the app needs them before it can read the database.
 */
class AppConfigService
{
    /**
     * key => config path (null = used by the app directly), secret, allowed values.
     *
     * @var array<string, array{config?: string, secret?: bool, options?: list<string>, int?: bool}>
     */
    public const FIELDS = [
        // Sign-up and login
        'default_country_code' => [],
        'signup_email' => ['options' => ['optional', 'required', 'hidden']],

        // SMS (log in with the phone number, change number)
        'sms_driver' => ['config' => 'services.sms.driver', 'options' => ['log', 'twilio', 'http']],
        'sms_twilio_sid' => ['config' => 'services.sms.twilio.sid'],
        'sms_twilio_token' => ['config' => 'services.sms.twilio.token', 'secret' => true],
        'sms_twilio_from' => ['config' => 'services.sms.twilio.from'],
        'sms_http_url' => ['config' => 'services.sms.http.url'],
        'sms_http_method' => ['config' => 'services.sms.http.method', 'options' => ['post', 'get']],
        'sms_http_format' => ['config' => 'services.sms.http.format', 'options' => ['form', 'json', 'query']],
        'sms_http_to_field' => ['config' => 'services.sms.http.to_field'],
        'sms_http_message_field' => ['config' => 'services.sms.http.message_field'],
        'sms_http_phone_format' => ['config' => 'services.sms.http.phone_format', 'options' => ['plus', 'digits']],
        'sms_http_params' => ['config' => 'services.sms.http.params', 'secret' => true],
        'sms_http_headers' => ['config' => 'services.sms.http.headers', 'secret' => true],
        'sms_http_bearer' => ['config' => 'services.sms.http.bearer', 'secret' => true],
        'sms_http_success_text' => ['config' => 'services.sms.http.success_text'],

        // Email (password reset, forgot PIN, email changed)
        'mail_mailer' => ['config' => 'mail.default', 'options' => ['smtp', 'log']],
        'mail_host' => ['config' => 'mail.mailers.smtp.host'],
        'mail_port' => ['config' => 'mail.mailers.smtp.port', 'int' => true],
        'mail_encryption' => ['options' => ['tls', 'ssl', 'none']],
        'mail_username' => ['config' => 'mail.mailers.smtp.username'],
        'mail_password' => ['config' => 'mail.mailers.smtp.password', 'secret' => true],
        'mail_from_address' => ['config' => 'mail.from.address'],
        'mail_from_name' => ['config' => 'mail.from.name'],

        // GIF search, calls, invite link
        'tenor_key' => ['config' => 'chat.gifs.tenor_key', 'secret' => true],
        'turn_urls' => ['config' => 'chat.calls.turn_urls'],
        'turn_secret' => ['config' => 'chat.calls.turn_secret', 'secret' => true],
        'turn_username' => ['config' => 'chat.calls.turn_username'],
        'turn_password' => ['config' => 'chat.calls.turn_password', 'secret' => true],
        'invite_url' => ['config' => 'chat.invite.url'],
    ];

    /** @var array<string, mixed>|null the .env values, to fall back to when a setting is emptied */
    private ?array $original = null;

    /** @var array<string, true> config paths currently set from the admin panel */
    private array $applied = [];

    /** Put the saved settings into the app's configuration (each request and queued job). */
    public function apply(): void
    {
        try {
            $this->original ??= collect(self::FIELDS)->filter(fn ($field) => isset($field['config']))
                ->mapWithKeys(fn ($field) => [$field['config'] => config($field['config'])])
                ->put('mail.mailers.smtp.scheme', config('mail.mailers.smtp.scheme'))
                ->all();

            $values = [];
            foreach (self::FIELDS as $key => $field) {
                $value = $this->value($key);
                if (isset($field['config']) && $value !== null) {
                    $values[$field['config']] = ($field['int'] ?? false) ? (int) $value : $value;
                }
            }
            if (($encryption = $this->value('mail_encryption')) !== null) {
                $values['mail.mailers.smtp.scheme'] = $encryption === 'ssl' ? 'smtps' : 'smtp';
            }

            // Settings emptied since the last time go back to .env; everything else is left alone.
            $saved = array_keys($values);
            foreach (array_diff_key($this->applied, $values) as $path => $_) {
                $values[$path] = $this->original[$path] ?? null;
            }
            $this->applied = array_fill_keys($saved, true);

            $mailChanged = collect($values)->contains(fn ($value, $path) => str_starts_with($path, 'mail.') && config($path) !== $value);
            config($values);

            if ($mailChanged && app()->resolved('mail.manager')) {
                app('mail.manager')->forgetMailers();
            }
        } catch (Throwable $e) {
            // The app still works with .env when the settings can't be read (e.g. before migrating).
            report($e);
        }
    }

    /** A saved setting (decrypted), or null when it isn't set. */
    public function value(string $key): mixed
    {
        $stored = AppSetting::get($key);
        if ($stored === null || $stored === '') {
            return null;
        }

        if (self::FIELDS[$key]['secret'] ?? false) {
            try {
                return Crypt::decryptString((string) $stored);
            } catch (Throwable) {
                return null;
            }
        }

        return $stored;
    }

    /**
     * Save what the admin typed. Empty passwords/keys keep the saved one unless $clear names them.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $clear
     * @return list<string> the settings that changed (names only, never values)
     */
    public function update(array $input, array $clear = []): array
    {
        $changes = [];

        foreach (self::FIELDS as $key => $field) {
            if (! array_key_exists($key, $input) && ! in_array($key, $clear, true)) {
                continue;
            }

            $value = is_string($input[$key] ?? null) ? trim($input[$key]) : ($input[$key] ?? null);
            $value = $value === '' ? null : $value;

            if ($field['secret'] ?? false) {
                if (in_array($key, $clear, true)) {
                    $new = null;
                } elseif ($value === null) {
                    continue;
                } else {
                    $new = Crypt::encryptString((string) $value);
                }
                if ($new === null && AppSetting::get($key) === null) {
                    continue;
                }
            } else {
                $new = $value;
                if ($new === AppSetting::get($key)) {
                    continue;
                }
            }

            $changes[$key] = $new;
        }

        if ($changes) {
            AppSetting::put($changes);
            $this->apply();
        }

        return array_keys($changes);
    }

    /**
     * Values for the settings form: plain values, and for secrets only whether one is saved.
     *
     * @return array<string, mixed>
     */
    public function formValues(): array
    {
        return collect(self::FIELDS)->mapWithKeys(fn ($field, $key) => [
            $key => ($field['secret'] ?? false) ? AppSetting::get($key) !== null : AppSetting::get($key),
        ])->all();
    }
}
