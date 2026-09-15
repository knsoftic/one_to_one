<div class="admin-stack">
    <section class="card">
        <div class="card-header admin-card-head">
            <div>
                <h2 class="card-title">Saved contacts</h2>
                <p class="card-subtitle">{{ number_format($contacts->total()) }} people from their phone book who use the app.</p>
            </div>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Saved as</th>
                        <th class="hidden md:table-cell">Number</th>
                        <th>Account</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($contacts as $contact)
                        <tr>
                            <td class="font-semibold">{{ $contact->name ?: '—' }}</td>
                            <td class="hidden md:table-cell tabular-nums text-sm text-muted">{{ $contact->phone ?: '—' }}</td>
                            <td>
                                @if ($contact->contactUser)
                                    <a href="{{ route('admin.users.show', $contact->contactUser) }}" class="admin-person">
                                        <x-avatar :user="$contact->contactUser" size="sm" status />
                                        <span class="min-w-0">
                                            <span class="admin-person-name">{{ $contact->contactUser->name }}</span>
                                            <span class="admin-person-meta">{{ '@'.$contact->contactUser->username }}</span>
                                        </span>
                                    </a>
                                @else
                                    <span class="text-muted text-sm">Deleted account</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><div class="empty-state"><div class="empty-state-icon"><x-icon name="contact" /></div><div class="empty-state-title">No saved contacts</div></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-admin.pager :items="$contacts" label="contacts" />
    </section>

    <div class="admin-grid admin-grid-even">
        @foreach ([['They blocked', $blocked, 'ban', 'Nobody blocked'], ['Blocked by', $blockers, 'shield', 'Nobody has blocked them']] as [$title, $people, $icon, $empty])
            <section class="card">
                <div class="card-body">
                    <h3 class="admin-section-title"><x-icon :name="$icon" /> {{ $title }} <span class="badge badge-muted">{{ $people->count() }}</span></h3>
                    @forelse ($people as $person)
                        <a href="{{ route('admin.users.show', $person) }}" class="admin-list-row is-compact">
                            <x-avatar :user="$person" size="sm" />
                            <span class="admin-list-body">
                                <span class="admin-list-title">{{ $person->name }}</span>
                                <span class="admin-list-text">{{ '@'.$person->username }}@if ($person->pivot?->created_at) · {{ \Illuminate\Support\Carbon::parse($person->pivot->created_at)->format('j M Y') }}@endif</span>
                            </span>
                        </a>
                    @empty
                        <p class="admin-muted">{{ $empty }}.</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</div>
