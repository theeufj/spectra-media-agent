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
    approved_bounded_trial: 'Automatic changes are on hold to preserve the approved trial; Google checks still run.',
};

// These are observations, not failures. A timeout or disapproval must never be
// treated as pending merely because its code contains the word "pending".
const evaluationPendingCodes = new Set([
    'ad_strength_google_review_or_strength_pending',
    'ad_strength_rating_pending',
    'ad_strength_review_pending',
    'ad_strength_strength_pending',
    'ad_strength_verification_pending',
]);
const evaluationPendingReasons = new Set(['google_review_or_strength_pending', 'rating_pending', 'review_pending', 'strength_pending']);
const isEvaluationPendingIssue = issue => evaluationPendingCodes.has(issue?.code);
const hasExplicitPending = state => Object.prototype.hasOwnProperty.call(state || {}, 'pending');
const isLegacyPendingIssue = (state, issue) => !hasExplicitPending(state) && isEvaluationPendingIssue(issue);

function readinessIssues(state) {
    const issues = [...(state?.issues || []), ...(state?.conversion_goals?.issues || []),
        ...(state?.audience_observation?.issues || []), ...(state?.ad_strength?.errors || [])];
    return issues.filter((issue, index) => issues.findIndex(other => other.code === issue.code && other.message === issue.message) === index);
}

function awaitingGoogleEvaluation(state) {
    const strength = state?.ad_strength;
    if (!strength?.checked || strength.verified || strength.skipped || strength.errors?.length) return false;
    if (hasExplicitPending(state)) {
        // Modern snapshots classify real repair failures separately even when
        // their reason/code contains "pending". Trust the explicit evidence.
        return state.pending?.some(isEvaluationPendingIssue) || strength.unresolved?.some(item => item.waiting === true) || false;
    }
    return (state?.issues || []).some(isEvaluationPendingIssue)
        || strength.unresolved?.some(item => evaluationPendingReasons.has(item.reason)) || false;
}

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
    const audiences = state.audience_observation;
    const strength = state.ad_strength || {};
    if (state.status === 'unknown' || state.errors?.length || state.conversion_goals?.status === 'unknown'
        || audiences?.status === 'unknown' || strength.errors?.length) return 'unknown';
    const issues = readinessIssues(state);
    const realIssues = issues.filter(issue => !isLegacyPendingIssue(state, issue));
    const evaluationPending = awaitingGoogleEvaluation(state);
    if (realIssues.length || state.conversion_goals?.status === 'needs_review' || audiences?.status === 'needs_review') return 'needs_review';
    if (state.skip_reason && state.skip_reason !== 'approved_bounded_trial') return 'on_hold';
    if (state.status === 'needs_review' && (hasExplicitPending(state) || !evaluationPending)) return 'needs_review';
    const verified = state.status === 'ready' && state.ready && !issues.length && !state.pending?.length && !evaluationPending
        && state.conversion_goals?.status === 'ready' && state.conversion_goals?.ready
        && (strength.status === 'not_applicable' || strength.checked && strength.verified && !strength.skipped)
        && (audiences?.status === 'not_applicable' || audiences?.status === 'ready' && audiences.ready);
    if (verified) return 'verified';
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
    const audiences = state?.audience_observation;
    const issues = readinessIssues(state).filter(issue => !isLegacyPendingIssue(state, issue));
    const pending = [...(state?.pending || []), ...readinessIssues(state).filter(issue => isLegacyPendingIssue(state, issue))];
    const pendingMessages = pending.filter((issue, index) => pending.findIndex(other => other.code === issue.code && other.message === issue.message) === index);
    const awaitingReview = awaitingGoogleEvaluation(state);
    const labels = { verified: 'Verified', pending: awaitingReview ? 'Waiting for Google evaluation' : 'Checks pending', needs_review: 'Needs attention', unknown: 'Unknown', on_hold: 'Checks on hold' };
    const goalLabel = goals?.status === 'ready' && goals.ready ? 'Verified' : goals?.status === 'needs_review' ? 'Needs review' : goals?.status === 'unknown' ? 'Unknown' : 'Not checked';
    const strengthLabel = strength?.status === 'not_applicable' ? 'Not applicable to this campaign type' : strength?.checked && strength.verified && !strength.skipped ? 'Verified'
        : strength?.errors?.length ? 'Unknown' : strength?.skipped ? 'Check on hold' : awaitingReview ? 'Waiting for Google evaluation' : strength?.unresolved?.length ? 'Needs review' : 'Not confirmed';
    const audienceLabel = audiences?.status === 'not_applicable' ? 'Not applicable to this strategy' : audiences?.status === 'ready' && audiences.ready ? 'Verified'
        : audiences?.status === 'unknown' ? 'Unknown' : audiences?.status === 'needs_review' ? 'Needs review' : 'Not checked';
    const goalName = { SIGNUP: 'Sign-up', PURCHASE: 'Purchase', SUBMIT_LEAD_FORM: 'Lead form' }[goals?.intent?.category];
    return <section aria-label="Google campaign readiness" className={`mt-4 rounded-lg border p-4 ${status === 'verified' ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50'}`}>
        <div className="flex flex-wrap items-start justify-between gap-2"><h3 className="font-semibold text-gray-900">Google campaign readiness</h3><span className={`rounded-full px-2 py-1 text-xs font-semibold ${status === 'verified' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-900'}`}>{labels[status]}</span></div>
        <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-3"><div><dt className="text-gray-600">Conversion goal{goalName ? ` · ${goalName}` : ''}</dt><dd className="font-medium text-gray-900">{goalLabel}</dd></div><div><dt className="text-gray-600">Ad strength</dt><dd className="font-medium text-gray-900">{strengthLabel}</dd></div><div><dt className="text-gray-600">Audience settings</dt><dd className="font-medium text-gray-900">{audienceLabel}</dd></div></dl>
        {status === 'verified' && <p className="mt-3 text-sm text-gray-700">The last check confirmed the applicable conversion goal, ad strength and audience settings. Delivery still depends on approval, billing and pause settings.</p>}
        {status === 'pending' && <p className="mt-3 text-sm text-gray-700">{awaitingReview ? 'Google has not finished evaluating the ad review or strength rating. This is a waiting state; the campaign is not fully verified yet. Automatic checks will continue, and a prolonged wait will be flagged for review.' : 'One or more applicable campaign checks are still waiting for confirmation. The next check will update the results above.'}</p>}
        {status === 'unknown' && <p className="mt-3 text-sm text-amber-950">The current readiness check is unavailable. This does not confirm that the campaign is ready. Last reported issues remain visible until a successful check resolves them.</p>}
        {state?.skip_reason && <p className="mt-3 text-sm text-gray-700">{holds[state.skip_reason] || 'Automatic repairs are on hold. Pause and budget settings have been preserved.'}</p>}
        {issues.length > 0 && <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-800">{issues.map((issue, index) => <li key={`${issue.code}-${index}`}>{issue.message || 'A Google readiness issue needs review.'}</li>)}</ul>}
        {pendingMessages.length > 0 && <ul aria-label="Pending Google evaluation" className="mt-3 list-disc space-y-1 pl-5 text-sm text-gray-700">{pendingMessages.map((issue, index) => <li key={`${issue.code}-${index}`}>{issue.message}</li>)}</ul>}
        {awaitingReview && checkedTime(strength?.pending_since) && <p className="mt-2 text-xs text-gray-600">Waiting since: {checkedTime(strength.pending_since)}</p>}
        {awaitingReview && checkedTime(strength?.expires_at) && <p className="mt-1 text-xs text-gray-600">Flag for review if still waiting at: {checkedTime(strength.expires_at)}</p>}
        <p className="mt-3 text-xs text-gray-600">{checkedTime(state?.checked_at) ? <>Last readiness check: {checkedTime(state.checked_at)}</> : 'No completed readiness check is available yet.'}</p>
        {strength?.last_known_checked_at && <p className="mt-1 text-xs text-gray-600">Last ad strength evidence: {checkedTime(strength.last_known_checked_at)}</p>}
        {audiences?.last_known_checked_at && <p className="mt-1 text-xs text-gray-600">Last audience settings evidence: {checkedTime(audiences.last_known_checked_at)}</p>}
        {admin && state?.campaign_resource && <details className="mt-3 text-xs text-gray-600"><summary className="cursor-pointer">Google campaign identifier</summary><p className="mt-1 break-all font-mono">{state.campaign_resource}</p></details>}
        {!admin && ['needs_review', 'unknown'].includes(status) && <Link href={route('support-tickets.create')} className="mt-3 inline-flex min-h-[44px] items-center text-sm font-semibold text-brand-dark underline">Get help with readiness</Link>}
    </section>;
}
