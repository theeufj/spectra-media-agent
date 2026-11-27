import { Link, useForm } from '@inertiajs/react';

const labels = {
    landing_view: 'Landing response sent',
    landing_visible: 'Landing visible in browser',
    signup_cta_clicked: 'Signup link clicked',
    preview_started: 'Preview form started',
    preview_submitted: 'Preview requested',
    preview_succeeded: 'Ad preview displayed',
    save_clicked: 'Save ad draft clicked',
    paid_subscription: 'First paid subscription',
    signup_viewed: 'Signup page opened',
    signup_started: 'Signup started',
    signup_submitted: 'Signup submitted',
    signup_completed: 'Registration completed',
    signup_saved_draft: 'Draft retained at registration',
    email_verified: 'Email verified',
};

const groups = [
    ['Landing', ['landing_view', 'landing_visible', 'signup_cta_clicked']],
    ['Optional ad preview', ['preview_started', 'preview_submitted', 'preview_succeeded', 'save_clicked']],
    ['Registration, including direct entry', ['signup_viewed', 'signup_started', 'signup_submitted', 'signup_completed', 'signup_saved_draft', 'email_verified', 'paid_subscription']],
];

const reasons = {
    validation: 'Validation',
    rate_limited: 'Rate limit',
    timeout: 'Timeout',
    network: 'Network',
    unavailable: 'Service unavailable',
    oauth_cancelled: 'Provider sign-in cancelled',
    unknown: 'Unspecified',
    email_taken: 'Email already registered',
    captcha: 'Verification challenge',
};

const checks = {
    preview_succeeded: 'Compare preview starts, requests and failures below to see whether visitors submitted a website and received a draft.',
    save_clicked: 'Compare displayed drafts with Save clicks. Visitors can also continue through direct registration.',
    signup_completed: 'Check signup submissions, validation failures and provider cancellations after visitors click Save.',
    paid_subscription: 'Compare signup dates and the pricing shown before checkout. Recent signups still have time to subscribe.',
};

const fieldClass = 'mt-1 block w-full rounded border-gray-300 text-sm';

export default function SignupFunnel({ report }) {
    const form = useForm({
        ...report.filters,
        funnel_source: report.filters.funnel_source || '',
        funnel_campaign: report.filters.funnel_campaign || '',
        funnel_entry_surface: report.filters.funnel_entry_surface || '',
    });
    const { data, setData, errors } = form;
    const largestDrop = Object.entries(report.preview_path ?? {}).reduce((largest, [stage, row]) =>
        row.missing > (largest?.missing ?? 0) ? { stage, ...row } : largest, null);
    const submit = (event) => {
        event.preventDefault();
        form.get(route('admin.conversions.index'), { only: ['signup_funnel', 'errors'], preserveScroll: true, preserveState: true });
    };
    return (
        <section id="signup-funnel" className="scroll-mt-20 rounded-lg bg-white p-4 shadow sm:p-6" aria-labelledby="signup-funnel-heading">
            <h3 id="signup-funnel-heading" className="text-lg font-medium text-gray-900">Site to Spend visitor-to-payment funnel</h3>
            <p className="mt-1 text-sm text-gray-600">Filter by the date a session first reached the site (UTC), its entry tags and first measured page. A later signup or first paid subscription counts through {report.as_of.slice(0, 10)} UTC, even if the entry window ended earlier.</p>
            <details className="mt-3" id="signup-funnel-filters">
                <summary className="cursor-pointer text-sm font-medium text-gray-800">Filter sessions · {report.filters.funnel_from} to {report.filters.funnel_to} UTC</summary>
                <form onSubmit={submit} className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <label className="text-sm">Entry date from (UTC)
                    <input type="date" required className={fieldClass} value={data.funnel_from} max={data.funnel_to} onChange={e => setData('funnel_from', e.target.value)} />
                </label>
                <label className="text-sm">Entry date through (UTC)
                    <input type="date" required className={fieldClass} value={data.funnel_to} min={data.funnel_from} max={report.as_of.slice(0, 10)} onChange={e => setData('funnel_to', e.target.value)} />
                </label>
                <label className="text-sm">Entry source
                    <select className={fieldClass} value={data.funnel_source} onChange={e => setData('funnel_source', e.target.value)}>
                        <option value="">All sources</option><option value="google">Google</option><option value="meta">Meta</option><option value="unattributed">Unattributed</option>
                    </select>
                </label>
                <label className="text-sm">Campaign
                    <select className={fieldClass} value={data.funnel_campaign} onChange={e => setData('funnel_campaign', e.target.value)}>
                        <option value="">All campaigns</option>
                        {report.campaigns.map(key => <option key={key} value={key}>{key}</option>)}
                    </select>
                </label>
                <label className="text-sm">Entry destination
                    <select className={fieldClass} value={data.funnel_entry_surface} onChange={e => setData('funnel_entry_surface', e.target.value)}>
                        <option value="">All destinations</option>
                        <option value="home_preview">Homepage with ad preview</option>
                        <option value="google_ads_service">Google Ads service page</option>
                        <option value="pricing">Pricing</option>
                        <option value="other_marketing">Other marketing pages</option>
                        <option value="guides">Blog and guides</option>
                        <option value="register">Direct registration</option>
                        <option value="unspecified">Unspecified destination</option>
                    </select>
                </label>
                <button type="submit" disabled={form.processing} className="self-end rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">Apply filters</button>
                {Object.values(errors).map(error => <span role="alert" key={error} className="text-sm text-red-700">{error}</span>)}
                </form>
            </details>
            {report.journeys === 0 ? (
                <p className="mt-5 text-sm font-medium text-gray-700" role="status">No observed journeys match these filters.</p>
            ) : (
                <>
                    <h4 className="mt-6 font-semibold text-gray-900">Ad preview to first paid subscription</h4>
                    <p className="mt-1 text-sm text-gray-600">A session advances when each step happens in order. Saving means clicking “Save this ad draft”. Payment requires a confirmed subscription payment.</p>
                    <div className="mt-3 overflow-x-auto">
                        <table aria-label="Ad preview conversion funnel" className="w-full text-left text-sm">
                            <thead><tr className="border-b text-gray-600">
                                <th className="py-2 pr-1 sm:pr-3">Step</th><th className="px-1 sm:px-3">Sessions</th><th className="hidden px-3 sm:table-cell">Previous step</th><th className="px-1 sm:px-3">Did not advance</th>
                            </tr></thead>
                            <tbody>{Object.entries(report.preview_path).map(([key, row]) => (
                                <tr key={key} className="border-b border-gray-100">
                                    <th scope="row" className="py-2 pr-1 font-medium sm:pr-3">{key === 'landing_visible' ? 'Visible measured sessions' : labels[key]}</th>
                                    <td className="whitespace-nowrap px-1 tabular-nums sm:px-3">{row.reached}{row.previous !== null && <span className="text-gray-600 sm:hidden"> of {row.previous}</span>}</td>
                                    <td className="hidden px-3 tabular-nums sm:table-cell">{row.previous ?? '—'}</td>
                                    <td className="px-1 tabular-nums sm:px-3">{row.missing ?? '—'}{row.dropoff_percent !== null && ` (${row.dropoff_percent}%)`}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                    {largestDrop && <p className="mt-4 border-l-2 border-gray-300 pl-3 text-sm text-gray-700">
                        <strong>{labels[largestDrop.stage]}</strong> had the largest drop by session count. Only {largestDrop.reached} of {largestDrop.previous} reached this step. {checks[largestDrop.stage]}
                    </p>}
                    <h4 className="mt-5 font-semibold text-gray-900">Other registration paths</h4>
                    <p className="mt-1 text-sm text-gray-700">Outside the observed preview-and-save sequence, we recorded <strong>{report.other_paths.registrations}</strong> completed {report.other_paths.registrations === 1 ? 'registration' : 'registrations'} and <strong>{report.other_paths.paid}</strong> first {report.other_paths.paid === 1 ? 'payment' : 'payments'}. This includes skipped steps and missing browser signals.</p>
                    <p className="mt-2 text-sm text-gray-700">Across all <strong>{report.journeys}</strong> measured sessions, <strong>{report.steps.signup_completed.reached}</strong> registered and <strong>{report.steps.paid_subscription.both}</strong> made a first subscription payment.</p>
                    <p className="mt-2 text-sm text-gray-700">The registration page was the entry point for <strong>{report.direct_entry.journeys}</strong> {report.direct_entry.journeys === 1 ? 'session' : 'sessions'}. Of those, <strong>{report.direct_entry.registrations}</strong> registered and <strong>{report.direct_entry.paid}</strong> paid. {' '}
                        <Link className="font-medium text-blue-700 underline" href={route('admin.conversions.index', { ...report.filters, funnel_entry_surface: 'register' })} preserveScroll only={['signup_funnel', 'errors']}>View direct-entry cohort</Link>
                    </p>
                    <details id="signup-funnel-diagnostics" className="mt-6">
                        <summary className="cursor-pointer font-medium text-gray-900">Step diagnostics and observed failures</summary>
                        <p className="mt-2 text-sm text-gray-600">Each count uses distinct sessions. The comparison counts sessions that reached those two steps in order.</p>
                    <div className="mt-3 overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead><tr className="border-b text-gray-600">
                                <th className="py-2 pr-3">Step</th><th className="px-3">Journeys</th><th className="px-3">Comparison step</th><th className="px-3">Reached both</th><th className="px-3">Still missing step</th>
                            </tr></thead>
                            {groups.map(([name, keys]) => <tbody key={name}>
                                <tr className="bg-gray-50"><th scope="rowgroup" colSpan="5" className="py-2 pl-2 text-gray-700">{name}</th></tr>
                                {keys.map(key => {
                                    const step = report.steps[key];
                                    return <tr key={key} className="border-b border-gray-100 text-gray-800">
                                        <th scope="row" className="py-2 pr-3 font-normal">{labels[key]}</th>
                                        <td className="px-3 tabular-nums">{step.reached}</td>
                                        <td className="px-3">{labels[step.baseline] || 'All observed journeys'}</td>
                                        <td className="px-3 tabular-nums">{step.both} / {step.baseline_count}</td>
                                        <td className="px-3 tabular-nums">{step.dropoff_percent === null ? '—' : `${step.missing} (${step.dropoff_percent}%)`}</td>
                                    </tr>;
                                })}
                            </tbody>)}
                        </table>
                    </div>
                    {report.failures.length > 0 && <div className="mt-4 text-sm">
                        <h4 className="font-medium text-gray-900">Journeys by first observed failure at each step</h4>
                        <ul className="mt-1 list-disc pl-5 text-gray-700">{report.failures.map(row => <li key={row.stage + row.reason}>
                            {row.stage === 'preview_failed' ? 'Preview' : 'Signup'} · {reasons[row.reason] || 'Unspecified'} · {row.journeys} {row.journeys === 1 ? 'journey' : 'journeys'}
                        </li>)}</ul>
                    </div>}
                    </details>
                </>
            )}
            <p className="mt-3 text-xs text-gray-500">
                Journey records are retained for 90 days. {report.first_observed_at ? `Earliest retained journey: ${report.first_observed_at.slice(0, 10)} UTC.` : 'No journeys have been recorded yet.'} Earlier visits cannot be reconstructed. Sessions that opt out through browser privacy controls are excluded. Blocked signals can leave gaps. A visible landing requires a browser signal from a visible tab.
            </p>
            <p className="mt-4 text-xs text-gray-600">Registrations and payments come from Site to Spend. Google processing and paid ad attribution are unverified for this cohort. The Google upload delivery section has its own reporting window.</p>
        </section>
    );
}
