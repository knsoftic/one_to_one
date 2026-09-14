{{-- Chats: theme --}}
@php($themes = ['system' => 'System default', 'light' => 'Light', 'dark' => 'Dark'])

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
    <p class="wa-group-note">"System default" follows your phone or computer's light or dark mode.</p>
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
