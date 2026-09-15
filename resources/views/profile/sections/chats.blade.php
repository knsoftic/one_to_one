{{-- Chats: theme, wallpaper and font size --}}
@php($themes = ['system' => 'System default', 'light' => 'Light', 'dark' => 'Dark'])
@php($fontSizes = ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'])
@php($wallpaper = \App\Services\WallpaperService::payload($user->wallpaper, $user->wallpaper_path, 'settings.wallpaper.show') + ['dim' => (int) $user->wallpaper_dim])

<div class="wa-group">
    <h3 class="wa-group-title">Display</h3>
    <label class="wa-row wa-choice">
        <x-icon name="palette" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Theme</span>
            <span class="wa-row-text" data-choice-label>{{ $themes[$user->theme] ?? 'System default' }}</span>
        </span>
        <select class="wa-choice-select" data-theme-select aria-label="Theme">
            @foreach ($themes as $value => $label)
                <option value="{{ $value }}" @selected($user->theme === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <button type="button" class="wa-row is-link" data-wallpaper-open data-wallpaper-current='@json($wallpaper)'>
        <x-icon name="wallpaper" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Wallpaper</span>
            <span class="wa-row-text" data-wallpaper-label>{{ $wallpaper['key'] === 'custom' ? 'Your photo' : ucfirst($wallpaper['key']) }}</span>
        </span>
        <span class="wa-wallpaper-thumb chat-wallpaper" data-wallpaper-thumb aria-hidden="true"></span>
    </button>
    <p class="wa-group-note">"System default" follows your phone or computer's light or dark mode. A chat can have its own wallpaper: open the chat menu → Wallpaper.</p>
</div>

<div class="wa-group">
    <h3 class="wa-group-title">Chat text</h3>
    <label class="wa-row wa-choice">
        <x-icon name="a-large-small" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Font size</span>
            <span class="wa-row-text" data-choice-label>{{ $fontSizes[$user->font_size] ?? 'Medium' }}</span>
        </span>
        <select class="wa-choice-select" data-preference="font_size" aria-label="Font size">
            @foreach ($fontSizes as $value => $label)
                <option value="{{ $value }}" @selected(($user->font_size ?? 'medium') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
    <p class="wa-group-note wa-font-sample" data-font-sample>Messages look like this. Pick the size that is easiest to read.</p>
</div>

<div class="wa-group">
    <h3 class="wa-group-title">Chat settings</h3>
    <a href="{{ route('chat.index') }}" class="wa-row is-link">
        <x-icon name="archive" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Archived, pinned and locked chats</span>
            <span class="wa-row-text">Long-press or right-click a chat in the list to archive, pin, mute or lock it</span>
        </span>
        <x-icon name="chevron-right" class="wa-row-chevron" />
    </a>
</div>
