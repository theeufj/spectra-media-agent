/**
 * The one status pill.
 *
 * There were 168 of these across 26 shapes, five radii, padding from
 * `px-1.5 py-0.5` to `px-3 py-1.5`, sizes from `text-xs` to `text-sm`, and
 * nine separate status→colour maps. The nine agreed on the hues and disagreed
 * on the shades, which is how `text-yellow-700` on `yellow-100` — 4.58:1, close
 * enough to the 4.5 line that any future shade nudge breaks it — ended up
 * shipping beside `text-green-800` on `green-100` at 6.49:1.
 *
 * So: one shape, one tone table, every pair measured. Pinning the foreground at
 * the -800 shade buys real headroom rather than sitting on the threshold:
 *
 *   good   green-800 on green-100    6.49:1
 *   warn   yellow-800 on yellow-100  6.38:1
 *   bad    red-800 on red-100        6.80:1
 *   busy   blue-800 on blue-100      7.15:1
 *   idle   gray-700 on gray-100      9.37:1
 *
 * The pill always carries its label as text, so state never rides on hue alone
 * — which matters here more than most places, since these five tones are the
 * only thing distinguishing "deployed" from "failed" at a glance.
 *
 * `Components/GTM/GTMStatusBadge.jsx` was already this pattern and is what this
 * generalises.
 */

/** The five tones. Nothing outside this table may colour a pill. */
export const TONES = {
    good: 'bg-green-100 text-green-800',
    warn: 'bg-yellow-100 text-yellow-800',
    bad: 'bg-red-100 text-red-800',
    busy: 'bg-blue-100 text-blue-800',
    idle: 'bg-gray-100 text-gray-700',
};

/**
 * The status strings the API emits today, mapped to a tone. Keyed lowercase and
 * looked up lowercased, because the same value arrives as `in_progress` from
 * one controller and `In Progress` from another.
 *
 * Not exhaustive — new statuses appear whenever a job does. An unrecognised one
 * falls back to `idle` rather than guessing a happy tone, so the failure mode
 * is "grey, go look" rather than "green, all fine". Add yours here when you
 * meet it; that is cheaper than the alternative, which is a tenth local map.
 */
export const STATUS_TONES = {
    // Reached the intended end state.
    active: 'good',
    approved: 'good',
    completed: 'good',
    connected: 'good',
    deployed: 'good',
    enabled: 'good',
    healthy: 'good',
    live: 'good',
    passed: 'good',
    resolved: 'good',
    sent: 'good',
    succeeded: 'good',
    success: 'good',
    uploaded: 'good',
    uploaded_all: 'good',
    verified: 'good',
    actioned: 'good',
    already_deployed: 'good',
    tracking_configured: 'good',

    // Needs a human, or is in a state that will not resolve itself.
    attention: 'warn',
    deploy_unverified: 'warn',
    degraded: 'warn',
    expiring: 'warn',
    high: 'warn',
    limited: 'warn',
    no_data: 'warn',
    open: 'warn',
    pending: 'warn',
    review_required: 'warn',
    suspended: 'warn',
    unverified: 'warn',
    warning: 'warn',

    // Broken.
    bad: 'bad',
    cancelled: 'bad',
    canceled: 'bad',
    critical: 'bad',
    declined: 'bad',
    disapproved: 'bad',
    error: 'bad',
    failed: 'bad',
    rejected: 'bad',
    unhealthy: 'bad',
    urgent: 'bad',
    import_failed: 'bad',
    tracking_failed: 'bad',

    // Work in flight — the page will change on its own.
    deploying: 'busy',
    generating: 'busy',
    in_progress: 'busy',
    in_review: 'busy',
    processing: 'busy',
    queued: 'busy',
    running: 'busy',
    scheduled: 'busy',
    syncing: 'busy',
    uploading: 'busy',
    import_submitted: 'busy',

    // Deliberately not doing anything.
    archived: 'idle',
    closed: 'idle',
    disabled: 'idle',
    disconnected: 'idle',
    dismissed: 'idle',
    draft: 'idle',
    expired: 'idle',
    inactive: 'idle',
    low: 'idle',
    not_detected: 'idle',
    paused: 'idle',
    removed: 'idle',
    skipped: 'idle',
    skipped_plan: 'idle',
    unknown: 'idle',
    created: 'idle',
    import_skipped: 'idle',
    tracking_skipped: 'idle',
    not_required: 'idle',
    no_changes: 'idle',
};

/**
 * Labels for the statuses whose raw value does not humanise into English.
 * Everything else is derived, so this stays short by design.
 */
export const STATUS_LABELS = {
    deploy_unverified: 'Not Verified',
    no_data: 'No Data',
    not_detected: 'Not Detected',
    skipped_plan: 'Not On Your Plan',
    uploaded_all: 'Uploaded',
    uploaded_google: 'Uploaded — Google',
    uploaded_facebook: 'Uploaded — Facebook',
};

function normalise(status) {
    return typeof status === 'string' ? status.trim().toLowerCase().replace(/[\s-]+/g, '_') : '';
}

/** Own-property lookup only — a status called "constructor" must miss, not return a function. */
function lookup(table, key) {
    return Object.prototype.hasOwnProperty.call(table, key) ? table[key] : undefined;
}

/** The tone a status resolves to, for the rare caller that needs the colour without the pill. */
export function toneFor(status) {
    const key = normalise(status);
    const known = lookup(STATUS_TONES, key);
    if (known) return known;

    // The one guess worth making. New `*_failed` / `*_error` statuses arrive
    // with every job that gains an error path, and greeting them with the same
    // calm grey as "skipped" is the failure this whole component exists to stop.
    if (/fail|error/.test(key)) return 'bad';

    return 'idle';
}

function labelFor(status) {
    const key = normalise(status);
    const known = lookup(STATUS_LABELS, key);
    if (known) return known;

    return key
        .split('_')
        .filter(Boolean)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
}

/**
 * @param {object} props
 * @param {string} [props.status] Raw value from the API, any casing.
 * @param {'good'|'warn'|'bad'|'busy'|'idle'} [props.tone] Override when the
 *   status word means something different in your context — `paused` is idle on
 *   a campaign list but a problem on a billing page.
 * @param {React.ReactNode} [props.label] Override the derived text.
 * @param {React.ReactNode} [props.icon] Leading glyph, for the pages that
 *   already pair one with the status.
 * @param {string} [props.className] Layout only — margins, alignment. The pill's
 *   own shape and colour are not overridable; that is the point of the component.
 */
export default function StatusBadge({ status, tone, label, icon, className = '' }) {
    const text = label ?? (status ? labelFor(status) : null);

    // A pill with no words is a coloured dot with no meaning; render nothing
    // rather than an empty chip where a status was expected.
    if (text == null || text === '') return null;

    const toneClass = lookup(TONES, tone) ?? TONES[toneFor(status)];

    return (
        <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium ${toneClass} ${className}`}>
            {icon}
            {text}
        </span>
    );
}
