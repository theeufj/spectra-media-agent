import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { fetchJson } from '@/utils/http';
import GoogleTagTest from './GoogleTagTest';

export default function OutcomeEvidence({ auth, campaign, strategy, evidence, setupUrl, testUrl }) {
    const [setup, setSetup] = useState(null);
    const [test, setTest] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    async function request(url, setter) {
        setBusy(true);
        setError('');
        try {
            setter(await fetchJson(url, { method: 'POST', json: {} }));
        } catch (failure) {
            setError(failure.body?.message || 'The verification request failed. Check your destination and try again.');
        } finally {
            setBusy(false);
        }
    }
    return <AuthenticatedLayout user={auth?.user}>
        <Head title="Test conversion tracking" />
        <main className="mx-auto max-w-3xl space-y-6 px-4 py-8">
            <Link href={route('campaigns.show', campaign.uuid)} className="text-brand-dark underline">Back to campaign</Link>
            <h1 className="text-2xl font-semibold">Test {strategy.platform} conversion tracking</h1>
            <p>{evidence.verified_for_launch ? 'The tracking requirement is satisfied for this goal.' : 'Complete the selected website action and verify delivery before this campaign can spend.'}</p>
            <p>Goal <strong>{evidence.goal || 'Choose a goal'}</strong> · Verified business events <strong>{evidence.verified_events}</strong> · Browser observations <strong>{evidence.browser_events}</strong></p>
            <p>Website ownership <strong>{evidence.website_origin_verified ? 'Verified' : 'Awaiting verification'}</strong></p>
            {setup?.website_file && <section className="space-y-2 rounded-lg border p-4"><h2 className="font-semibold">Verify this website</h2><p>Publish a plain-text file at this exact URL. Paste the line below into the file, then start the test.</p><code className="block break-all">{setup.website_file.url}</code><code className="block break-all">{setup.website_file.contents}</code></section>}
            <details className="rounded-lg border p-4"><summary className="cursor-pointer font-semibold">Connect your website backend</summary>
                <p className="my-3">Your developer should call this endpoint after a successful registration or paid order, using a stable business event ID. Keep the signing key on your server.</p>
                <button type="button" disabled={busy} className="underline" onClick={() => request(setupUrl, setSetup)}>Show my integration details</button>
                {setup && <div className="mt-3 space-y-2">
                    <code className="block break-all">{setup.endpoint}</code><code className="block break-all">{setup.signing_secret}</code>
                    <p>POST JSON with source_id, goal, mode, occurred_at, source_url and ad_user_data. For a purchase, send its amount, currency and a stable order_id. The source_id is used as the order ID if you omit order_id. For a refund, send goal refund, mode live, its unique refund ID as source_id, the original order_id, and the positive amount and currency. Refunds update your order reporting. Use granted, denied or unknown for consent. For Google imports, supply the captured gclid, gbraid or wbraid when available.</p>
                    {setup.binding?.channel === 'facebook' && <p>With permission to share measurement data, send the visitor’s browser <code>client_user_agent</code> from the original website request. Include either <code>meta_email_sha256</code> (lowercase and trim the email before hashing), <code>meta_external_id_sha256</code> (hash your stable customer ID), or the visitor’s captured <code>fbc</code> cookie. Hash on your server using SHA-256 and send lowercase hexadecimal. You can test before anyone clicks an ad.</p>}
                    <p>Send the current Unix timestamp in X-Outcome-Timestamp. Set X-Outcome-Signature to the lowercase hex HMAC-SHA256 of the timestamp, a period, and the exact request body, using your signing key.</p>
                </div>}
            </details>
            <button type="button" disabled={busy || evidence.binding_status !== 'configured'} className="rounded bg-brand-primary px-4 py-2 font-semibold text-white" onClick={() => request(testUrl, setTest)}>Start an exact-event test</button>
            {test?.verified && <p role="status">A genuine Site to Spend business receipt passed verification for this advertising destination. Refresh to view its status.</p>}
            {test && !test.verified && <div className="space-y-2 rounded-lg border p-4">
                <p>Run the business action on your website before {new Date(test.expires_at).toLocaleTimeString()}. Send mode <code>test</code> and this challenge with the signed event.</p>
                <code className="block break-all">{test.challenge}</code>
                {test.send_to && test.capture_url ? <GoogleTagTest test={test} />
                    : test.channel === 'google' ? <p>Google click imports require a genuine Google Ads click and a matching live event that Google has processed. For a new advertiser, select a website conversion action in the campaign’s Google conversion goal, publish its reviewed success tag, then start a fresh test.</p>
                    : <p>For Meta, use Events Manager → dataset <code>{test.destination}</code> → Test Events. Copy its code into <code>test_code</code>, then complete a real signup or purchase in a consenting browser session and send the matching fields from backend setup.</p>}
            </div>}
            {error && <p role="alert">{error}</p>}
            <Link href={route('campaigns.outcome.show', { campaign: campaign.uuid, strategy: strategy.uuid })} className="block underline">Refresh verification status</Link>
        </main>
    </AuthenticatedLayout>;
}
