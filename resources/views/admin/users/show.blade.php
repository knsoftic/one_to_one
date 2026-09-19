<x-layouts.admin :title="$user->name" :heading="$user->name" :subheading="'@'.$user->username.' · #'.$user->id.' · joined '.$user->created_at->format('j M Y')" :back="route('admin.users')">
    @php
        $canManage = auth()->user()->can('manage', $user);
        $canRole = auth()->user()->can('changeRole', $user);
        $bytes = function (?int $value): string {
            if (! $value) {
                return '0 B';
            }
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = min((int) floor(log($value, 1024)), count($units) - 1);

            return round($value / (1024 ** $i), $i === 0 ? 0 : 1).' '.$units[$i];
        };
    @endphp

    <nav class="admin-tabs admin-user-tabs" aria-label="Account sections">
        @foreach ($tabs as $key => [$label, $icon])
            <a href="{{ route('admin.users.show', ['user' => $user, 'tab' => $key === 'overview' ? null : $key]) }}" @class(['admin-tab', 'is-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>
                <x-icon :name="$icon" /> {{ $label }}
            </a>
        @endforeach
    </nav>

    @if ($tab === 'overview')
        @include('admin.users.tabs.overview')
    @else
        <div class="admin-user-strip card">
            <x-avatar :user="$user" size="md" status />
            <span class="min-w-0">
                <span class="admin-person-name">{{ $user->name }} <x-verified-badge :user="$user" /></span>
                <span class="admin-person-meta">{{ $user->phone }} @if ($user->email)· {{ $user->email }}@endif</span>
            </span>
            <x-admin.status-badge :user="$user" />
            <span class="admin-user-strip-actions"><x-admin.user-actions :user="$user" /></span>
        </div>
        @include('admin.users.tabs.'.$tab)
    @endif
</x-layouts.admin>
