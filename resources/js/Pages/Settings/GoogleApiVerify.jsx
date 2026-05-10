import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

function StatusBadge({ error, count }) {
    if (error) return <span className="text-xs font-semibold text-red-600 bg-red-50 px-2 py-0.5 rounded-full">Error</span>;
    return <span className="text-xs font-semibold text-green-700 bg-green-100 px-2 py-0.5 rounded-full">{count} accessible</span>;
}

function ApiSection({ title, icon, description, data, renderRow }) {
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
                <StatusBadge error={data.error} count={data.count ?? 0} />
            </div>

            {data.error ? (
                <div className="px-5 py-4 text-sm text-red-600 bg-red-50">{data.error}</div>
            ) : data.accounts?.length === 0 ? (
                <div className="px-5 py-4 text-sm text-gray-400 italic">No accessible accounts found for this Google login.</div>
            ) : (
                <div className="divide-y divide-gray-50">
                    {data.accounts?.map((item, i) => (
                        <div key={i} className="px-5 py-3">
                            {renderRow(item)}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

const GoogleAdsIcon = (
    <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7">
        <rect width="28" height="28" rx="6" fill="#FBBC04"/>
        <path d="M8 20l6-12 6 12H8z" fill="#fff" fillOpacity=".9"/>
    </svg>
);

const GTMIcon = (
    <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7">
        <rect width="28" height="28" rx="6" fill="#4285F4"/>
        <rect x="7" y="7" width="6" height="6" rx="1" fill="#fff"/>
        <rect x="15" y="7" width="6" height="6" rx="1" fill="#fff" fillOpacity=".6"/>
        <rect x="7" y="15" width="6" height="6" rx="1" fill="#fff" fillOpacity=".6"/>
        <rect x="15" y="15" width="6" height="6" rx="1" fill="#fff" fillOpacity=".3"/>
    </svg>
);

const GA4Icon = (
    <svg viewBox="0 0 28 28" fill="none" className="w-7 h-7">
        <rect width="28" height="28" rx="6" fill="#34A853"/>
        <rect x="6" y="17" width="4" height="5" rx="1" fill="#fff"/>
        <rect x="12" y="12" width="4" height="10" rx="1" fill="#fff" fillOpacity=".8"/>
        <rect x="18" y="7" width="4" height="15" rx="1" fill="#fff" fillOpacity=".6"/>
    </svg>
);

export default function GoogleApiVerify({ account_name, googleAds, tagManager, analytics }) {
    const allOk = !googleAds.error && !tagManager.error && !analytics.error;

    return (
        <AuthenticatedLayout>
            <Head title="Live API Access Verification" />

            <div className="max-w-2xl mx-auto px-4 py-10">
                {/* Header */}
                <div className="mb-8">
                    <div className={`inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold mb-4 ${allOk ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'}`}>
                        <span className={`w-1.5 h-1.5 rounded-full ${allOk ? 'bg-green-500' : 'bg-amber-500'}`} />
                        Live API calls · {new Date().toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                    </div>
                    <h1 className="text-2xl font-bold text-gray-900 mb-1">Live API Access Verification</h1>
                    <p className="text-sm text-gray-500">
                        Real-time results from Google's APIs using the OAuth token granted by <strong>{account_name}</strong>. Each section below is a live API call made by SiteToSpend's Spectra agents.
                    </p>
                </div>

                <div className="space-y-4 mb-8">
                    <ApiSection
                        title="Google Ads API"
                        icon={GoogleAdsIcon}
                        description="Scope: https://www.googleapis.com/auth/adwords"
                        data={googleAds}
                        renderRow={(item) => (
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-gray-900">Customer ID: {item.id}</p>
                                    <p className="text-xs text-gray-400 font-mono">{item.resource_name}</p>
                                </div>
                                <div className="w-2 h-2 rounded-full bg-green-400" />
                            </div>
                        )}
                    />

                    <ApiSection
                        title="Google Tag Manager API"
                        icon={GTMIcon}
                        description="Scopes: tagmanager.publish · tagmanager.edit.containers · tagmanager.readonly"
                        data={tagManager}
                        renderRow={(item) => (
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-gray-900">{item.name}</p>
                                    <p className="text-xs text-gray-400">Account ID: {item.id}</p>
                                </div>
                                <div className="w-2 h-2 rounded-full bg-green-400" />
                            </div>
                        )}
                    />

                    <ApiSection
                        title="Google Analytics (GA4) API"
                        icon={GA4Icon}
                        description="Scopes: analytics.edit · analytics.readonly"
                        data={analytics}
                        renderRow={(item) => (
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-gray-900">{item.name}</p>
                                    <p className="text-xs text-gray-400">{item.region && `Region: ${item.region} · `}Resource: {item.resource}</p>
                                </div>
                                <div className="w-2 h-2 rounded-full bg-green-400" />
                            </div>
                        )}
                    />
                </div>

                {/* How these APIs are used */}
                <div className="bg-gray-50 border border-gray-200 rounded-xl px-5 py-5 mb-8">
                    <p className="text-xs font-bold text-gray-500 uppercase tracking-wide mb-3">How SiteToSpend uses these APIs</p>
                    <div className="space-y-3">
                        {[
                            { api: 'Google Ads API', usage: 'Spectra's Optimisation and Budget agents create campaigns, adjust bids, pause underperforming ad groups, and pull daily performance metrics 24/7.' },
                            { api: 'Tag Manager API', usage: 'Spectra's Vision AI agent publishes conversion tags and tracking pixels to GTM containers when new campaign types are launched.' },
                            { api: 'GA4 API', usage: 'Spectra's Search Term Mining and A/B Testing agents read landing page and session data to identify high-intent signals and improve targeting.' },
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
                        href={route('google-api.verify')}
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
