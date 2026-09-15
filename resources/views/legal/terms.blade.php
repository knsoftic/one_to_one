<x-layouts.legal :title="$title" :page="$page" :pages="$pages" :updated="$updated">
    <p>These terms apply when you use {{ $app }}, run by {{ $owner }}. By creating an account you agree to them and to the <a href="{{ route('legal', 'privacy') }}">privacy policy</a>.</p>

    <h2>Your account</h2>
    <ul>
        <li>You must be at least 13 years old, and old enough to agree to these terms where you live.</li>
        <li>Use your own mobile number and keep your password, chat lock code and two-step PIN to yourself. You are responsible for what happens in your account.</li>
        <li>One person, one account. Don't pretend to be someone else.</li>
    </ul>

    <h2>What is not allowed</h2>
    <ul>
        <li>Sexual content involving children, grooming or anything that sexualises minors — zero tolerance, see <a href="{{ route('legal', 'child-safety') }}">child safety standards</a>.</li>
        <li>Harassment, threats, hate, and sharing someone's private images or information without permission.</li>
        <li>Spam, scams, bulk or automated messages, and messages to people who don't want them.</li>
        <li>Illegal content or activity, malware, and trying to break into accounts or the service.</li>
        <li>Copying or reselling the service, or getting around bans and limits.</li>
    </ul>

    <h2>Moderation</h2>
    <p>People can report accounts from the app. To keep the service safe, administrators can review reported chats (this is logged), remove content, and warn, suspend or ban accounts for a time or permanently. We may also report illegal content to the authorities.</p>

    <h2>Your content</h2>
    <p>What you send stays yours. You give us permission to store, copy and deliver it only so the service works (for example to deliver messages, show them on your devices and make backups you ask for). Only send things you have the right to share.</p>

    <h2>The service</h2>
    <ul>
        <li>We work to keep the app running, but it is provided “as is”: messages or calls may sometimes be delayed or fail, and features may change.</li>
        <li>The app is not a replacement for emergency calls.</li>
        <li>Mobile data, SMS and call charges from your network provider are your own.</li>
    </ul>

    <h2>Ending your use</h2>
    <p>You can delete your account at any time (<a href="{{ route('legal', 'delete-account') }}">how</a>). We may close accounts that break these terms or stay unused for a long time after telling you in the app when possible.</p>

    <h2>Liability</h2>
    <p>As far as the law allows, {{ $owner }} is not responsible for indirect losses, lost data or what other people send you. Nothing in these terms limits rights you have under the law of {{ $country }} that cannot be limited.</p>

    <h2>Changes and contact</h2>
    <p>We may update these terms; the date at the top shows the latest version and important changes are announced in the app. Questions: <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>
</x-layouts.legal>
