<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use Illuminate\View\View;

/**
 * X5 — public pages Google Play asks for: privacy policy, terms, child safety standards
 * and how to delete an account. The operator and contact email come from App settings.
 */
class LegalController extends Controller
{
    public const PAGES = [
        'privacy' => 'Privacy policy',
        'terms' => 'Terms of service',
        'child-safety' => 'Child safety standards',
        'delete-account' => 'Delete your account',
    ];

    public function show(string $page): View
    {
        abort_unless(array_key_exists($page, self::PAGES), 404);

        return view('legal.'.$page, [
            'title' => self::PAGES[$page],
            'page' => $page,
            'pages' => self::PAGES,
            'app' => (string) config('app.name'),
            'owner' => (string) (AppSetting::get('legal_owner') ?: config('app.name')),
            'email' => (string) (AppSetting::get('legal_email') ?: config('mail.from.address')),
            'country' => (string) (AppSetting::get('legal_country') ?: 'Pakistan'),
            'updated' => (string) (AppSetting::get('legal_updated') ?: '15 September 2026'),
            'url' => rtrim((string) config('app.url'), '/'),
        ]);
    }
}
