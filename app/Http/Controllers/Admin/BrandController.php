<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminAuditService;
use App\Services\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Admin → App settings → App name & icon.
 */
class BrandController extends Controller
{
    public function __construct(
        private readonly BrandService $brand,
        private readonly AdminAuditService $audit,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('brand', [
            'name' => ['required', 'string', 'max:'.BrandService::NAME_MAX, 'not_regex:/[<>\r\n]/'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'file', 'image', Rule::dimensions()->minWidth(512)->minHeight(512), 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'remove_icon' => ['nullable', 'boolean'],
        ], [
            'name.required' => 'Enter the app name.',
            'name.not_regex' => 'Use letters, numbers and spaces.',
            'color.regex' => 'Pick a colour.',
            'icon.dimensions' => 'Use a square picture of at least 512 × 512 pixels (1024 × 1024 is best).',
            'icon.mimes' => 'Upload a PNG, JPG or WebP picture.',
        ]);

        $before = $this->brand->name();
        try {
            $this->brand->update($validated, $request->file('icon'), $request->boolean('remove_icon'));
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['icon' => 'This picture could not be used. Try another PNG or JPG.'])->errorBag('brand');
        }

        $changes = array_filter([
            $before !== $this->brand->name() ? 'name "'.$before.'" → "'.$this->brand->name().'"' : null,
            $request->hasFile('icon') ? 'new icon' : null,
            $request->boolean('remove_icon') && ! $request->hasFile('icon') ? 'icon removed' : null,
        ]);
        $this->audit->record($request->user(), 'app.brand_updated', null, 'App name & icon: '.($changes ? implode(', ', $changes) : 'saved'));

        return redirect()->to(route('admin.settings').'#brand')->with('status', 'App name and icon saved.');
    }

    /** Public: the name and icon, for `php artisan app:android-brand --url=…` when building the APK. */
    public function show(): JsonResponse
    {
        return response()->json($this->brand->publicPayload(), 200, ['Cache-Control' => 'no-cache'], JSON_UNESCAPED_SLASHES);
    }
}
