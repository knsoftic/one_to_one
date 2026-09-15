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
use App\Services\SmsService;
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
            'values' => $this->config->formValues(),
            'smsReady' => $sms->available(),
            'mailer' => (string) config('mail.default'),
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
        ];
        AppSetting::put($simple);

        $input = array_intersect_key($validated, AppConfigService::FIELDS);
        $changed = $this->config->update($input, $validated['clear'] ?? []);

        // The log names what changed, never passwords or keys.
        $this->audit->record($request->user(), 'settings.updated', null,
            'Changed app settings: sign-ups '.($simple['registration_open'] ? 'open' : 'closed').', notice '.($simple['notice'] ? 'on' : 'off').($changed ? ', '.implode(', ', $changed) : ''),
            ['changed' => $changed, 'registration_open' => $simple['registration_open']]);

        return back()->with('status', 'Settings saved.');
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
