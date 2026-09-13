<x-layouts.guest title="Create account" wide>
    <h2 class="auth-title">Create your account</h2>
    <p class="auth-subtitle">Start chatting privately in less than a minute.</p>

    <form method="POST" action="{{ route('register.store') }}" enctype="multipart/form-data" class="auth-form" data-loading-form novalidate>
        @csrf

        <x-alerts />

        {{-- Profile image --}}
        <div class="form-group">
            <span class="form-label">Profile image <span class="optional">(optional)</span></span>
            <div class="avatar-picker" data-avatar-picker>
                <label for="field-profile_image" class="avatar-picker-preview" data-avatar-preview>
                    <x-icon name="camera" class="icon-lg" data-avatar-placeholder />
                    <img alt="" hidden data-avatar-image>
                </label>
                <div class="flex flex-col gap-1.5">
                    <div class="flex gap-2">
                        <label for="field-profile_image" class="btn btn-secondary btn-sm cursor-pointer">
                            <x-icon name="upload" /> Upload photo
                        </label>
                        <button type="button" class="btn btn-ghost btn-sm" data-avatar-clear hidden>Remove</button>
                    </div>
                    <span class="avatar-picker-meta">JPG, PNG or WEBP · max {{ (int) (config('chat.uploads.avatar.max_kb') / 1024) }} MB</span>
                </div>
                <input id="field-profile_image" type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="sr-only" data-avatar-input>
            </div>
            @error('profile_image')
                <p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>
            @enderror
        </div>

        <div class="auth-grid auth-grid-2">
            <x-field name="name" label="Full name" icon="user" placeholder="Awais Ahmed" autocomplete="name" maxlength="100" required />
            <x-field name="username" label="Username" icon="at-sign" placeholder="awais" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="30" required />
        </div>

        <div class="auth-grid auth-grid-2">
            <x-field name="email" type="email" label="Email" icon="mail" placeholder="you@example.com" autocomplete="email" maxlength="191" required />
            <x-field name="phone" type="tel" label="Mobile number" icon="phone" placeholder="+92 300 1234567" autocomplete="tel" maxlength="20" required />
        </div>

        <div class="auth-grid auth-grid-2">
            <x-field name="password" type="password" label="Password" icon="lock" placeholder="Min. 8 characters" autocomplete="new-password" data-strength-input required>
                <div class="strength" data-strength-meter data-score="0" aria-hidden="true"><span></span><span></span><span></span><span></span></div>
            </x-field>
            <x-field name="password_confirmation" type="password" label="Confirm password" icon="lock" placeholder="Repeat password" autocomplete="new-password" required />
        </div>

        <p class="form-hint -mt-1">Use at least 8 characters with upper &amp; lowercase letters and a number.</p>

        <button type="submit" class="btn btn-primary btn-lg btn-block">
            <x-icon name="user-plus" />
            <span>Create account</span>
        </button>
    </form>

    <p class="auth-alt">
        Already have an account?
        <a href="{{ route('login') }}" class="auth-link">Sign in</a>
    </p>
</x-layouts.guest>
