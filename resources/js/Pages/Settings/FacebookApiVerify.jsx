import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

function StatusBadge({ error, count, label }) {
    if (error) return <span className="text-xs font-semibold text-red-600 bg-red-50 px-2 py-0.5 rounded-full">Error</span>;
    return <span className="text-xs font-semibold text-green-700 bg-green-100 px-2 py-0.5 rounded-full">{label ?? `${count} accessible`}</span>;
}

function ApiSection({ title, icon, description, data, renderRow, identityBadge }) {
    return (
        <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
            <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <div className="w-7 h-7 rounded-lg overflow-hidden flex-shrink-0">{icon}</div>
                    <div>
                        <p className="text-sm font-semibold text-gray-900">{title}</p>
                        <p className="text-xs text-gray-500">{description}</p>
                    </div>
                </div>
                {identityBadge
                    ? <StatusBadge error={data.error} label="Verified" />
                    : <StatusBadge error={data.error} count={data.count ?? 0} />
                }
            </div>
            {data.error ? (
                <div className="px-5 py-4 text-sm text-red-600 bg-red-50">
                    {data.status && <span className="font-mono text-xs mr-2 opacity-60">HTTP {data.status}</span>}
                    {data.error}
                </div>
            ) : identityBadge ? (
                <div className="px-5 py-4">
                    {data.data && (
                        <div className="flex gap-6">
                            <div><p className="text-xs text-gray-400 mb-0.5">Name</p><p className="text-sm font-medium text-gray-900">{data.data.name}</p></div>
                            {data.data.email && <div><p className="text-xs text-gray-400 mb-0.5">Email</p><p className="text-sm text-gray-700">{data.data.email}</p></div>}
                            <div><p className="text-xs text-gray-400 mb-0.5">User ID</p><p className="text-sm font-mono text-gray-500">{data.data.id}</p></div>
                        </div>
                    )}
                </div>
            ) : data.accounts?.length === 0 ? (
                <div className="px-5 py-4 text-sm text-gray-400 italic">No accessible accounts found for this Facebook login.</div>
            ) : (
                <div className="divide-y divide-gray-50">
                    {data.accounts?.map((item, i) => (
                        <div key={i} className="px-5 py-3">{renderRow(item)}</div>
                    ))}
                </div>
            )}
        </div>
    );
}

const FBIcon = (
    <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7">
        <rect width="28" height="28" rx="6" fill="#1877F2"/>
        <path d="M19 6c-3.09 0-5.6 2.51-5.6 5.6 0 2.7 1.92 4.96 4.48 5.49v-3.85h-1.4v-1.64h1.4v-1.61c0-1.49.89-2.31 2.24-2.31.64 0 1.32.11 1.32.11v1.47h-.74c-.74 0-.97.46-.97.93v1.41h1.63l-.26 1.64h-1.37v3.85C17.08 16.56 19 14.3 19 11.6 19 8.51 16.49 6 13.4 6z" fill="#fff"/>
    </svg>
);

const AdsIcon = (
    <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7">
        <rect width="28" height="28" rx="6" fill="#0866FF"/>
        <path d="M8 20l4-10 4 10H8z" fill="#fff" fillOpacity=".9"/>
        <circle cx="20" cy="14" r="4" fill="#fff" fillOpacity=".7"/>
    </svg>
);

const BizIcon = (
    <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7">
        <rect width="28" height="28" rx="6" fill="#0866FF"/>
        <rect x="6" y="12" width="16" height="10" rx="2" fill="#fff" fillOpacity=".9"/>
        <rect x="10" y="8" width="8" height="6" rx="1.5" fill="#fff" fillOpacity=".6"/>
        <rect x="11" y="17" width="6" height="5" rx="1" fill="#0866FF" fillOpacity=".35"/>
    </svg>
);

export default function FacebookApiVerify({ account_name, token_expired, identity, adAccounts, businesses }) {
    const allOk = !token_expired && !identity?.error && !adAccounts?.error && !businesses?.error;

    return (
        <AuthenticatedLayout>
            <Head title="Live Facebook API Access Verification" />

            <div className="max-w-2xl mx-auto px-4 py-10">
                {token_expired && (
                    <div className="mb-6 bg-amber-50 border border-amber-200 rounded-xl px-5 py-4 flex items-start gap-3">
                        <svg className="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                        </svg>
                        <div>
                            <p className="text-sm font-semibold text-amber-800">Token expired</p>
                            <p className="text-xs text-amber-700 mt-0.5">
                                Re-authorise on the <Link href={route('facebook-api.show')} className="underline">manage page</Link> to get a fresh 60-day token.
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
                        Real-time results from Meta's Graph API using the OAuth token granted by <strong>{account_name}</strong>. Each section below is a live API call made by SiteToSpend's Spectra agents.
                    </p>
                </div>

                <div className="space-y-4 mb-8">
                    <ApiSection
                        title="User Identity"
                        icon={FBIcon}
                        description="Endpoint: /me?fields=id,name,email"
                        data={identity ?? { error: null }}
                        identityBadge
                    />
                    <ApiSection
                        title="Ad Accounts"
                        icon={AdsIcon}
                        description="Scopes: ads_management · ads_read"
                        data={adAccounts ?? { error: null, accounts: [], count: 0 }}
                        renderRow={(item) => (
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-gray-900">{item.name}</p>
                                    <p className="text-xs text-gray-400 font-mono">{item.id}</p>
                                </div>
                                <div className="w-2 h-2 rounded-full bg-green-400" />
                            </div>
                        )}
                    />
                    <ApiSection
                        title="Business Managers"
                        icon={BizIcon}
                        description="Scope: business_management"
                        data={businesses ?? { error: null, accounts: [], count: 0 }}
                        renderRow={(item) => (
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-gray-900">{item.name}</p>
                                    <p className="text-xs text-gray-400">ID: {item.id}</p>
                                </div>
                                <div className="w-2 h-2 rounded-full bg-green-400" />
                            </div>
                        )}
                    />
                </div>

                <div className="bg-gray-50 border border-gray-200 rounded-xl px-5 py-5 mb-8">
                    <p className="text-xs font-bold text-gray-500 uppercase tracking-wide mb-3">How SiteToSpend uses these APIs</p>
                    <div className="space-y-3">
                        {[
                            { api: 'Meta Ads API', usage: "Spectra's Optimisation and Budget agents create Facebook ad campaigns, adjust bids, and pull daily performance metrics 24/7." },
                            { api: 'Business Management API', usage: "Spectra agents access Business Manager accounts and assets required for campaign creation and management on behalf of clients." },
                            { api: 'Pages & Instagram API', usage: "Spectra reads Page engagement signals and Instagram account data to inform creative decisions and audience targeting strategies." },
                        ].map(({ api, usage }) => (
                            <div key={api} className="flex gap-3">
                                <div className="w-1 rounded-full bg-gray-300 flex-shrink-0" />
                                <div>
                                    <p className="text-xs font-semibold text-gray-700">{api}</p>
                                    <p className="text-xs text-gray-500 mt-0.5 leading-relaxed">{usage}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex gap-3">
                    <Link
                        href={route('facebook-api.verify')}
                        className="flex items-center gap-2 px-5 py-3 text-sm font-medium text-gray-700 hover:bg-gray-100 rounded-xl border border-gray-200 transition-colors"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        Refresh
                    </Link>
                    <Link
                        href={route('dashboard')}
                        className="flex-1 flex items-center justify-center bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-6 py-3 rounded-xl transition-colors"
                    >
                        Go to dashboard
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
