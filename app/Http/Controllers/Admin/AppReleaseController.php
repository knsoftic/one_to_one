<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAuditService;
use App\Services\AppUpdateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * X4 — Admin → App settings → Android app: the newest version, the oldest version still
 * allowed, what's new, and its APK or store link.
 */
class AppReleaseController extends Controller
{
    public function __construct(
        private readonly AppUpdateService $updates,
        private readonly AdminAuditService $audit,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('release', [
            'latest_code' => ['nullable', 'integer', 'min:1', 'max:2100000000'],
            'latest_name' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/'],
            'min_code' => ['nullable', 'integer', 'min:1', 'lte:latest_code'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'download_url' => ['nullable', 'url:https', 'max:500'],
            'apk' => ['nullable', 'file', 'extensions:apk', 'max:204800'],
            'remove_apk' => ['nullable', 'boolean'],
        ], [
            'min_code.lte' => 'The oldest allowed version can\'t be newer than the latest version.',
            'latest_name.regex' => 'Use a version like 1.2 or 1.2.0.',
            'apk.extensions' => 'Upload the Android .apk file.',
        ]);

        if (($request->hasFile('apk') || filled($validated['download_url'] ?? null) || isset($validated['min_code'])) && empty($validated['latest_code'])) {
            throw ValidationException::withMessages(['latest_code' => 'Enter the version code of this app first.'])->errorBag('release');
        }

        $this->updates->update($validated, $request->file('apk'), $request->boolean('remove_apk'));

        $this->audit->record($request->user(), 'app.release_updated', null, empty($validated['latest_code'])
            ? 'Turned off the Android update prompt'
            : 'Published Android app '.($validated['latest_name'] ?? $validated['latest_code']).' (version code '.$validated['latest_code'].')'
                .(isset($validated['min_code']) ? ', older than '.$validated['min_code'].' must update' : '')
                .($request->hasFile('apk') ? ', new APK uploaded' : ''));

        return redirect()->to(route('admin.settings').'#android')->with('status', 'Android app settings saved.');
    }
}
