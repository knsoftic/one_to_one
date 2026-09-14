{{-- Profile: photo, name, About, username, email, phone (like WhatsApp's profile screen) --}}
<form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data" class="wa-profile" data-loading-form data-profile-form novalidate>
    @csrf
    @method('PUT')

    <div class="wa-profile-photo" data-avatar-picker>
        <label for="profile-image-input" @class(['wa-profile-avatar', 'has-image' => $user->avatar_url]) data-avatar-preview>
            <img src="{{ $user->avatar_url }}" alt="" @unless ($user->avatar_url) hidden @endunless data-avatar-image>
            <span class="wa-profile-initials" data-avatar-placeholder @if ($user->avatar_url) hidden @endif>{{ $user->initials }}</span>
        </label>
        <label for="profile-image-input" class="wa-profile-camera" title="Change profile photo">
            <x-icon name="camera" />
            <span class="sr-only">Change profile photo</span>
        </label>
        <input id="profile-image-input" type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" class="sr-only" data-avatar-input>
        <input type="hidden" name="remove_profile_image" value="0" data-avatar-remove>
        <div class="wa-profile-photo-actions">
            <label for="profile-image-input" class="wa-link">Edit</label>
            <button type="button" class="wa-link is-danger" data-avatar-clear @unless ($user->avatar_url) hidden @endunless>Remove photo</button>
        </div>
        <span class="wa-note">JPG, PNG or WEBP · max {{ (int) (config('chat.uploads.avatar.max_kb') / 1024) }} MB</span>
        @error('profile_image', 'profile')
            <p class="form-error"><x-icon name="circle-alert" />{{ $message }}</p>
        @enderror
    </div>

    <div class="wa-group">
        @foreach ([
            ['name' => 'name', 'label' => 'Name', 'icon' => 'user-round', 'value' => $user->name, 'attrs' => ['maxlength' => 100, 'autocomplete' => 'name', 'required' => true], 'hint' => 'This name is shown to people you chat with.'],
            ['name' => 'about', 'label' => 'About', 'icon' => 'info', 'value' => $user->about, 'attrs' => ['maxlength' => 139, 'placeholder' => 'Hey there! I am using '.config('app.name').'.']],
            ['name' => 'username', 'label' => 'Username', 'icon' => 'at-sign', 'value' => $user->username, 'attrs' => ['maxlength' => 30, 'autocomplete' => 'username', 'autocapitalize' => 'none', 'required' => true]],
            ['name' => 'email', 'label' => 'Email', 'icon' => 'mail', 'value' => $user->email, 'type' => 'email', 'attrs' => ['maxlength' => 191, 'autocomplete' => 'email', 'required' => true]],
        ] as $field)
            @php($invalid = $errors->getBag('profile')->has($field['name']))
            <label class="wa-field" for="profile-{{ $field['name'] }}">
                <x-icon :name="$field['icon']" class="wa-field-icon" />
                <span class="wa-field-body">
                    <span class="wa-field-label">{{ $field['label'] }}</span>
                    <input id="profile-{{ $field['name'] }}" name="{{ $field['name'] }}" type="{{ $field['type'] ?? 'text' }}"
                           value="{{ old($field['name'], $field['value']) }}" @class(['wa-field-input', 'is-invalid' => $invalid])
                           @foreach ($field['attrs'] as $attr => $attrValue) {!! $attrValue === true ? e($attr) : e($attr).'="'.e($attrValue).'"' !!} @endforeach
                           data-profile-input>
                    @if ($invalid)
                        <span class="form-error"><x-icon name="circle-alert" />{{ $errors->getBag('profile')->first($field['name']) }}</span>
                    @elseif (isset($field['hint']))
                        <span class="wa-field-hint">{{ $field['hint'] }}</span>
                    @endif
                </span>
                <x-icon name="pencil" class="wa-field-edit" />
            </label>
        @endforeach

        {{-- Changing the email asks for the password (shown once the email is edited). --}}
        @php($passwordInvalid = $errors->getBag('profile')->has('current_password'))
        <label class="wa-field" for="profile-current-password" data-email-password @unless ($passwordInvalid) hidden @endunless>
            <x-icon name="lock" class="wa-field-icon" />
            <span class="wa-field-body">
                <span class="wa-field-label">Current password</span>
                <input id="profile-current-password" name="current_password" type="password" autocomplete="current-password" @class(['wa-field-input', 'is-invalid' => $passwordInvalid]) data-profile-input>
                @if ($passwordInvalid)
                    <span class="form-error"><x-icon name="circle-alert" />{{ $errors->getBag('profile')->first('current_password') }}</span>
                @else
                    <span class="wa-field-hint">Needed to change your email.</span>
                @endif
            </span>
        </label>

        <button type="button" class="wa-field" data-settings-open="account">
            <x-icon name="phone" class="wa-field-icon" />
            <span class="wa-field-body">
                <span class="wa-field-label">Phone</span>
                <span class="wa-field-value">{{ $user->phone }}</span>
            </span>
            <span class="wa-field-action">Change</span>
        </button>
    </div>

    <div class="wa-save-bar" data-profile-save>
        <button type="submit" class="btn btn-primary"><x-icon name="check" /> Save changes</button>
    </div>
</form>
