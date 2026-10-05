import { Link } from '@inertiajs/react';

const holds = {
    campaign_not_active: 'Automatic repairs are on hold while the campaign is inactive or paused.',
    google_campaign_paused: 'Automatic repairs are on hold while the Google campaign is paused.',
    google_campaign_not_enabled: 'Google has not enabled this campaign. Automatic repairs are on hold.',
    setup_only: 'Automatic repairs are on hold for your one-time setup campaign. Your ads remain paused until you enable them.',
    ad_spend_unfunded: 'Automatic repairs are on hold because ad spend funding is unavailable or paused.',
    auto_healing_disabled: 'Automatic repairs have been turned off for this business.',
    campaign_ended: 'Automatic repairs are on hold because the campaign has ended.',
    strategy_not_approved: 'Approve this strategy before automatic repairs can run.',
    sandbox: 'Live Google checks do not run for demo campaigns.',
    google_disabled: 'Google checks are temporarily disabled.',
    missing_google_account: 'The Google account is not ready for checks yet.',
    google_management_unavailable: 'Google account access is unavailable. Contact support to restore access.',
    already_checking: 'Another Google check is in progress.',
    customer_inactive: 'Checks are on hold while this business is inactive.',
};

/** A later launch check must be able to invalidate an earlier green aggregate. */
export function resolveGoogleReadiness(readiness, conversionGoals) {
    if (!conversionGoals?.status) return readiness || null;
    const aggregateAt = Date.parse(readiness?.checked_at || '');
    const goalsAt = Date.parse(conversionGoals.checked_at || '');
    const newer = !readiness || Number.isNaN(aggregateAt) || Number.isNaN(goalsAt) || goalsAt >= aggregateAt;
    if (!newer) return readiness;
    const state = { status: 'pending', ready: false, ...readiness, conversion_goals: conversionGoals };
    if (conversionGoals.checked_at) state.checked_at = conversionGoals.checked_at;
    if (conversionGoals.status !== 'ready' || !conversionGoals.ready) {
        const oldGoalCodes = new Set((readiness?.conversion_goals?.issues || []).map(issue => issue.code));
        state.issues = [...(readiness?.issues || []).filter(issue => !oldGoalCodes.has(issue.code)), ...(conversionGoals.issues || [])];
        state.ready = false;
        state.status = conversionGoals.status === 'unknown' ? 'unknown' : 'needs_review';
    }
    return state;
}

export function googleReadinessStatus(readiness, conversionGoals) {
    const state = resolveGoogleReadiness(readiness, conversionGoals);
    if (!state) return 'pending';
    if (state.status === 'unknown' || state.errors?.length) return 'unknown';
    const issues = state.issues || [];
    const strength = state.ad_strength || {};
    const verified = state.status === 'ready' && state.ready && !issues.length
        && state.conversion_goals?.status === 'ready' && state.conversion_goals?.ready
        && (strength.status === 'not_applicable' || strength.checked && strength.verified && !strength.skipped);
    if (verified) return 'verified';
    if (issues.length && issues.every(issue => /strength.*(pending|review_pending)|strength_google_review_or_strength_pending/i.test(issue.code || ''))) return 'pending';
    if (issues.length || state.status === 'needs_review' || state.conversion_goals?.status === 'needs_review') return 'needs_review';
    if (state.skip_reason) return 'on_hold';
    return 'pending';
}

function checkedTime(value) {
    if (!value || Number.isNaN(Date.parse(value))) return null;
    return <time dateTime={value}>{new Date(value).toLocaleString()}</time>;
}

export default function GoogleReadinessCard({ readiness, conversionGoals, admin = false }) {
    const state = resolveGoogleReadiness(readiness, conversionGoals);
    const status = googleReadinessStatus(readiness, conversionGoals);
    const goals = state?.conversion_goals;
    const strength = state?.ad_strength;
    const issues = state?.issues || [];
    const awaitingReview = issues.some(issue => /strength.*pending/i.test(issue.code || ''));
    const labels = { verified: 'Verified', pending: awaitingReview ? 'Awaiting Google review' : 'Checks pending', needs_review: 'Needs attention', unknown: 'Unknown', on_hold: 'Checks on hold' };
    const goalLabel = goals?.status === 'ready' && goals.ready ? 'Verified' : goals?.status === 'needs_review' ? 'Needs review' : goals?.status === 'unknown' ? 'Unknown' : 'Not checked';
    const strengthLabel = strength?.status === 'not_applicable' ? 'Not applicable to this campaign type' : strength?.checked && strength.verified && !strength.skipped ? 'Verified'
        : strength?.errors?.length ? 'Unknown' : strength?.skipped ? 'Check on hold' : awaitingReview ? 'Awaiting Google review' : strength?.unresolved?.length ? 'Needs review' : 'Not confirmed';
    const goalName = { SIGNUP: 'Sign-up', PURCHASE: 'Purchase', SUBMIT_LEAD_FORM: 'Lead form' }[goals?.intent?.category];
    return <section aria-label="Google campaign readiness" className={`mt-4 rounded-lg border p-4 ${status === 'verified' ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50'}`}>
        <div className="flex flex-wrap items-start justify-between gap-2"><h3 className="font-semibold text-gray-900">Google campaign readiness</h3><span className={`rounded-full px-2 py-1 text-xs font-semibold ${status === 'verified' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900'}`}>{labels[status]}</span></div>
        <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2"><div><dt className="text-gray-600">Conversion goal{goalName ? ` · ${goalName}` : ''}</dt><dd className="font-medium text-gray-900">{goalLabel}</dd></div><div><dt className="text-gray-600">Ad strength</dt><dd className="font-medium text-gray-900">{strengthLabel}</dd></div></dl>
        {status === 'verified' && <p className="mt-3 text-sm text-gray-700">The last check confirmed the applicable conversion goal and ad strength checks. Delivery still depends on approval, billing and pause settings.</p>}
        {status === 'pending' && <p className="mt-3 text-sm text-gray-700">{awaitingReview ? 'Google has not confirmed the ad review or strength rating yet. The next check will update this result.' : 'Readiness checks have not completed yet. Created ads can still be waiting for Google approval or a confirmed strength rating.'}</p>}
        {status === 'unknown' && <p className="mt-3 text-sm text-amber-950">The current readiness check is unavailable. This does not confirm that the campaign is ready. Last reported issues remain visible until a successful check resolves them.</p>}
        {state?.skip_reason && <p className="mt-3 text-sm text-gray-700">{holds[state.skip_reason] || 'Automatic repairs are on hold. Pause and budget settings have been preserved.'}</p>}
        {issues.length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-800">{issues.map((issue, index) => <li key={`${issue.code}-${index}`}>{issue.message || 'A Google readiness issue needs review.'}</li>)}</ul>}
        <p className="mt-3 text-xs text-gray-600">{checkedTime(state?.checked_at) ? <>Last readiness check: {checkedTime(state.checked_at)}</> : 'No completed readiness check is available yet.'}</p>
        {strength?.last_known_checked_at && <p className="mt-1 text-xs text-gray-600">Last ad strength evidence: {checkedTime(strength.last_known_checked_at)}</p>}
        {admin && state?.campaign_resource && <details className="mt-3 text-xs text-gray-600"><summary className="cursor-pointer">Google campaign identifier</summary><p className="mt-1 break-all font-mono">{state.campaign_resource}</p></details>}
        {!admin && ['needs_review', 'unknown'].includes(status) && <Link href={route('support-tickets.create')} className="mt-3 inline-flex min-h-[44px] items-center text-sm font-semibold text-brand-dark underline">Get help with readiness</Link>}
    </section>;
}
