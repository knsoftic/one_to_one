<x-layouts.legal :title="$title" :page="$page" :pages="$pages" :updated="$updated">
    <p>{{ $app }} (“the app”, at <a href="{{ $url }}">{{ $url }}</a> and in the Android app) is run by {{ $owner }}. This policy explains what we store, why, who can see it and how long we keep it. Questions: <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>

    <h2>What we store</h2>
    <ul>
        <li><strong>Your account</strong>: name, mobile number, username, password (stored only as a secure hash), and if you add them, email address, profile photo and About text.</li>
        <li><strong>Your messages and files</strong>: text, photos, videos, voice messages, documents, stickers, polls, contact cards and locations you send, and status updates you post. They are stored on our server so they reach the other people and so your chats come back when you sign in on a new device.</li>
        <li><strong>Phone contacts</strong> (only if you allow it in the Android app): the phone numbers in your phone book are compared with registered accounts to show who uses the app. Only the matches are saved to your account; numbers of people who don't use the app are not kept.</li>
        <li><strong>Calls</strong>: who called whom, when and for how long. The sound and video of calls go directly between the phones or through a relay server and are never recorded.</li>
        <li><strong>Location</strong>: only when you choose to share your location in a chat.</li>
        <li><strong>Devices and security</strong>: sign-in times and method, IP address, browser or phone model and app version, notification tokens (Firebase or browser push), and the devices you trust for two-step verification.</li>
        <li><strong>Settings</strong>: privacy choices, blocked people, muted, pinned, archived and locked chats, wallpapers, tones and similar preferences.</li>
        <li><strong>Reports</strong>: when you report someone, the report and the recent messages you include.</li>
    </ul>

    <h2>Why we use it</h2>
    <ul>
        <li>To deliver messages, calls and notifications and to show your chats on every device you sign in on.</li>
        <li>To keep accounts safe: verification codes, two-step verification, sign-in history and signing out lost devices.</li>
        <li>To stop abuse: spam, harassment, illegal content and people who break the <a href="{{ route('legal', 'terms') }}">terms</a>.</li>
        <li>To run and improve the service (counts such as the number of messages per day). We don't sell your information and we don't show ads.</li>
    </ul>

    <h2>Who can see it</h2>
    <ul>
        <li><strong>The people you chat with</strong> see what you send them. Your privacy settings decide who sees your last seen, profile photo, About and status updates.</li>
        <li><strong>Our administrators</strong>: messages are protected in transit (HTTPS) but they are <strong>not end-to-end encrypted</strong>. To investigate reports, abuse and legal requests, administrators can open chats. Every time they do, it is written to an audit log.</li>
        <li><strong>Service providers</strong> that run parts of the app for us: our hosting provider; Google Firebase Cloud Messaging and browser push services (notifications); the SMS provider (verification codes); the email provider; and, only when you use them, Tenor by Google (GIF search) and the websites you link to (link previews).</li>
        <li><strong>Authorities</strong>, when the law requires it, and to protect people from serious harm (for example child sexual abuse material, see <a href="{{ route('legal', 'child-safety') }}">child safety standards</a>).</li>
    </ul>

    <h2>How long we keep it</h2>
    <ul>
        <li>Messages and files stay until they are deleted: "Delete for everyone", clearing or deleting chats, disappearing messages (after the time chosen), view once media (after it is opened) or deleting your account. When nobody can see a file any more, it is removed.</li>
        <li>Status updates: 24 hours. Chat backups you make: 7 days. Sign-in history: 180 days.</li>
        <li>When you delete your account, your profile, chats, messages, files, status updates and settings are deleted. Messages you sent in groups of other people are removed too. Server backups made for disaster recovery may keep copies until they are replaced or deleted.</li>
    </ul>

    <h2>Your choices</h2>
    <ul>
        <li>Change what others see in Settings → Privacy; block or report people.</li>
        <li>Download your data in Settings → Account → “Download my data”, or a chat backup in Settings → Storage and data.</li>
        <li>Turn off contact access, notifications, camera, microphone or location in your phone's settings at any time.</li>
        <li>Delete your account: see <a href="{{ route('legal', 'delete-account') }}">Delete your account</a>.</li>
    </ul>

    <h2>Security</h2>
    <p>All connections use HTTPS. Passwords, chat lock codes and two-step PINs are stored as hashes, and saved keys are encrypted. No system is perfectly secure; tell us at <a href="mailto:{{ $email }}">{{ $email }}</a> if you find a problem.</p>

    <h2>Children</h2>
    <p>The app is not meant for children under 13 and we don't knowingly let them sign up. If you believe a child under 13 has an account, contact us and we will delete it.</p>

    <h2>Changes</h2>
    <p>We will update this page when our practices change and show the new date at the top. Important changes are announced in the app.</p>

    <h2>Contact</h2>
    <p>{{ $owner }}, {{ $country }} · <a href="mailto:{{ $email }}">{{ $email }}</a></p>
</x-layouts.legal>
