import { useEffect, useRef, useState } from 'react';
import { fetchJson } from '@/utils/http';

export default function GoogleTagTest({ test }) {
    const merchant = useRef(null);
    const [status, setStatus] = useState('');
    useEffect(() => {
        function receive(event) {
            if (!merchant.current || event.source !== merchant.current || event.origin !== test.website_origin || !event.data) return;
            const reply = message => event.source.postMessage(message, test.website_origin);
            if (event.data.type === 'sitetospend-test-ready' && event.data.goal === test.goal) {
                reply({ type: 'sitetospend-test-context', goal: test.goal, challenge: test.challenge, send_to: test.send_to });
            }
            if (event.data.type !== 'sitetospend-test-observation') return;
            const receipt = event.data.receipt;
            if (!receipt || receipt.challenge !== test.challenge || receipt.send_to !== test.send_to || receipt.goal !== test.goal) return;
            const acknowledge = accepted => reply({ type: 'sitetospend-test-response', challenge: test.challenge, goal: test.goal, event_id: receipt.event_id, accepted });
            fetchJson(test.capture_url, { method: 'POST', json: { ...receipt, website_origin: event.origin } })
                .then(() => {
                    acknowledge(true);
                    setStatus('Browser request captured. Verification also checks your signed business event and the latest activity Google reports for this tag.');
                }).catch(() => {
                    acknowledge(false);
                    setStatus('The browser observation was declined. Check the business ID and start a fresh test.');
                });
        }
        window.addEventListener('message', receive);
        return () => { merchant.current = null; window.removeEventListener('message', receive); };
    }, [test]);
    const purchase = test.goal === 'purchase';
    const example = [
        '<script src="' + test.capture_script + '"></script>',
        '<script>',
        '// Run after server-confirmed success and measurement consent.',
        'window.SiteToSpendGoogleTest.watch({',
        '  goal: ' + JSON.stringify(test.goal) + ',',
        '  eventId: ' + (purchase ? 'String(confirmedOrder.id),' : 'String(confirmedEvent.id),'),
        ...(purchase ? ['  amount: confirmedOrder.amount, currency: confirmedOrder.currency,'] : []),
        "  consent: 'granted'",
        '}).catch(console.error);',
        '</script>',
    ].join('\n');
    return <div className="space-y-3">
        <p>Have your developer install this once on your consented confirmation page. Substitute the real event from your backend. Use its {purchase ? 'order_id' : 'source_id'} in the signed test webhook.</p>
        {purchase
            ? <p>Set the paid order’s transaction ID, value and ISO currency in the GTM <code>ecommerce</code> data layer before firing the reviewed purchase trigger.</p>
            : <p>Set <code>sitetospend.source_id</code> to the confirmed registration ID before the managed signup trigger. For an existing Google tag, set its transaction ID to the same business ID. Republish older managed signup goals to apply this mapping.</p>}
        <p>For a thank-you URL trigger, populate these fields before loading GTM on the confirmed page.</p>
        <pre className="overflow-auto rounded bg-gray-50 p-3 text-xs"><code>{example}</code></pre>
        <button type="button" className="underline" onClick={() => {
            merchant.current = window.open(test.website_origin, '_blank');
            setStatus(merchant.current ? 'Complete the business action in the new tab. Keep this verification tab open.' : 'Allow this window to open the verified advertiser website, then retry.');
        }}>Open verified website for this test</button>
        {status && <p role="status">{status}</p>}
        <p>The helper observes your existing Google request. Google reports the latest activity for the selected tag and does not identify this individual transaction in its diagnostic. A blocked or hidden request stays unverified.</p>
    </div>;
}
