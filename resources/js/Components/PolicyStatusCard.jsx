import { Link } from '@inertiajs/react';

const PLATFORM_NAMES = { google_ads: 'Google Ads', facebook_ads: 'Facebook Ads' };

function checkedTime(value) {
    if (!value || Number.isNaN(new Date(value).getTime())) return null;
    return <time dateTime={value}>{new Date(value).toLocaleString()}</time>;
}

function DestinationUrl({ value }) {
    if (!value) return null;
    const safe = /^https?:\/\//i.test(value);
    return safe
        ? <a href={value} target="_blank" rel="noopener noreferrer" className="break-all underline">{value}<span className="sr-only"> (opens in a new tab)</span></a>
        : <span className="break-all">{value}</span>;
}

function PolicyIssue({ issue, admin }) {
    const topics = issue.policy_topics || [];
    const evidence = topics.flatMap(topic => topic.evidences || []);
    const destinations = evidence.filter(item => item.type === 'destination_not_working');
    const urls = [...new Set([...(issue.final_urls || []), ...destinations.map(item => item.expanded_url)].filter(Boolean))];
    const destinationIssue = issue.destination_issue || destinations.length > 0 || topics.some(topic => /destination/i.test(topic.topic || ''));
    const topicNames = topics.map(topic => (topic.topic || '').replaceAll('_', ' ').toLowerCase()).filter(Boolean);

    return <li className="rounded-lg border border-amber-200 bg-white p-4">
        <h3 className="font-semibold text-gray-900">{PLATFORM_NAMES[issue.platform] || 'Ad platform'} · {destinationIssue ? 'Destination not working' : 'Ad approval issue'}</h3>
        {topicNames.length > 0 && <p className="mt-1 text-sm text-gray-700">Policy: {topicNames.join(', ')}</p>}
        {issue.message && <p className="mt-2 text-sm text-gray-700">{issue.message}</p>}
        {urls.length > 0 && <div className="mt-3 text-sm text-gray-700"><p className="font-medium">Affected destination</p><ul className="mt-1 space-y-1">{urls.map(url => <li key={url}><DestinationUrl value={url} /></li>)}</ul></div>}
        {destinations.map((item, index) => <dl key={index} className="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
            {item.http_error_code != null && <div><dt className="text-gray-500">HTTP error</dt><dd className="font-semibold text-red-800">HTTP {item.http_error_code}</dd></div>}
            {item.device && <div><dt className="text-gray-500">Device reported by Google</dt><dd className="capitalize">{item.device.toLowerCase().replaceAll('_', ' ')}</dd></div>}
            {item.dns_error_type && <div><dt className="text-gray-500">DNS error</dt><dd>{item.dns_error_type.replaceAll('_', ' ').toLowerCase()}</dd></div>}
            {checkedTime(item.last_checked_at) && <div><dt className="text-gray-500">Google destination check</dt><dd>{checkedTime(item.last_checked_at)}</dd></div>}
        </dl>)}
        {destinationIssue ? <p className="mt-3 text-sm text-gray-800">Ask your website administrator to repair the affected page and check that Google Ads can reach it. Check the website host, CDN, firewall and redirects. Changing ad copy cannot repair a website error. After the page works, request another review in Google Ads or contact support.</p>
            : <p className="mt-3 text-sm text-gray-800">Review the policy reason and affected ad before requesting another review. Contact support if you need help with the policy requirements.</p>}
        {admin && issue.ad_resource_name && <details className="mt-3 text-xs text-gray-600"><summary className="cursor-pointer">Ad identifier</summary><p className="mt-1 break-all font-mono">{issue.ad_resource_name}</p></details>}
    </li>;
}

/** Current platform approval state, independent of local campaign delivery status. */
export default function PolicyStatusCard({ campaign, policyStatus = campaign?.policy_checks, showClear = true, showCampaignLink = false, admin = false }) {
    if (!policyStatus && !campaign?.google_ads_campaign_id && !campaign?.facebook_ads_campaign_id) return null;
    const status = policyStatus?.status || 'unknown';
    if (status === 'clear' && !showClear) return null;
    const issues = policyStatus?.issues?.length ? policyStatus.issues : status === 'unknown' ? policyStatus?.last_incident?.issues || [] : [];
    const paused = String(campaign?.status || '').toLowerCase() === 'paused';
    const checked = checkedTime(policyStatus?.checked_at);
    const lastSuccess = checkedTime(policyStatus?.last_successful_checked_at);
    const isClear = status === 'clear';
    const title = isClear ? 'Ad policy check complete' : status === 'unknown' ? 'Ad policy status unavailable' : 'Ad approval needs attention';

    return <section id={`policy-status-${campaign?.id || campaign?.uuid || 'campaign'}`} aria-label={`Ad policy status${campaign?.name ? ` for ${campaign.name}` : ''}`} className={`rounded-xl border p-4 sm:p-5 ${isClear ? 'border-gray-200 bg-white' : 'border-amber-300 bg-amber-50'}`}>
        <div className="flex flex-wrap items-start justify-between gap-2">
            <div><h2 className="text-lg font-semibold text-gray-900">{title}</h2>{showCampaignLink && campaign?.name && <p className="mt-1 text-sm font-medium text-gray-800">{campaign.name}</p>}</div>
            <span className={`rounded-full px-3 py-1 text-xs font-semibold ${isClear ? 'bg-gray-100 text-gray-700' : 'bg-amber-100 text-amber-900'}`}>{isClear ? 'No current policy issues reported' : status === 'unknown' ? 'Unknown' : policyStatus?.repair_status === 'needs_website_repair' ? 'Website repair required' : 'Review required'}</span>
        </div>
        {checked ? <p className="mt-2 text-xs text-gray-600">Last policy check: {checked}</p> : <p className="mt-2 text-xs text-gray-600">No completed policy check is available yet.</p>}
        {status === 'unknown' && <div className="mt-3 text-sm text-amber-950"><p>We could not confirm the current ad approval status. An unavailable check does not mean the ads are approved.</p>{lastSuccess && <p className="mt-1">Last successful policy check: {lastSuccess}</p>}{issues.length > 0 && <p className="mt-1">The last reported issues are shown below until a successful check confirms they are resolved.</p>}</div>}
        {paused && <p className="mt-3 text-sm text-gray-700">Campaign status: Paused. Approval issues remain visible while the campaign is paused. Fixing an issue does not resume the campaign.</p>}
        {isClear && <p className="mt-3 text-sm text-gray-700">The last successful check found no ad policy issues. Campaign delivery, billing and pause status are separate.</p>}
        {issues.length > 0 && <ul className="mt-4 space-y-3">{issues.map((issue, index) => <PolicyIssue key={`${issue.platform}-${issue.ad_resource_name || index}`} issue={issue} admin={admin} />)}</ul>}
        {isClear && policyStatus?.last_incident?.resolved_at && <details className="mt-3 text-sm text-gray-600"><summary className="cursor-pointer">Previous approval issue</summary><p className="mt-2">Resolution confirmed: {checkedTime(policyStatus.last_incident.resolved_at)}</p><ul className="mt-3 space-y-3">{(policyStatus.last_incident.issues || []).map((issue, index) => <PolicyIssue key={index} issue={issue} admin={admin} />)}</ul></details>}
        {!isClear && <div className="mt-4 flex flex-wrap gap-4 text-sm font-medium">{showCampaignLink && campaign?.uuid && <Link href={route(admin ? 'admin.campaigns.show' : 'campaigns.show', campaign.uuid)} className="text-brand-dark underline">View campaign</Link>}{!admin && <Link href={route('support-tickets.create')} className="text-brand-dark underline">Get help with this issue</Link>}</div>}
    </section>;
}
