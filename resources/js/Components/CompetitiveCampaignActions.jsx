import { useEffect, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { usePolling } from '@/hooks/usePolling';

const labels = {
    pending: 'Awaiting review', approved: 'Queued', applying: 'Applying change', applied: 'Applied · verifying',
    verified: 'Verified · measuring results', measured: 'Results available', failed: 'Needs attention',
    needs_verification: 'Checking interrupted change', rejected: 'Dismissed',
};
const types = { BUDGET: 'Daily budget', BIDDING: 'Keyword bid', NETWORK_SETTINGS: 'Search network settings',
    COMPETITOR_KEYWORD_TEST: 'Keyword test', COMPETITOR_AD_TEST: 'Ad variation test' };
const working = data => ['queued', 'running'].includes(data?.review?.status)
    || data?.actions?.some(action => ['approved', 'applying', 'applied', 'needs_verification'].includes(action.status));

export default function CompetitiveCampaignActions({ initial = { actions: [], review: null } }) {
    const [state, setState] = useState(initial || { actions: [], review: null });
    const [busy, setBusy] = useState(null);
    const [timedOut, setTimedOut] = useState(false);
    const isWorking = working(state);
    useEffect(() => {
        if (!isWorking) { setTimedOut(false); return; }
        const timer = setTimeout(() => setTimedOut(true), 15 * 60 * 1000);
        return () => clearTimeout(timer);
    }, [isWorking]);
    useEffect(() => setState(initial || { actions: [], review: null }), [initial]);
    const { data, failureStreak } = usePolling(route('seo.competitors.actions'), {
        enabled: isWorking && !timedOut, interval: 10000, until: value => !working(value),
        parse: value => { if (!Array.isArray(value?.actions)) throw new Error('Invalid action status'); return value; },
    });
    useEffect(() => { if (data) setState(data); }, [data]);
    const submit = (url, id) => {
        setBusy(id);
        router.post(url, {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    };
    const review = state.review;
    return <section className="rounded-lg border border-gray-200 bg-white p-6 space-y-5" aria-label="Campaign changes from competitor research">
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div><h2 className="text-lg font-semibold text-gray-900">What changed in your campaigns</h2>
                <p className="mt-1 text-sm text-gray-600">See the evidence, review proposed tests, and track verified changes and results.</p></div>
            <button type="button" disabled={busy === 'review' || ['queued', 'running'].includes(review?.status)}
                onClick={() => submit(route('seo.competitors.review-campaigns'), 'review')}
                className="rounded-lg bg-brand-dark px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                {['queued', 'running'].includes(review?.status) ? 'Reviewing campaigns…' : 'Review current campaigns'}
            </button>
        </div>
        <p className="text-sm text-gray-500">Keyword and ad-variation tests require your approval and use the existing campaign budget. Supported adjustments can apply automatically when performance evidence meets the confidence threshold.</p>
        {(failureStreak >= 3 || timedOut) && <p role="alert" className="text-amber-800">Status updates are unavailable. Refresh this page to check the latest result.</p>}
        {review?.status === 'completed' && <p role="status" className="text-sm text-gray-600">{review.reviewed === 0
            ? 'No serving campaigns are ready for review yet. Newly deployed campaigns must begin serving first.'
            : `Reviewed ${review.reviewed} campaign(s); ${review.proposed} new action(s). A review can find that no change is warranted.`}</p>}
        {['failed', 'partial'].includes(review?.status) && <p role="alert" className="text-amber-800">Some campaigns could not be reviewed. Existing actions are retained; you can review again.</p>}
        {!state.actions?.length && <p className="text-sm text-gray-500">No campaign changes recorded from this report yet. Report suggestions become changes only when they appear here as applied and verified.</p>}
        <div className="space-y-4">{state.actions?.map(action => <article key={action.id} className="rounded-lg border border-gray-200 p-4 space-y-3">
            <div className="flex flex-wrap justify-between gap-2"><div>
                <h3 className="font-semibold text-gray-900">{types[action.type] || action.type.replaceAll('_', ' ')}</h3>
                <Link className="text-sm text-brand-dark underline" href={route('campaigns.show', { campaign: action.campaign_uuid })}>{action.campaign_name}</Link>
            </div><span className="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium self-start">{labels[action.status] || action.status}</span></div>
            <p className="text-sm text-gray-700">{action.rationale}</p>
            {action.change?.keywords && <p className="text-sm">Exact-match keywords: {action.change.keywords.join(', ')}</p>}
            {action.change?.headlines && <div className="rounded bg-gray-50 p-3 text-sm"><p className="font-medium">Proposed ad variation</p>
                <p>{action.change.headlines.join(' · ')}</p>{action.change.descriptions?.map((text, i) => <p className="mt-1" key={i}>{text}</p>)}
                <p className="mt-2 text-gray-500">Runs alongside your existing ads. Delivery is not a randomized split test.</p></div>}
            {action.type === 'BUDGET' && <p className="text-sm">Proposed daily budget: {action.change?.suggested_value} {action.currency || 'in the campaign currency'}.</p>}
            {action.type === 'BIDDING' && <p className="text-sm">Proposed keyword bid: {Number(action.change?.suggested_value) / 1000000} {action.currency || 'in the campaign currency'}.</p>}
            {action.type === 'NETWORK_SETTINGS' && <p className="text-sm">Turn off Search Partners and Display expansion; retain Google Search.</p>}
            {action.evidence?.length > 0 && <p className="text-xs text-gray-500">Based on: {action.evidence.map(source => `${source.domain || (source.kind === 'war_room_gap_analysis' ? 'Competitor gap analysis' : source.kind === 'competitive_strategy' ? 'Competitive strategy' : 'Auction evidence')} (${new Date(source.observed_at).toLocaleDateString()})`).join('; ')}</p>}
            {action.message && <p className="text-sm text-amber-800">{action.message}</p>}
            {action.verified_at && <p className="text-xs text-green-800">Platform state verified {new Date(action.verified_at).toLocaleString()}. Ad approval and delivery are separate.</p>}
            {action.status === 'verified' && <p className="text-xs text-gray-500">Results will be compared after seven complete days.</p>}
            {action.outcome && <div className="rounded bg-gray-50 p-3 text-sm"><p>{action.outcome.summary}</p>
                <table className="mt-2 w-full text-left"><thead><tr><th>Metric</th><th>Before<br /><span className="font-normal text-xs">{action.outcome.before?.from} – {action.outcome.before?.to}</span></th><th>After<br /><span className="font-normal text-xs">{action.outcome.after?.from} – {action.outcome.after?.to}</span></th></tr></thead><tbody>
                    {['impressions', 'clicks', 'cost', 'conversions'].map(metric => <tr key={metric}><th className="font-normal capitalize">{metric === 'cost' ? `Spend (${action.currency || 'campaign currency'})` : metric}</th><td>{action.outcome.before?.[metric] ?? '—'}</td><td>{action.outcome.after?.[metric] ?? '—'}</td></tr>)}
                </tbody></table></div>}
            {action.status === 'pending' && <div className="flex gap-3">
                {action.can_apply && <button type="button" disabled={busy === action.id} onClick={() => submit(route('strategy.war-room.recommendations.approve', action.id), action.id)} className="rounded bg-brand-dark px-3 py-2 text-sm text-white disabled:opacity-50">Approve change</button>}
                <button type="button" disabled={busy === action.id} onClick={() => submit(route('strategy.war-room.recommendations.reject', action.id), action.id)} className="rounded border px-3 py-2 text-sm disabled:opacity-50">Dismiss</button>
            </div>}
        </article>)}</div>
    </section>;
}
