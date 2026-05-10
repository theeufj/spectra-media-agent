import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

// ─── shared ─────────────────────────────────────────────────────────────────

function SectionCard({ title, icon, badge, children, error, status }) {
    return (
        <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between bg-gray-50">
                <div className="flex items-center gap-3">
                    <div className="w-7 h-7 rounded-lg overflow-hidden flex-shrink-0">{icon}</div>
                    <p className="text-sm font-semibold text-gray-900">{title}</p>
                </div>
                {badge}
            </div>
            {error ? (
                <div className="px-5 py-4 bg-red-50 text-sm text-red-600">
                    {status && <span className="font-mono text-xs mr-2 opacity-60">HTTP {status}</span>}
                    {error}
                </div>
            ) : children}
        </div>
    );
}

function GreenBadge({ label }) {
    return <span className="text-xs font-semibold text-green-700 bg-green-100 px-2 py-0.5 rounded-full">{label}</span>;
}

function MetricBox({ label, value, sub }) {
    return (
        <div className="bg-gray-50 rounded-lg px-4 py-3">
            <p className="text-xs text-gray-500 mb-1">{label}</p>
            <p className="text-lg font-bold text-gray-900">{value ?? '—'}</p>
            {sub && <p className="text-xs text-gray-400 mt-0.5">{sub}</p>}
        </div>
    );
}

// ─── icons ──────────────────────────────────────────────────────────────────

const FBIcon = <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7"><rect width="28" height="28" rx="6" fill="#1877F2"/><path d="M19 6c-3.09 0-5.6 2.51-5.6 5.6 0 2.7 1.92 4.96 4.48 5.49v-3.85h-1.4v-1.64h1.4v-1.61c0-1.49.89-2.31 2.24-2.31.64 0 1.32.11 1.32.11v1.47h-.74c-.74 0-.97.46-.97.93v1.41h1.63l-.26 1.64h-1.37v3.85C17.08 16.56 19 14.3 19 11.6 19 8.51 16.49 6 13.4 6z" fill="#fff"/></svg>;
const AdsIcon = <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7"><rect width="28" height="28" rx="6" fill="#0866FF"/><path d="M8 20l4-10 4 10H8z" fill="#fff" fillOpacity=".9"/><circle cx="20" cy="14" r="4" fill="#fff" fillOpacity=".7"/></svg>;
const BizIcon = <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7"><rect width="28" height="28" rx="6" fill="#0866FF"/><rect x="6" y="12" width="16" height="10" rx="2" fill="#fff" fillOpacity=".9"/><rect x="10" y="8" width="8" height="6" rx="1.5" fill="#fff" fillOpacity=".6"/><rect x="11" y="17" width="6" height="5" rx="1" fill="#0866FF" fillOpacity=".35"/></svg>;
const PageIcon = <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7"><rect width="28" height="28" rx="6" fill="#1877F2"/><circle cx="14" cy="11" r="4" fill="#fff" fillOpacity=".9"/><path d="M6 24c0-4.418 3.582-8 8-8s8 3.582 8 8" stroke="#fff" strokeWidth="2" strokeOpacity=".6" fill="none"/></svg>;
const CampaignIcon = <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7"><rect width="28" height="28" rx="6" fill="#16A34A"/><path d="M8 20V14m4 6V10m4 10V8m4 12V12" stroke="#fff" strokeWidth="2.5" strokeLinecap="round"/></svg>;

// ─── sections ───────────────────────────────────────────────────────────────

function IdentitySection({ identity }) {
    return (
        <SectionCard title="User Identity" icon={FBIcon} error={identity.error}
            badge={!identity.error && <GreenBadge label="Verified" />}>
            {identity.data && (
                <div className="px-5 py-4 flex gap-8 flex-wrap">
                    <div><p className="text-xs text-gray-400 mb-0.5">Name</p><p className="text-sm font-medium text-gray-900">{identity.data.name}</p></div>
                    {identity.data.email && <div><p className="text-xs text-gray-400 mb-0.5">Email</p><p className="text-sm text-gray-700">{identity.data.email}</p></div>}
                    <div><p className="text-xs text-gray-400 mb-0.5">User ID</p><p className="text-sm font-mono text-gray-500">{identity.data.id}</p></div>
                </div>
            )}
        </SectionCard>
    );
}

function AdAccountsSection({ adAccounts, adInsights }) {
    const hasInsights = adInsights && !adInsights.error;
    return (
        <SectionCard title="Ad Accounts — Performance Data" icon={AdsIcon}
            error={adAccounts.error} status={adAccounts.status}
            badge={!adAccounts.error && <GreenBadge label={`${adAccounts.count ?? 0} accounts`} />}>
            {/* Accounts list */}
            {adAccounts.accounts?.length > 0 && (
                <div className="px-5 pt-4 pb-2 border-b border-gray-100">
                    <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Accessible Ad Accounts (ads_read · ads_management)</p>
                    <div className="space-y-1">
                        {adAccounts.accounts.map((a, i) => (
                            <div key={i} className="flex items-center justify-between py-1.5">
                                <div>
                                    <span className="text-sm font-medium text-gray-900">{a.name}</span>
                                    <span className="text-xs text-gray-400 ml-2 font-mono">{a.id}</span>
                                </div>
                                <div className="w-2 h-2 rounded-full bg-green-400" />
                            </div>
                        ))}
                    </div>
                </div>
            )}
            {/* Live performance metrics */}
            <div className="px-5 py-4">
                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">
                    Live Performance Metrics — {adInsights?.period ?? 'Last 30 days'}
                    {adInsights?.account_id && <span className="font-normal normal-case ml-1 text-gray-400">for {adInsights.account_id}</span>}
                </p>
                {adInsights?.error ? (
                    <p className="text-sm text-red-500">{adInsights.error}</p>
                ) : (
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <MetricBox label="Impressions" value={hasInsights ? Number(adInsights.impressions).toLocaleString() : '—'} />
                        <MetricBox label="Clicks" value={hasInsights ? Number(adInsights.clicks).toLocaleString() : '—'} />
                        <MetricBox label="Spend" value={hasInsights ? `$${Number(adInsights.spend).toFixed(2)}` : '—'} />
                        <MetricBox label="Reach" value={hasInsights ? Number(adInsights.reach).toLocaleString() : '—'} />
                    </div>
                )}
            </div>
        </SectionCard>
    );
}

function CampaignCreateSection({ adAccounts }) {
    const [result, setResult] = useState(null);
    const [loading, setLoading] = useState(false);

    const firstAccount = adAccounts.accounts?.[0];

    const handleCreate = () => {
        if (!firstAccount) return;
        setLoading(true);
        router.post(route('facebook-api.test-campaign'), { ad_account_id: firstAccount.id }, {
            preserveScroll: true,
            onSuccess: (page) => {
                // Inertia router.post with onSuccess gets the page props
                setLoading(false);
            },
            onFinish: () => setLoading(false),
        });
    };

    const handleCreateDirect = async () => {
        if (!firstAccount) return;
        setLoading(true);
        try {
            const res = await fetch(route('facebook-api.test-campaign'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ ad_account_id: firstAccount.id }),
            });
            const data = await res.json();
            setResult(data);
        } catch (e) {
            setResult({ error: e.message });
        } finally {
            setLoading(false);
        }
    };

    return (
        <SectionCard title="Campaign Creation Demo" icon={CampaignIcon}
            badge={result?.success ? <GreenBadge label="Campaign created" /> : null}>
            <div className="px-5 py-4">
                <p className="text-xs text-gray-500 mb-4 leading-relaxed">
                    Demonstrates <strong>ads_management</strong> write access. Creates a PAUSED test campaign on ad account <strong className="font-mono">{firstAccount?.id ?? '—'}</strong>.
                    The campaign does not spend — it is created with status PAUSED.
                </p>

                {!result && (
                    <button
                        onClick={handleCreateDirect}
                        disabled={loading || !firstAccount}
                        className="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white text-sm font-medium rounded-lg hover:bg-gray-800 disabled:opacity-50 transition-colors"
                    >
                        {loading ? (
                            <><svg className="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"/><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/></svg> Creating…</>
                        ) : (
                            <><svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}><path strokeLinecap="round" strokeLinejoin="round" d="M12 4v16m8-8H4"/></svg> Create Test Campaign</>
                        )}
                    </button>
                )}

                {result && (
                    <div className={`rounded-lg p-4 ${result.success ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'}`}>
                        {result.success ? (
                            <>
                                <p className="text-sm font-semibold text-green-800 mb-2">Campaign created successfully</p>
                                <div className="grid grid-cols-2 gap-3">
                                    <div><p className="text-xs text-green-600">Campaign ID</p><p className="text-sm font-mono text-green-900">{result.campaign_id}</p></div>
                                    <div><p className="text-xs text-green-600">Status</p><p className="text-sm font-semibold text-green-900">{result.status}</p></div>
                                    <div className="col-span-2"><p className="text-xs text-green-600">Name</p><p className="text-sm text-green-900">{result.name}</p></div>
                                    <div><p className="text-xs text-green-600">Objective</p><p className="text-sm text-green-900">{result.objective}</p></div>
                                </div>
                            </>
                        ) : (
                            <>
                                <p className="text-sm font-semibold text-red-700">{result.error}</p>
                                {result.detail && <p className="text-xs text-red-600 mt-1">{result.detail}</p>}
                            </>
                        )}
                    </div>
                )}
            </div>
        </SectionCard>
    );
}

function BusinessSection({ businesses, businessAssets }) {
    return (
        <SectionCard title="Business Manager — Assets" icon={BizIcon}
            error={businesses.error} status={businesses.status}
            badge={!businesses.error && <GreenBadge label={`${businesses.count ?? 0} businesses`} />}>
            {businesses.accounts?.length > 0 && (
                <div className="divide-y divide-gray-50">
                    {businesses.accounts.map((b, i) => (
                        <div key={i} className="px-5 py-3 flex items-center justify-between">
                            <div>
                                <p className="text-sm font-medium text-gray-900">{b.name}</p>
                                <p className="text-xs text-gray-400 font-mono">ID: {b.id}</p>
                            </div>
                            <div className="w-2 h-2 rounded-full bg-green-400" />
                        </div>
                    ))}
                </div>
            )}
            {/* Business → pages relationship */}
            {businessAssets && !businessAssets.error && (
                <div className="px-5 pt-3 pb-4 border-t border-gray-100">
                    <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
                        Pages linked to {businessAssets.business_name}
                    </p>
                    {businessAssets.pages?.length === 0 ? (
                        <p className="text-xs text-gray-400 italic">No pages linked to this business.</p>
                    ) : (
                        <div className="space-y-1">
                            {businessAssets.pages?.map((p, i) => (
                                <div key={i} className="flex items-center justify-between py-1">
                                    <div>
                                        <span className="text-sm text-gray-800">{p.name}</span>
                                        <span className="text-xs text-gray-400 ml-2">{p.category}</span>
                                    </div>
                                    <span className="text-xs text-gray-400">{p.fans?.toLocaleString()} fans</span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            )}
            {businessAssets?.error && (
                <div className="px-5 py-3 border-t border-gray-100 text-xs text-amber-700 bg-amber-50">{businessAssets.error}</div>
            )}
        </SectionCard>
    );
}

function PagesSection({ managedPages, pagePosts }) {
    return (
        <SectionCard title="Managed Pages — Content & Engagement" icon={PageIcon}
            error={managedPages.error} status={managedPages.status}
            badge={!managedPages.error && <GreenBadge label={`${managedPages.count ?? 0} pages`} />}>
            {/* Page list (pages_show_list) */}
            {managedPages.pages?.length > 0 && (
                <div className="px-5 pt-4 pb-3 border-b border-gray-100">
                    <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Managed Pages (pages_show_list)</p>
                    <div className="space-y-1">
                        {managedPages.pages.map((p, i) => (
                            <div key={i} className="flex items-center justify-between py-1.5">
                                <div>
                                    <span className="text-sm font-medium text-gray-900">{p.name}</span>
                                    <span className="text-xs text-gray-400 ml-2">{p.category}</span>
                                </div>
                                <div className="text-right">
                                    <p className="text-xs text-gray-500">{p.followers?.toLocaleString()} followers</p>
                                    <p className="text-xs text-gray-400">{p.fans?.toLocaleString()} fans</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            {/* Page posts (pages_read_engagement) */}
            <div className="px-5 py-4">
                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-3">
                    Recent Posts from {pagePosts?.page_name ?? '—'} (pages_read_engagement)
                </p>
                {pagePosts?.error ? (
                    pagePosts.error.includes('pages_read_engagement') || pagePosts.error.includes('#10') ? (
                        <div className="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3">
                            <p className="text-sm font-semibold text-amber-800 mb-1">Token needs refresh</p>
                            <p className="text-xs text-amber-700 mb-2">
                                The stored token was issued before <code className="font-mono bg-amber-100 px-1 rounded">pages_read_engagement</code> was confirmed.
                                Re-authorise to get a fresh token with all five scopes active, then return here.
                            </p>
                            <Link href={route('facebook-api.show')} className="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-900 underline">
                                Re-authorise now →
                            </Link>
                        </div>
                    ) : (
                        <p className="text-sm text-red-500">{pagePosts.error}</p>
                    )
                ) : pagePosts?.posts?.length === 0 ? (
                    <p className="text-xs text-gray-400 italic">No recent posts found on this page.</p>
                ) : (
                    <div className="space-y-3">
                        {pagePosts?.posts?.map((post, i) => (
                            <div key={i} className="bg-gray-50 rounded-lg px-4 py-3">
                                <p className="text-sm text-gray-800 leading-relaxed line-clamp-2">
                                    {post.message || <span className="italic text-gray-400">No text content</span>}
                                </p>
                                <div className="flex items-center gap-4 mt-2">
                                    <span className="text-xs text-gray-400">{new Date(post.created_time).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}</span>
                                    <span className="text-xs text-gray-500">👍 {post.likes?.toLocaleString()}</span>
                                    <span className="text-xs text-gray-500">💬 {post.comments?.toLocaleString()}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </SectionCard>
    );
}

const SCOPE_META = {
    ads_management:        { label: 'Ads Management',        desc: 'Create and manage campaigns, ad sets, ads' },
    ads_read:              { label: 'Ads Read',              desc: 'Read performance insights and reporting data' },
    business_management:   { label: 'Business Management',   desc: 'Manage Business Manager assets and permissions' },
    pages_read_engagement: { label: 'Pages Read Engagement', desc: 'Read Page content, posts, and engagement data' },
    pages_show_list:       { label: 'Pages Show List',       desc: 'List all Pages managed by this account' },
};

function PermissionsSection({ grantedPermissions }) {
    const perms = grantedPermissions?.permissions ?? [];
    const grantedSet = new Set(perms.filter(p => p.status === 'granted').map(p => p.permission));
    const allGranted = Object.keys(SCOPE_META).every(k => grantedSet.has(k));

    return (
        <SectionCard
            title="Granted OAuth Scopes — Token Verification"
            icon={<svg viewBox="0 0 28 28" fill="none" className="w-7 h-7"><rect width="28" height="28" rx="6" fill="#7C3AED"/><path d="M14 7l2.1 5.4H22l-4.7 3.4 1.8 5.5L14 18l-5.1 3.3 1.8-5.5L6 12.4h5.9L14 7z" fill="#fff" fillOpacity=".9"/></svg>}
            error={grantedPermissions?.error}
            badge={!grantedPermissions?.error && <GreenBadge label={`${grantedSet.size} scopes granted`} />}>
            <div className="px-5 py-4">
                <p className="text-xs text-gray-500 mb-3 leading-relaxed">
                    Live result from <code className="font-mono bg-gray-100 px-1 rounded">GET /me/permissions</code> — confirms which scopes Meta granted to this token.
                </p>
                <div className="space-y-2">
                    {Object.entries(SCOPE_META).map(([key, meta]) => {
                        const granted = grantedSet.has(key);
                        return (
                            <div key={key} className={`flex items-center gap-3 px-4 py-2.5 rounded-lg ${granted ? 'bg-green-50 border border-green-100' : 'bg-gray-50 border border-gray-100'}`}>
                                <div className={`w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0 ${granted ? 'bg-green-500' : 'bg-gray-300'}`}>
                                    {granted ? (
                                        <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                    ) : (
                                        <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    )}
                                </div>
                                <div className="flex-1 min-w-0">
                                    <p className={`text-xs font-semibold ${granted ? 'text-green-900' : 'text-gray-500'}`}>{meta.label}</p>
                                    <p className="text-xs text-gray-400 truncate">{meta.desc}</p>
                                </div>
                                <span className={`text-xs font-bold flex-shrink-0 ${granted ? 'text-green-700' : 'text-gray-400'}`}>
                                    {granted ? 'granted' : 'not granted'}
                                </span>
                            </div>
                        );
                    })}
                </div>
                {allGranted && (
                    <div className="mt-3 bg-green-50 border border-green-200 rounded-lg px-4 py-2.5">
                        <p className="text-xs font-semibold text-green-800">All 5 required scopes confirmed active on this token.</p>
                    </div>
                )}
            </div>
        </SectionCard>
    );
}

// ─── page ────────────────────────────────────────────────────────────────────

export default function FacebookApiVerify({
    account_name, token_expired,
    identity, grantedPermissions,
    adAccounts, adInsights,
    businesses, businessAssets,
    managedPages, pagePosts,
}) {
    const allOk = !token_expired
        && !identity?.error
        && !adAccounts?.error
        && !businesses?.error
        && !managedPages?.error;

    return (
        <AuthenticatedLayout>
            <Head title="Live Facebook API Access Verification" />

            <div className="max-w-2xl mx-auto px-4 py-10">
                {token_expired && (
                    <div className="mb-6 bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 flex items-start gap-3">
                        <svg className="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01" />
                        </svg>
                        <div>
                            <p className="text-sm font-semibold text-amber-800">Token expired</p>
                            <p className="text-xs text-amber-700 mt-0.5">
                                <Link href={route('facebook-api.show')} className="underline">Re-authorise</Link> to get a fresh 60-day token.
                            </p>
                        </div>
                    </div>
                )}

                <div className="mb-8">
                    <div className={`inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold mb-4 ${allOk ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}>
                        <span className={`w-1.5 h-1.5 rounded-full ${allOk ? 'bg-green-500' : 'bg-amber-500'}`} />
                        Live API calls · {new Date().toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                    </div>
                    <h1 className="text-2xl font-bold text-gray-900 mb-1">Live API Access Verification</h1>
                    <p className="text-sm text-gray-500">
                        Real-time results from Meta's Graph API using the OAuth token granted by <strong>{account_name}</strong>.
                    </p>
                </div>

                <div className="space-y-4 mb-8">
                    <PermissionsSection grantedPermissions={grantedPermissions} />
                    <IdentitySection identity={identity ?? { error: null }} />
                    <AdAccountsSection adAccounts={adAccounts ?? { accounts: [] }} adInsights={adInsights} />
                    <CampaignCreateSection adAccounts={adAccounts ?? { accounts: [] }} />
                    <BusinessSection businesses={businesses ?? { accounts: [] }} businessAssets={businessAssets} />
                    <PagesSection managedPages={managedPages ?? { pages: [] }} pagePosts={pagePosts} />
                </div>

                <div className="flex gap-3">
                    <Link href={route('facebook-api.verify')}
                        className="flex items-center gap-2 px-5 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-xl border border-gray-200 transition-colors">
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Refresh
                    </Link>
                    <Link href={route('dashboard')}
                        className="flex-1 flex items-center justify-center bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-6 py-3 rounded-xl transition-colors">
                        Go to dashboard
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
