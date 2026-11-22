import { useForm } from '@inertiajs/react';

const labels = {
    landing_view: 'Landing response sent',
    landing_visible: 'Landing visible in browser',
    signup_cta_clicked: 'Signup link clicked',
    preview_started: 'Preview form started',
    preview_submitted: 'Preview requested',
    preview_succeeded: 'Preview displayed',
    signup_viewed: 'Signup page opened',
    signup_started: 'Signup started',
    signup_submitted: 'Signup submitted',
    signup_completed: 'Registration completed',
    email_verified: 'Email verified',
};

const groups = [
    ['Landing', ['landing_view', 'landing_visible', 'signup_cta_clicked']],
    ['Optional ad preview', ['preview_started', 'preview_submitted', 'preview_succeeded']],
    ['Registration, including direct entry', ['signup_viewed', 'signup_started', 'signup_submitted', 'signup_completed', 'email_verified']],
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

const fieldClass = 'mt-1 block w-full rounded border-gray-300 text-sm';

export default function SignupFunnel({ report }) {
    const form = useForm({
        ...report.filters,
        funnel_source: report.filters.funnel_source || '',
        funnel_campaign: report.filters.funnel_campaign || '',
        funnel_entry_surface: report.filters.funnel_entry_surface || '',
    });
    const { data, setData, errors } = form;
    const submit = (event) => {
        event.preventDefault();
        form.get(route('admin.conversions.index'), { only: ['signup_funnel', 'errors'], preserveScroll: true, preserveState: true });
    };
    return (
        <section className="rounded-lg bg-white p-6 shadow" aria-labelledby="signup-funnel-heading">
            <h3 id="signup-funnel-heading" className="text-lg font-medium text-gray-900">Site to Spend signup funnel</h3>
            <p className="mt-1 text-sm text-gray-600">Dates select when a journey first reached the site, in UTC. Later steps count through the time of this report. Source and campaign come from the entry tags. The entry destination is the first measured page in that journey.</p>
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
                        <option value="register">Direct registration</option>
                        <option value="unspecified">Unspecified destination</option>
                    </select>
                </label>
                <button type="submit" disabled={form.processing} className="self-end rounded bg-gray-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50">Apply filters</button>
                {Object.values(errors).map(error => <span role="alert" key={error} className="text-sm text-red-700">{error}</span>)}
            </form>
            <p className="mt-3 text-xs text-gray-500">
                Journey records are retained for 90 days. {report.first_observed_at ? `Earliest retained journey: ${report.first_observed_at.slice(0, 10)} UTC.` : 'No journeys have been recorded yet.'} Earlier visits cannot be reconstructed. Sessions that opt out through browser privacy controls are excluded. Blocked signals can leave gaps. A visible landing requires a browser signal from a visible tab.
            </p>
            {report.journeys === 0 ? (
                <p className="mt-5 text-sm font-medium text-gray-700" role="status">No observed journeys match these filters.</p>
            ) : (
                <>
                    <div className="mt-5 flex flex-wrap gap-x-8 gap-y-2 text-sm text-gray-700">
                        <span><strong>{report.journeys}</strong> observed journeys</span>
                        <span><strong>{report.steps.signup_completed.reached}</strong> completed registrations</span>
                        <span><strong>{report.signups_with_preview}</strong> after an observed preview</span>
                        <span><strong>{report.signups_without_observed_preview}</strong> without a prior observed preview</span>
                    </div>
                    <p className="mt-4 text-sm text-gray-600">Each journey counts once per step. The comparison shows how many journeys at the comparison step also reached the named step. A visitor can register directly or skip a browser signal.</p>
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
                </>
            )}
            <p className="mt-4 text-xs text-gray-600">Completed registrations come from Site to Spend. Google processing and paid ad attribution are unverified for this cohort. The Google upload delivery section covers all sources in its own reporting window.</p>
        </section>
    );
}
