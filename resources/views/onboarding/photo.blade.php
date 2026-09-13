<x-layouts.guest title="Add a profile photo">
    <ol class="signup-steps" aria-label="Sign-up steps">
        <li class="is-done"><span><x-icon name="check" class="icon-xs" /></span> Your details</li>
        <li class="is-current"><span>2</span> Profile photo <em>(optional)</em></li>
    </ol>

    <h2 class="auth-title">Add a profile photo</h2>
    <p class="auth-subtitle">Help your contacts recognise you, {{ \Illuminate\Support\Str::before($user->name, ' ') }}. You can skip this and add one later in Settings.</p>

    <form method="POST" action="{{ route('onboarding.photo.store') }}" enctype="multipart/form-data" class="auth-form onboarding-photo" data-loading-form data-avatar-picker novalidate>
        @csrf

        <x-alerts />

        <label for="onboarding-photo" class="dp-picker" data-avatar-preview>
            <span class="dp-picker-initials avatar-fallback" style="--hue: {{ (int) $user->avatar_hue }}" data-avatar-placeholder>{{ $user->initials }}</span>
            <img alt="" hidden data-avatar-image>
            <span class="dp-picker-badge" aria-hidden="true"><x-icon name="camera" /></span>
            <span class="sr-only">Choose a profile photo</span>
        </label>
        <input id="onboarding-photo" type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="sr-only" data-avatar-input data-enable-submit>

        @error('profile_image')
            <p class="form-error justify-center"><x-icon name="circle-alert" />{{ $message }}</p>
        @enderror

        <p class="form-hint text-center">JPG, PNG or WEBP · up to {{ (int) (config('chat.uploads.avatar.max_kb') / 1024) }} MB. Tap the circle to choose a photo.</p>

        <div class="flex flex-col gap-2">
            <button type="submit" class="btn btn-primary btn-lg btn-block" data-photo-submit disabled>
                <x-icon name="check" />
                <span>Save photo &amp; continue</span>
            </button>
            <button type="button" class="btn btn-ghost btn-block" data-avatar-clear hidden>Remove photo</button>
            <a href="{{ route('chat.index') }}" class="btn btn-secondary btn-lg btn-block">Skip for now</a>
        </div>
    </form>
</x-layouts.guest>
