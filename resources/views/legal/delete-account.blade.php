<x-layouts.legal :title="$title" :page="$page" :pages="$pages" :updated="$updated">
    <p>You can delete your {{ $app }} account yourself at any time, from the Android app or the website. You don't need to reinstall anything.</p>

    <h2>Delete it in the app</h2>
    <ol>
        <li>Open {{ $app }} and sign in (<a href="{{ route('login') }}">sign in on the website</a>).</li>
        <li>Go to <strong>Settings → Account → Delete my account</strong>.</li>
        <li>Confirm with your password. The account is deleted straight away.</li>
    </ol>
    @auth
        <p><a class="btn btn-primary" href="{{ route('profile.edit', ['tab' => 'account']) }}">Open Settings → Account</a></p>
    @endauth
    <p>Want a copy first? Use <strong>Settings → Account → Download my data</strong> or <strong>Settings → Storage and data → Chat backup</strong> before deleting.</p>

    <h2>Can't sign in?</h2>
    <p>Email <a href="mailto:{{ $email }}?subject=Delete%20my%20account">{{ $email }}</a> from the email address on the account, or include the mobile number of the account. We will confirm the request with a code sent to that number or email, then delete the account within 30 days.</p>

    <h2>What is deleted</h2>
    <ul>
        <li>Your profile (name, username, number, email, photo, About), settings, contacts, blocked list and devices.</li>
        <li>Your one-to-one chats with all their messages and files, your messages in groups, your status updates, stickers, channels you created and broadcast lists.</li>
        <li>You leave your groups and communities; they stay for the other people (another member becomes admin).</li>
    </ul>

    <h2>What may remain</h2>
    <ul>
        <li>Copies other people saved themselves (for example a chat export or screenshot they made).</li>
        <li>Reports about abuse and the administrator audit log, kept as long as needed for safety and legal reasons.</li>
        <li>Server backups for disaster recovery, until they are replaced or deleted by an administrator.</li>
    </ul>
</x-layouts.legal>
