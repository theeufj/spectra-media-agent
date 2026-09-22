import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { usePolling } from '@/hooks/usePolling';

const isRunning = run => ['queued', 'running'].includes(run?.status);
const date = value => value ? new Date(value).toLocaleString() : '—';

function MetricCard({ label, value }) {
    return <div className="rounded-lg border border-gray-200 bg-white p-4">
        <p className="text-xs text-gray-500">{label}</p>
        <p className="mt-1 text-xl font-bold text-gray-900">{value ?? '—'}</p>
    </div>;
}

export default function Backlinks({ profile, domain, error, run = null }) {
    const [report, setReport] = useState({ profile, run });
    const [submitting, setSubmitting] = useState(false);
    const [timedOut, setTimedOut] = useState(false);
    const running = isRunning(report.run);
    useEffect(() => setReport({ profile, run }), [profile, run, domain]);
    useEffect(() => {
        if (!running) { setTimedOut(false); return; }
        const timer = setTimeout(() => setTimedOut(true), 6 * 60 * 1000);
        return () => clearTimeout(timer);
    }, [running]);
    const { data, failureStreak } = usePolling(domain ? route('seo.backlinks.status') : null, {
        enabled: running && !timedOut, interval: 3000,
        until: result => !isRunning(result.run),
        parse: result => {
            if (result?.domain !== domain || !Object.prototype.hasOwnProperty.call(result, 'run')) {
                throw new Error('Unexpected backlink analysis response');
            }
            return result;
        },
    });
    useEffect(() => { if (data) setReport(data); }, [data]);
    const refresh = () => {
        setSubmitting(true);
        setTimedOut(false);
        router.post(route('seo.backlinks.refresh'), {}, { preserveScroll: true, onFinish: () => setSubmitting(false) });
    };
    const result = report.profile;
    return <AuthenticatedLayout>
        <Head title="Backlink Analysis" />
        <div className="py-8"><div className="mx-auto max-w-6xl space-y-6">
            <div>
                <Link href={route('seo.index')} className="text-sm text-brand-dark hover:underline">← Back to SEO</Link>
                <div className="mt-2 flex flex-wrap items-center justify-between gap-4">
                    <div><h1 className="text-2xl font-bold text-gray-900">Backlink Analysis</h1>
                        <p className="mt-1 text-sm text-gray-500">{domain || 'Set your website to analyze backlinks.'}</p></div>
                    {domain && <button type="button" disabled={submitting || running} onClick={refresh}
                        className="rounded-lg bg-brand-dark px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
                        {running ? 'Analyzing backlinks…' : result ? 'Refresh analysis' : 'Run analysis'}
                    </button>}
                </div>
            </div>
            {error && <p role="alert" className="rounded-lg bg-amber-50 p-4 text-sm text-amber-800">{error}</p>}
            {running && <p role="status" className="rounded-lg bg-blue-50 p-4 text-sm text-blue-800">Checking the backlink index and preparing your report. Results update here automatically.</p>}
            {report.run?.status === 'failed' && <p role="alert" className="rounded-lg bg-amber-50 p-4 text-sm text-amber-800">{report.run.error}</p>}
            {failureStreak >= 3 && <p role="alert" className="text-sm text-amber-800">Unable to get progress updates. Refresh this page to check the result.</p>}
            {timedOut && <p role="status" className="text-sm text-amber-800">This is taking longer than expected. Refresh the page to check the latest status.</p>}
            {!result && !running && !error && <p className="rounded-lg border bg-white p-6 text-sm text-gray-600">Run an analysis to see indexed links, referring domains and source pages. Missing data will be shown as unavailable, not zero.</p>}
            {result && <>
                <div className="rounded-lg border border-gray-200 bg-white p-5 text-sm text-gray-600">
                    <p>{result.provider ? 'Source: ' + result.provider + ' backlink index.' : 'Indexed backlink data is unavailable.'} Last checked: {date(result.analyzed_at)}.</p>
                    <p className="mt-2">An index can lag behind the web. Counts cover indexed linking pages and domains; the sample below is limited to 100 links and may include links last reported as lost.</p>
                    {result.warnings?.map((warning, i) => <p key={i} className="mt-2 text-amber-800">{warning}</p>)}
                </div>
                <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <MetricCard label="Linking pages (Moz)" value={result.indexed_linking_pages} />
                    <MetricCard label="Referring domains (Moz)" value={result.referring_domains} />
                    <MetricCard label="Domain authority (Moz)" value={result.domain_authority} />
                    <MetricCard label="Links in this sample" value={result.sample_available ? result.sample_size : null} />
                </div>
                <section className="rounded-lg border border-gray-200 bg-white p-5">
                    <h2 className="text-lg font-semibold text-gray-900">Indexed backlink sample</h2>
                    <p className="mt-1 text-sm text-gray-500">{result.sample_truncated ? 'More links exist in the index than this sample shows.' : 'Source links returned by the latest index request.'} Follow status comes from Moz; these pages have not been rechecked live.</p>
                    {!!result.review_count && <p className="mt-2 text-sm text-amber-800">{result.review_count} source(s) have a high Spam Score. Review them manually; this score alone does not establish harm or justify disavowing a link.</p>}
                    {result.backlinks?.length > 0 ? <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[680px] text-left text-sm">
                            <thead className="border-b text-gray-500"><tr><th className="p-2">Source / destination</th><th className="p-2">Anchor</th><th className="p-2">Link type</th><th className="p-2">Source DA</th><th className="p-2">Last seen</th></tr></thead>
                            <tbody>{result.backlinks.map((link, i) => <tr key={i} className="border-b align-top last:border-0">
                                <td className="max-w-sm p-2"><a href={link.source_url} target="_blank" rel="noopener noreferrer" className="break-all text-brand-dark underline">{link.source_url}</a>
                                    <p className="mt-1 break-all text-xs text-gray-500">Links to <a href={link.target_url} target="_blank" rel="noopener noreferrer" className="underline">{link.target_url}</a></p>
                                    {link.review_reason && <p className="mt-1 text-xs text-amber-800">{link.review_reason}</p>}</td>
                                <td className="max-w-xs break-words p-2">{link.anchor_text || 'No anchor text reported'}</td>
                                <td className="p-2">{link.rel}{link.lost && <p className="mt-1 text-xs text-amber-800">Last reported lost {link.disappeared}</p>}</td>
                                <td className="p-2">{link.domain_authority ?? '—'}</td>
                                <td className="whitespace-nowrap p-2">{link.last_seen || '—'}</td>
                            </tr>)}</tbody>
                        </table>
                    </div> : <p className="mt-4 text-sm text-gray-500">{result.sample_available ? 'Moz returned no links in this sample. This is not proof that no backlinks exist.' : 'The backlink sample could not be retrieved.'}</p>}
                </section>
                {result.anchor_analysis?.length > 0 && <section className="rounded-lg border bg-white p-5">
                    <h2 className="text-lg font-semibold">Anchor text in this sample</h2>
                    <p className="mt-1 text-sm text-gray-500">Excludes links last reported lost.</p>
                    <ul className="mt-3 space-y-2">{result.anchor_analysis.slice(0, 10).map((anchor, i) => <li key={i} className="flex justify-between gap-4 text-sm"><span>{anchor.text}</span><span>{anchor.count}</span></li>)}</ul>
                </section>}
                {result.mentions?.length > 0 && <section className="rounded-lg border bg-white p-5">
                    <h2 className="text-lg font-semibold">Unverified web mentions</h2>
                    <p className="mt-1 text-sm text-gray-500">These pages mention your domain. We have not confirmed that they link to it, so they are excluded from all backlink metrics.</p>
                    <ul className="mt-3 space-y-2">{result.mentions.map(mention => <li key={mention.url}><a href={mention.url} target="_blank" rel="noopener noreferrer" className="text-sm text-brand-dark underline">{mention.title}</a></li>)}</ul>
                </section>}
                {result.opportunities?.length > 0 && <section className="rounded-lg border bg-white p-5">
                    <h2 className="text-lg font-semibold">Link-building ideas to investigate</h2>
                    <p className="mt-1 text-sm text-gray-500">AI suggestions based on your business profile and this sample. Placements have not been verified or contacted.</p>
                    <ul className="mt-3 space-y-3">{result.opportunities.map((idea, i) => <li key={i} className="rounded bg-blue-50 p-3 text-sm text-blue-900">{idea.description}</li>)}</ul>
                </section>}
            </>}
        </div></div>
    </AuthenticatedLayout>;
}
