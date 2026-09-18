<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SmsException;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\AppConfigService;
use App\Services\AppUpdateService;
use App\Services\BrandService;
use App\Services\MonetisationService;
use App\Services\SmsService;
use App\Services\TurnServerService;
use App\Support\AdPlacement;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Admin panel: the audit log and app settings — sign-up, notice, and the settings that
 * used to live in .env (SMS, email, GIF search, calls, invite link), saved in MySQL.
 */
class SystemController extends Controller
{
    public function __construct(
        private readonly AppConfigService $config,
        private readonly AdminAuditService $audit,
    ) {}

    public function audit(Request $request): View
    {
        $filters = $request->validate([
            'action' => ['nullable', Rule::in(array_keys(AdminAuditLog::ACTIONS))],
            'admin' => ['nullable', 'integer'],
            'target' => ['nullable', 'integer'],
        ]);

        $logs = AdminAuditLog::query()
            ->with('admin')
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($filters['admin'] ?? null, fn ($q, $id) => $q->where('admin_id', $id))
            ->when($filters['target'] ?? null, fn ($q, $id) => $q->where('target_type', 'User')->where('target_id', $id))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'admins' => User::query()->whereIn('id', AdminAuditLog::query()->select('admin_id')->distinct())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function settings(SmsService $sms): View
    {
        return view('admin.settings', [
            'registrationOpen' => (bool) AppSetting::get('registration_open'),
            'notice' => AppSetting::get('notice'),
            'legal' => [
                'owner' => AppSetting::get('legal_owner'),
                'email' => AppSetting::get('legal_email'),
                'country' => AppSetting::get('legal_country'),
                'updated' => AppSetting::get('legal_updated'),
            ],
            'values' => $this->config->formValues(),
            'smsReady' => $sms->available(),
            'mailer' => (string) config('mail.default'),
            'turn' => app(TurnServerService::class)->status(),
            'ads' => [
                'enabled' => (bool) AppSetting::get('ads_enabled'),
                'frequency' => (int) AppSetting::get('ad_frequency'),
                'placements' => AdPlacement::enabled(),
                'admob_app_id' => AppSetting::get('admob_app_id'),
                'admob_native_unit' => AppSetting::get('admob_native_unit'),
                'admob_test' => (bool) AppSetting::get('admob_test'),
                'adsense_client' => AppSetting::get('adsense_client'),
                'adsense_slot' => AppSetting::get('adsense_slot'),
            ],
            'paid' => $this->paidSettings(),
            'brand' => app(BrandService::class)->settings() + [
                'current_name' => app(BrandService::class)->name(),
                'default_name' => app(BrandService::class)->defaultName(),
                'icon_url' => app(BrandService::class)->iconUrl(192),
                'maskable_url' => app(BrandService::class)->iconUrl('maskable'),
            ],
            'android' => [
                'latest_code' => AppSetting::get('android_latest_code'),
                'latest_name' => AppSetting::get('android_latest_name'),
                'min_code' => AppSetting::get('android_min_code'),
                'notes' => AppSetting::get('android_notes'),
                'download_url' => AppSetting::get('android_download_url'),
                'apk_size' => app(AppUpdateService::class)->apkSize(),
                'apk_url' => app(AppUpdateService::class)->apkPath() ? route('app.download.android') : null,
            ],
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $secrets = collect(AppConfigService::FIELDS)->filter(fn ($field) => $field['secret'] ?? false)->keys()->all();
        $fieldName = '/^[A-Za-z0-9_.\-\[\]]{1,60}$/';

        $validated = $request->validate([
            'registration_open' => ['nullable', 'boolean'],
            'notice' => ['nullable', 'string', 'max:300'],
            // X5 — shown on the privacy policy, terms and child safety pages.
            'legal_owner' => ['nullable', 'string', 'max:120'],
            'legal_email' => ['nullable', 'email:rfc', 'max:191'],
            'legal_country' => ['nullable', 'string', 'max:80'],
            'legal_updated' => ['nullable', 'string', 'max:40'],
            'default_country_code' => ['nullable', 'regex:/^\+\d{1,4}$/'],
            'signup_email' => ['nullable', Rule::in(AppConfigService::FIELDS['signup_email']['options'])],

            'sms_driver' => ['nullable', Rule::in(AppConfigService::FIELDS['sms_driver']['options'])],
            'sms_twilio_sid' => ['nullable', 'string', 'max:64'],
            'sms_twilio_token' => ['nullable', 'string', 'max:255'],
            'sms_twilio_from' => ['nullable', 'string', 'max:64'],
            'sms_http_url' => ['nullable', 'url:https,http', 'max:500'],
            'sms_http_method' => ['nullable', Rule::in(AppConfigService::FIELDS['sms_http_method']['options'])],
            'sms_http_format' => ['nullable', Rule::in(AppConfigService::FIELDS['sms_http_format']['options'])],
            'sms_http_to_field' => ['nullable', 'regex:'.$fieldName],
            'sms_http_message_field' => ['nullable', 'regex:'.$fieldName],
            'sms_http_phone_format' => ['nullable', Rule::in(AppConfigService::FIELDS['sms_http_phone_format']['options'])],
            'sms_http_params' => ['nullable', 'string', 'max:1000'],
            'sms_http_headers' => ['nullable', 'string', 'max:1000'],
            'sms_http_bearer' => ['nullable', 'string', 'max:500'],
            'sms_http_success_text' => ['nullable', 'string', 'max:100'],

            'mail_mailer' => ['nullable', Rule::in(AppConfigService::FIELDS['mail_mailer']['options'])],
            'mail_host' => ['nullable', 'string', 'max:255'],
            'mail_port' => ['nullable', 'integer', 'between:1,65535'],
            'mail_encryption' => ['nullable', Rule::in(AppConfigService::FIELDS['mail_encryption']['options'])],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email:rfc', 'max:191'],
            'mail_from_name' => ['nullable', 'string', 'max:100'],

            // Ads (Y1)
            'ads_enabled' => ['nullable', 'boolean'],
            'ad_frequency' => ['nullable', 'integer', 'between:4,50'],
            'ad_placements' => ['nullable', 'array'],
            'ad_placements.*' => [Rule::in(AdPlacement::keys())],
            'admob_app_id' => ['nullable', 'string', 'max:120', 'regex:/^ca-app-pub-\d{16}~\d{10}$/'],
            'admob_native_unit' => ['nullable', 'string', 'max:120', 'regex:/^ca-app-pub-\d{16}\/\d{10}$/'],
            'admob_test' => ['nullable', 'boolean'],
            'adsense_client' => ['nullable', 'string', 'max:120', 'regex:/^ca-pub-\d{16}$/'],
            'adsense_slot' => ['nullable', 'string', 'max:20', 'regex:/^\d{6,20}$/'],
            // Paid features (Y2)
            ...$this->paidRules(),

            'tenor_key' => ['nullable', 'string', 'max:255'],
            'turn_urls' => ['nullable', 'string', 'max:1000'],
            'turn_secret' => ['nullable', 'string', 'max:255'],
            'turn_username' => ['nullable', 'string', 'max:255'],
            'turn_password' => ['nullable', 'string', 'max:255'],
            'invite_url' => ['nullable', 'url:https,http', 'max:500'],

            'clear' => ['nullable', 'array'],
            'clear.*' => [Rule::in($secrets)],
        ], [
            'default_country_code.regex' => 'Write the country code like +92.',
            'sms_http_to_field.regex' => 'Use letters, numbers, dots, dashes or underscores.',
            'sms_http_message_field.regex' => 'Use letters, numbers, dots, dashes or underscores.',
        ]);

        $simple = [
            'registration_open' => $request->boolean('registration_open'),
            'notice' => filled($validated['notice'] ?? null) ? trim((string) $validated['notice']) : null,
            'ads_enabled' => $request->boolean('ads_enabled'),
            'admob_test' => $request->boolean('admob_test'),
        ];
        if ($request->has('ad_frequency')) {
            $simple['ad_frequency'] = (int) ($validated['ad_frequency'] ?? 6);
        }
        // Which screens may show ads. The Ads form always posts this field, so an empty list
        // means "nowhere" and the ads simply stop appearing.
        if ($request->has('ads_section')) {
            $simple['ad_placements'] = array_values($validated['ad_placements'] ?? []);
        }
        // Paid features (Y2): saved only when that section of the form was submitted.
        $paidChanged = [];
        if ($request->has('paid_section')) {
            [$paidSimple, $paidChanged] = $this->paidSimple($request, $validated);
            $simple += $paidSimple;
        }
        foreach (['admob_app_id', 'admob_native_unit', 'adsense_client', 'adsense_slot'] as $key) {
            if ($request->has($key)) {
                $simple[$key] = filled($validated[$key] ?? null) ? trim((string) $validated[$key]) : null;
            }
        }
        foreach (['legal_owner', 'legal_email', 'legal_country', 'legal_updated'] as $key) {
            if ($request->has($key)) {
                $simple[$key] = filled($validated[$key] ?? null) ? trim((string) $validated[$key]) : null;
            }
        }
        AppSetting::put($simple);

        $input = array_intersect_key($validated, AppConfigService::FIELDS);
        $changed = $this->config->update($input, $validated['clear'] ?? []);

        // The log names what changed, never passwords or keys.
        $this->audit->record($request->user(), 'settings.updated', null,
            'Changed app settings: sign-ups '.($simple['registration_open'] ? 'open' : 'closed').', notice '.($simple['notice'] ? 'on' : 'off').', ads '.($simple['ads_enabled'] ? 'on' : 'off').($changed ? ', '.implode(', ', $changed) : ''),
            ['changed' => $changed, 'registration_open' => $simple['registration_open']]);

        if ($paidChanged) {
            $this->audit->record($request->user(), 'paid.settings_updated', null,
                'Changed paid-feature settings: '.implode(', ', $paidChanged), ['changed' => $paidChanged]);
        }

        return back()->with('status', 'Settings saved.');
    }

    /** The Paid features section of the settings page (Y2). */
    private function paidSettings(): array
    {
        $keys = [
            'paid_enabled', 'paid_currency', 'promote_enabled', 'promo_rate_status', 'promo_rate_channel', 'promo_rate_community',
            'promo_rate_business', 'promo_rate_card', 'promo_rate_link', 'promo_min_coins', 'promo_max_coins', 'promo_max_active',
            'promo_daily_cap', 'promo_weight', 'promo_auto_approve', 'promo_blocked_hosts', 'referral_enabled', 'referral_reward',
            'referral_welcome', 'referral_daily_cap', 'referral_ip_cap', 'badge_coin_price', 'badge_days', 'wallet_withdraw_enabled',
            'manual_enabled', 'manual_jazzcash', 'manual_easypaisa', 'manual_bank', 'manual_note', 'manual_expire_hours',
            'proof_keep_days', 'stripe_enabled', 'paypal_enabled', 'play_enabled', 'play_min_app_code',
        ];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = AppSetting::get($key);
        }
        $out['promo_placements'] = is_array(AppSetting::get('promo_placements')) ? AppSetting::get('promo_placements') : [];
        $out['stripe_configured'] = filled(config('services.stripe.secret'));
        $out['paypal_configured'] = filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret'));
        $out['play_configured'] = filled(config('services.play.service_account'));

        return $out;
    }

    /** @return array<string, array<int, mixed>> */
    private function paidRules(): array
    {
        $on = ['nullable', 'boolean'];
        $count = fn (int $min, int $max) => ['nullable', 'integer', "between:{$min},{$max}"];

        return [
            'paid_enabled' => $on,
            'paid_currency' => ['nullable', Rule::in(array_keys(MonetisationService::CURRENCIES))],
            'promote_enabled' => $on,
            'promo_rate_status' => $count(1, 100000),
            'promo_rate_channel' => $count(1, 100000),
            'promo_rate_community' => $count(1, 100000),
            'promo_rate_business' => $count(1, 100000),
            'promo_rate_card' => $count(1, 100000),
            'promo_rate_link' => $count(1, 100000),
            'promo_min_coins' => $count(1, 1000000),
            'promo_max_coins' => ['nullable', 'integer', 'between:1,10000000', 'gte:promo_min_coins'],
            'promo_max_active' => $count(1, 100),
            'promo_daily_cap' => $count(1, 50),
            'promo_weight' => $count(1, 100),
            'promo_auto_approve' => $on,
            'promo_placements' => ['nullable', 'array'],
            'promo_placements.*' => [Rule::in(AdPlacement::keys())],
            'promo_blocked_hosts' => ['nullable', 'string', 'max:2000'],
            'referral_enabled' => $on,
            'referral_reward' => $count(0, 100000),
            'referral_welcome' => $count(0, 100000),
            'referral_daily_cap' => $count(1, 10000),
            'referral_ip_cap' => $count(1, 1000),
            'badge_coin_price' => $count(0, 10000000),
            'badge_days' => $count(0, 3650),
            'wallet_withdraw_enabled' => $on,
            'manual_enabled' => $on,
            'manual_jazzcash' => ['nullable', 'string', 'max:600'],
            'manual_easypaisa' => ['nullable', 'string', 'max:600'],
            'manual_bank' => ['nullable', 'string', 'max:600'],
            'manual_note' => ['nullable', 'string', 'max:300'],
            'manual_expire_hours' => $count(1, 720),
            'proof_keep_days' => $count(1, 3650),
            'stripe_enabled' => $on,
            'stripe_publishable_key' => ['nullable', 'string', 'max:191', 'regex:/^pk_(test|live)_/'],
            'stripe_secret_key' => ['nullable', 'string', 'max:191', 'regex:/^(sk|rk)_(test|live)_/'],
            'stripe_webhook_secret' => ['nullable', 'string', 'max:191', 'regex:/^whsec_/'],
            'paypal_enabled' => $on,
            'paypal_client_id' => ['nullable', 'string', 'max:191'],
            'paypal_secret' => ['nullable', 'string', 'max:191'],
            'paypal_mode' => ['nullable', Rule::in(AppConfigService::FIELDS['paypal_mode']['options'])],
            'paypal_webhook_id' => ['nullable', 'string', 'max:64'],
            'play_enabled' => $on,
            'play_package_name' => ['nullable', 'string', 'max:120', 'regex:/^[a-zA-Z][a-zA-Z0-9_]*(\.[a-zA-Z][a-zA-Z0-9_]*)+$/'],
            'play_service_account_json' => ['nullable', 'string', 'max:10000', 'json'],
            'play_min_app_code' => ['nullable', 'integer', 'between:1,1000000'],
        ];
    }

    /**
     * The paid-feature settings to store, from the validated form.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function paidSimple(Request $request, array $validated): array
    {
        $before = $this->paidSettings();
        $simple = [];

        foreach (['paid_enabled', 'promote_enabled', 'promo_auto_approve', 'referral_enabled', 'wallet_withdraw_enabled', 'manual_enabled', 'stripe_enabled', 'paypal_enabled', 'play_enabled'] as $key) {
            $simple[$key] = $request->boolean($key);
        }
        $simple['paid_currency'] = strtoupper((string) ($validated['paid_currency'] ?? 'PKR'));
        foreach ([
            'promo_rate_status', 'promo_rate_channel', 'promo_rate_community', 'promo_rate_business', 'promo_rate_card', 'promo_rate_link',
            'promo_min_coins', 'promo_max_coins', 'promo_max_active', 'promo_daily_cap', 'promo_weight', 'referral_reward', 'referral_welcome',
            'referral_daily_cap', 'referral_ip_cap', 'badge_coin_price', 'badge_days', 'manual_expire_hours', 'proof_keep_days',
        ] as $key) {
            if (array_key_exists($key, $validated) && $validated[$key] !== null) {
                $simple[$key] = (int) $validated[$key];
            }
        }
        $simple['play_min_app_code'] = filled($validated['play_min_app_code'] ?? null) ? (int) $validated['play_min_app_code'] : null;
        $simple['promo_placements'] = array_values($validated['promo_placements'] ?? []);
        foreach (['promo_blocked_hosts', 'manual_jazzcash', 'manual_easypaisa', 'manual_bank', 'manual_note'] as $key) {
            $simple[$key] = filled($validated[$key] ?? null) ? trim((string) $validated[$key]) : null;
        }

        $changed = [];
        foreach ($simple as $key => $value) {
            if (($before[$key] ?? null) != $value) {
                $changed[] = $key;
            }
        }

        return [$simple, $changed];
    }

    public function testSms(Request $request, SmsService $sms): RedirectResponse
    {
        $request->merge(['test_phone' => Phone::forAccount((string) $request->input('test_phone'))]);
        $phone = $request->validateWithBag('testSms', ['test_phone' => ['required', 'regex:/^\+?[0-9]{7,15}$/']], ['test_phone.regex' => 'Enter a valid mobile number.'])['test_phone'];

        if (! $sms->available()) {
            return back()->withErrors(['test_phone' => 'Choose an SMS provider and save it first ("Log only" works outside production).'], 'testSms');
        }

        try {
            $sms->send($phone, 'Test message from '.config('app.name').': your SMS settings work.');
        } catch (SmsException $e) {
            return back()->withErrors(['test_phone' => 'The SMS could not be sent: '.mb_substr((string) $e->getPrevious()?->getMessage(), 0, 200)], 'testSms');
        }

        $this->audit->record($request->user(), 'settings.updated', null, 'Sent a test SMS', ['to' => $phone]);

        return back()->with('status', "Test SMS sent to {$phone}.".(config('services.sms.driver') === 'log' ? ' (Log only: see storage/logs/laravel.log.)' : ''));
    }

    public function testMail(Request $request): RedirectResponse
    {
        $email = $request->validateWithBag('testMail', ['test_email' => ['required', 'email:rfc', 'max:191']])['test_email'];

        try {
            Mail::raw('This is a test email from '.config('app.name').'. Your email settings work.', fn ($message) => $message->to($email)->subject(config('app.name').' test email'));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['test_email' => 'The email could not be sent: '.mb_substr($e->getMessage(), 0, 200)], 'testMail');
        }

        $this->audit->record($request->user(), 'settings.updated', null, 'Sent a test email', ['to' => $email]);

        return back()->with('status', "Test email sent to {$email}.".(config('mail.default') === 'log' ? ' (Log only: see storage/logs/laravel.log.)' : ''));
    }
}
