{{-- Profile QR code (A4) --}}
<div class="wa-qr" id="qr-code">
    <div class="wa-qr-card">
        <x-avatar :user="$user" size="lg" class="wa-qr-avatar" />
        <div class="wa-qr-name">{{ $user->name }}</div>
        <div class="wa-qr-sub">{{ config('app.name') }} contact</div>
        <div class="wa-qr-box" data-profile-qr="{{ $qrUrl }}" role="img" aria-label="Your QR code"><span class="spinner"></span></div>
    </div>
    <p class="wa-qr-text">Your QR code is private. If you share it with someone, they can scan it with {{ config('app.name') }} to start a chat with you.</p>
</div>

<div class="wa-group">
    <button type="button" class="wa-row is-link" data-share-link="{{ $qrUrl }}" data-share-title="{{ $user->name }}" data-copy-text="{{ $qrUrl }}">
        <x-icon name="share-2" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Share link</span>
            <span class="wa-row-text wa-break" data-profile-qr-link>{{ $qrUrl }}</span>
        </span>
    </button>
    <div class="wa-row">
        <x-icon name="scan-qr-code" class="wa-row-icon" />
        <span class="wa-row-body">
            <span class="wa-row-title">Scan code</span>
            <span class="wa-row-text">In the chats, tap <strong>Menu ⋮</strong> → <strong>QR code</strong> → <strong>Scan code</strong> to open a chat from someone's code.</span>
        </span>
    </div>
    <form method="POST" action="{{ route('profile-qr.reset') }}" data-confirm="Your current QR code and link will stop working. People who already chat with you aren't affected." data-confirm-title="Reset your QR code?" data-confirm-label="Reset">
        @csrf
        <button type="submit" class="wa-row is-link is-danger">
            <x-icon name="refresh-cw" class="wa-row-icon" />
            <span class="wa-row-body"><span class="wa-row-title">Reset QR code</span></span>
        </button>
    </form>
</div>
