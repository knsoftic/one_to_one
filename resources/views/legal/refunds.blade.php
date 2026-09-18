<x-layouts.legal :title="$title" :page="$page" :pages="$pages" :updated="$updated">
    <p>{{ $app }} sells coins (used to promote your status, channel, business or a link inside the app), paid plans and a verified badge. This page explains when a payment is refunded.</p>

    <h2>Google Play purchases (Android app)</h2>
    <p>Purchases made inside the Android app go through Google Play. Refunds for those purchases are handled by Google under the <a href="https://support.google.com/googleplay/answer/2479637" target="_blank" rel="noopener">Google Play refund policy</a>. When Google refunds a purchase, the coins are removed from your wallet and any plan bought with it ends.</p>

    <h2>Website purchases</h2>
    <ul>
        <li><strong>Coins</strong> are delivered instantly and are not refundable once used. Unused coins from a payment made by mistake can be refunded within 7 days — email us with the payment number shown in Settings → Wallet.</li>
        <li><strong>Plans</strong> can be refunded within 7 days of the payment if no benefit has been used in a way that cannot be undone (for example, promotions run with the plan's free coins). Otherwise the plan runs until its end date and is not renewed automatically.</li>
        <li><strong>Manual transfers</strong> (JazzCash, EasyPaisa, bank) that are not approved are never charged: nothing is taken from your wallet and no plan starts. Money sent to a wrong account cannot be recovered by us.</li>
    </ul>

    <h2>Promotions</h2>
    <p>Coins spent on a promotion are held while it is reviewed. A promotion we do not approve is refunded in full. A promotion you stop early is refunded for the views that were not delivered. Coins for delivered views are not refunded.</p>

    <h2>Earned coins</h2>
    <p>Coins earned through Refer &amp; earn can be spent in the app. Withdrawing them for money is not available yet; when it is, the terms will be shown here first.</p>

    <h2>How to ask</h2>
    <p>Email <a href="mailto:{{ $email }}?subject=Refund%20request">{{ $email }}</a> from the email address on your account, or include the mobile number of the account and the payment number. We answer within 5 working days.</p>
</x-layouts.legal>
