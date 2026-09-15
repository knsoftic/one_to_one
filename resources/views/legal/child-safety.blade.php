<x-layouts.legal :title="$title" :page="$page" :pages="$pages" :updated="$updated">
    <p>{{ $owner }} has zero tolerance for child sexual abuse and exploitation (CSAE) on {{ $app }}, including child sexual abuse material (CSAM), grooming, sextortion and any content that sexualises minors.</p>

    <h2>Our standards</h2>
    <ul>
        <li>Creating, sharing, requesting or storing CSAM, or contacting minors for sexual purposes, is forbidden by our <a href="{{ route('legal', 'terms') }}">terms</a>.</li>
        <li>Accounts are for people aged 13 and over. We delete accounts we learn belong to younger children.</li>
        <li>Accounts involved in CSAE are banned permanently, their content is removed, and the evidence is kept only as long as needed to report it to the authorities.</li>
    </ul>

    <h2>How to report</h2>
    <ul>
        <li><strong>In the app</strong>: open the chat or the person's contact info → <em>Report</em>. You can include the recent messages and block the person at the same time.</li>
        <li><strong>By email</strong>: <a href="mailto:{{ $email }}">{{ $email }}</a> — our child safety contact. Please don't attach illegal images; describe what happened and the username or number.</li>
        <li>If a child is in immediate danger, contact your local police first.</li>
    </ul>

    <h2>What we do</h2>
    <ul>
        <li>Reports about children are reviewed first. Administrators can open the reported chat (every access is logged), remove the content and ban the account.</li>
        <li>We report apparent CSAM to the relevant authorities in {{ $country }} and to the National Center for Missing &amp; Exploited Children (NCMEC) where applicable, and cooperate with lawful requests.</li>
        <li>Administrators follow these standards whenever they review a report.</li>
    </ul>

    <h2>Contact</h2>
    <p>Child safety point of contact: {{ $owner }} · <a href="mailto:{{ $email }}">{{ $email }}</a></p>
</x-layouts.legal>
