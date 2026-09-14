{{-- Blocked contacts (P5) --}}
<div class="wa-group" data-blocked-list>
    @if ($blockCandidates->isNotEmpty() && Route::has('blocks.store'))
        <details class="wa-block-picker">
            <summary class="wa-row is-link">
                <span class="wa-row-avatar-icon"><x-icon name="user-x" /></span>
                <span class="wa-row-body"><span class="wa-row-title">Block someone</span></span>
            </summary>
            <div class="wa-block-picker-panel">
                <div class="input-wrap">
                    <x-icon name="search" />
                    <input type="search" class="form-control" placeholder="Search people you chat with" aria-label="Search people you chat with" data-block-search>
                </div>
                <div class="wa-block-picker-list">
                    @foreach ($blockCandidates as $candidate)
                        <div class="wa-row" data-block-candidate data-name="{{ mb_strtolower(($savedNames[$candidate->id] ?? $candidate->name).' '.$candidate->username) }}">
                            <x-avatar :user="$candidate" size="md" />
                            <span class="wa-row-body">
                                <span class="wa-row-title">{{ $savedNames[$candidate->id] ?? $candidate->name }}</span>
                                <span class="wa-row-text">{{ '@'.$candidate->username }}</span>
                            </span>
                            <form method="POST" action="{{ route('blocks.store', $candidate) }}" data-confirm="Block {{ $savedNames[$candidate->id] ?? $candidate->name }}? They won't be able to call you or send you messages.">
                                @csrf
                                <button type="submit" class="btn btn-danger-soft btn-sm"><x-icon name="ban" /> Block</button>
                            </form>
                        </div>
                    @endforeach
                </div>
            </div>
        </details>
    @endif

    @forelse ($blockedUsers as $blocked)
        <div class="wa-row" data-blocked-row="{{ $blocked->id }}">
            <x-avatar :user="$blocked" size="md" />
            <span class="wa-row-body">
                <span class="wa-row-title">{{ $savedNames[$blocked->id] ?? $blocked->name }}</span>
                <span class="wa-row-text">{{ '@'.$blocked->username }} · blocked {{ \Illuminate\Support\Carbon::parse($blocked->pivot->created_at)->diffForHumans() }}</span>
            </span>
            @if (Route::has('blocks.destroy'))
                <form method="POST" action="{{ route('blocks.destroy', $blocked) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-secondary btn-sm">Unblock</button>
                </form>
            @endif
        </div>
    @empty
        <div class="empty-state">
            <div class="empty-state-icon"><x-icon name="shield-check" /></div>
            <div class="empty-state-title">No blocked contacts</div>
            <div class="empty-state-text">People you block will appear here. You can also block someone from their chat.</div>
        </div>
    @endforelse

    <p class="wa-group-note">Blocked contacts can't call you or send you messages, and don't see your last seen, online, profile photo, About or status. Existing messages stay visible.</p>
</div>
