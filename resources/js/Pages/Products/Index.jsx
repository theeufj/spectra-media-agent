import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import FormErrorSummary from '@/Components/FormErrorSummary';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { usePolling } from '@/hooks/usePolling';

const EMPTY = { feed_name: '', merchant_id: '', source_type: 'api', sync_frequency: 'daily' };
const ACTION = 'min-h-[44px] rounded-lg bg-brand-dark px-4 py-2 text-sm font-medium text-white hover:bg-brand-darker disabled:opacity-50';
const SECONDARY = 'min-h-[44px] rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50';
const STATUS = { active: 'bg-green-100 text-green-800', processing: 'bg-blue-100 text-blue-800', error: 'bg-red-100 text-red-800', pending: 'bg-amber-100 text-amber-800' };

export default function Index({ feeds: initialFeeds = [], stats: initialStats }) {
    const [feeds, setFeeds] = useState(initialFeeds);
    const [stats, setStats] = useState(initialStats);
    const [editing, setEditing] = useState(null);
    const [showCreate, setShowCreate] = useState(false);
    const [watchToken, setWatchToken] = useState(0);
    const [timedOut, setTimedOut] = useState(false);
    const form = useForm(EMPTY);
    const working = feeds.some(feed => ['pending', 'processing'].includes(feed.status));
    const watch = usePolling(route('products.index', { watch: watchToken }), { enabled: working && !timedOut, interval: 8000, until: result => !result.working });
    useEffect(() => { setFeeds(initialFeeds); setStats(initialStats); }, [initialFeeds, initialStats]);
    useEffect(() => { if (watch.data) { setFeeds(watch.data.feeds); setStats(watch.data.stats); } }, [watch.data]);
    useEffect(() => {
        if (!working) return;
        const timer = setTimeout(() => setTimedOut(true), 600000);
        return () => clearTimeout(timer);
    }, [working, watchToken]);
    const checkAgain = () => { setTimedOut(false); setWatchToken(value => value + 1); };
    const edit = feed => { setEditing(feed); setShowCreate(true); form.clearErrors(); form.setData({ ...EMPTY, ...feed, source_type: 'api' }); };
    const submit = event => {
        event.preventDefault();
        form[editing ? 'put' : 'post'](editing ? route('products.feeds.update', editing.id) : route('products.feeds.create'), { preserveScroll: true, onSuccess: () => { setShowCreate(false); setEditing(null); form.reset(); checkAgain(); } });
    };
    const sync = feed => router.post(route('products.feeds.sync', feed.id), {}, { preserveScroll: true, onSuccess: () => checkAgain() });
    return <AuthenticatedLayout><Head title="Product feeds" />
        <main className="mx-auto max-w-5xl space-y-6 px-4 py-8">
            <header className="flex flex-wrap items-start justify-between gap-4"><div><h1 className="text-2xl font-bold text-gray-900">Products & Merchant Center feeds</h1><p className="mt-2 text-sm text-gray-600">Sync your catalogue, resolve product issues and prepare Shopping campaigns.</p></div><div className="flex flex-wrap gap-2"><Link href={route('products.list')} className={SECONDARY}>Browse products</Link><button className={ACTION} onClick={() => { setEditing(null); form.setData(EMPTY); form.clearErrors(); setShowCreate(true); }}>Add feed</button></div></header>
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">{[['Total products', stats?.total], ['Approved', stats?.approved], ['Disapproved', stats?.disapproved], ['Out of stock', stats?.out_of_stock]].map(([label, number]) => <div key={label} className="rounded-lg border bg-white p-4"><p className="text-xs text-gray-500">{label}</p><p className="mt-2 text-xl font-bold text-gray-900">{number || 0}</p></div>)}</div>
            {working && <p role="status" className="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">{timedOut ? 'Sync is taking longer than expected. It continues in the background.' : 'A feed is queued or syncing. Counts and product issues will update here when it finishes.'}</p>}
            {(watch.error || timedOut) && <div role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p>{watch.error ? 'We could not refresh feed status. The last confirmed information is shown.' : 'No finished result yet.'}</p><button onClick={checkAgain} className={`${SECONDARY} mt-3`}>Check status again</button></div>}
            {showCreate && <form onSubmit={submit} className="space-y-5 rounded-xl border bg-white p-6"><h2 className="text-lg font-semibold">{editing ? 'Repair or edit feed' : 'Add Merchant Center feed'}</h2><p className="text-sm text-gray-600">Use your Merchant Center ID. The account must grant Spectra access before products can sync. If access fails, check the ID and account permissions, then retry.</p><FormErrorSummary errors={form.errors} /><div className="grid gap-4 sm:grid-cols-2">{[['feed_name', 'Feed name'], ['merchant_id', 'Merchant Center account ID']].map(([key, label]) => <div key={key}><label htmlFor={key} className="block text-sm font-medium">{label}</label><input id={key} value={form.data[key]} onChange={event => form.setData(key, event.target.value)} required aria-invalid={Boolean(form.errors[key])} aria-describedby={form.errors[key] ? `${key}-error` : undefined} className="mt-1 w-full rounded-lg border-gray-300 text-sm" />{form.errors[key] && <p id={`${key}-error`} className="mt-1 text-sm text-red-700">{form.errors[key]}</p>}</div>)}<div><label htmlFor="sync_frequency" className="block text-sm font-medium">Sync frequency</label><select id="sync_frequency" className="mt-1 w-full rounded-lg border-gray-300 text-sm" value={form.data.sync_frequency} onChange={event => form.setData('sync_frequency', event.target.value)}><option value="hourly">Hourly</option><option value="daily">Daily</option><option value="weekly">Weekly</option></select></div></div><div className="flex gap-3"><button type="button" className={SECONDARY} disabled={form.processing} onClick={() => setShowCreate(false)}>Cancel</button><button className={ACTION} disabled={form.processing}>{form.processing ? 'Saving…' : editing ? 'Save & retry sync' : 'Add & sync feed'}</button></div></form>}
            {feeds.length ? <div className="grid gap-4 md:grid-cols-2">{feeds.map(feed => <section key={feed.id} className="space-y-4 rounded-xl border bg-white p-5"><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold text-gray-900">{feed.feed_name}</h2><p className="mt-1 text-xs text-gray-500">Merchant Center {feed.merchant_id}</p></div><span className={`rounded px-2 py-1 text-xs font-medium ${STATUS[feed.status] || 'bg-gray-100 text-gray-800'}`}>{feed.status === 'pending' ? 'Queued' : feed.status}</span></div><p className="text-sm text-gray-600">{feed.total_products || 0} products · {feed.approved_products || 0} approved · {feed.disapproved_products || 0} with issues</p><p className="text-xs text-gray-500">{feed.last_synced_at ? `Last successful sync: ${new Date(feed.last_synced_at).toLocaleString()}` : 'No successful sync yet'}</p>{feed.last_error && <div role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-800"><p>{feed.last_error}</p><p className="mt-2">Check the account ID and access in Merchant Center, or contact support with this error.</p><Link className="mt-2 inline-block underline" href={route('support-tickets.create')}>Contact support</Link></div>}<div className="flex flex-wrap gap-2"><button className={SECONDARY} disabled={['pending', 'processing'].includes(feed.status)} onClick={() => sync(feed)}>{feed.status === 'error' ? 'Retry sync' : 'Sync now'}</button><button className={SECONDARY} disabled={!timedOut && ['pending', 'processing'].includes(feed.status)} onClick={() => edit(feed)}>Edit feed</button><Link className={SECONDARY} href={route('products.list', { feed: feed.id, status: feed.disapproved_products ? 'disapproved' : undefined })}>{feed.disapproved_products ? 'Review issues' : 'View products'}</Link><button className="min-h-[44px] px-3 text-sm text-red-700" onClick={() => { if (confirm(`Delete ${feed.feed_name}? Its synced products will be removed from this workspace.`)) router.delete(route('products.feeds.delete', feed.id), { preserveScroll: true }); }}>Delete</button></div></section>)}</div> : <section className="rounded-xl border bg-white p-8 text-center"><h2 className="font-semibold">No product feeds yet</h2><p className="mx-auto mt-2 max-w-md text-sm text-gray-600">Add a Merchant Center feed to inspect product eligibility before building Shopping campaigns.</p><button className={`${ACTION} mt-5`} onClick={() => setShowCreate(true)}>Add your first feed</button></section>}
        </main>
    </AuthenticatedLayout>;
}
