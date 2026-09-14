<x-layouts.admin title="App settings" heading="App settings" subheading="Switches for the whole app.">
    <form method="POST" action="{{ route('admin.settings.update') }}" class="admin-stack admin-settings" data-loading-form>
        @csrf
        @method('PUT')

        <section class="card">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="user-plus" /> Sign-ups</h3>
                <label class="admin-setting-row">
                    <span>
                        <strong>Allow new accounts</strong>
                        <small>When off, the sign-up page is closed and "Create one" is hidden on the login page. People who already have an account can still sign in.</small>
                    </span>
                    <span class="switch">
                        <input type="hidden" name="registration_open" value="0">
                        <input type="checkbox" name="registration_open" value="1" @checked($registrationOpen) aria-label="Allow new accounts">
                        <span class="switch-track"></span>
                    </span>
                </label>
            </div>
        </section>

        <section class="card">
            <div class="card-body">
                <h3 class="admin-section-title"><x-icon name="megaphone" /> Notice for everyone</h3>
                <p class="admin-muted">Shown at the top of the chats for every user, e.g. planned maintenance. Leave empty to hide it.</p>
                <div class="form-group mt-3">
                    <label for="app-notice" class="form-label">Notice</label>
                    <textarea id="app-notice" name="notice" class="form-control @error('notice') is-invalid @enderror" rows="3" maxlength="300" placeholder="e.g. The app will be updated tonight at 2 AM for about 10 minutes.">{{ old('notice', $notice) }}</textarea>
                    @error('notice')<p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <div><button type="submit" class="btn btn-primary"><x-icon name="check" /> Save settings</button></div>
    </form>
</x-layouts.admin>
