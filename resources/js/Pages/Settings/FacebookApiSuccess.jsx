import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const SCOPE_LABELS = {
    'ads_management':        'Meta Ads — Create and manage campaigns',
    'ads_read':              'Meta Ads — Read performance data',
    'business_management':   'Business Manager — Manage assets and permissions',
    'pages_read_engagement': 'Pages — Read content and engagement',
    'instagram_basic':       'Instagram — Read linked account info',
};

const API_GROUPS = [
    {
        id: 'ads',
        name: 'Meta Ads API',
        icon: (
            <svg viewBox="0 0 40 40" fill="none" className="w-8 h-8">
                <rect width="40" height="40" rx="8" fill="#1877F2"/>
                <path d="M28 12c-4.418 0-8 3.582-8 8 0 3.866 2.746 7.09 6.4 7.84V23h-2v-3h2v-2.3c0-2.13 1.27-3.3 3.2-3.3.92 0 1.88.17 1.88.17v2.1h-1.06c-1.04 0-1.36.65-1.36 1.31V20h2.32l-.37 3h-1.95v4.84C25.254 27.09 28 23.866 28 20c0-4.418-3.582-8-8-8z" fill="#fff"/>
            </svg>
        ),
        scopeKeys: ['ads_management', 'ads_read'],
        description: 'Create campaigns, ad sets, ads and read performance insights',
    },
    {
        id: 'business',
        name: 'Business Management API',
        icon: (
            <svg viewBox="0 0 40 40" fill="none" className="w-8 h-8">
                <rect width="40" height="40" rx="8" fill="#0866FF"/>
                <rect x="10" y="15" width="20" height="14" rx="2" fill="#fff" fillOpacity=".9"/>
                <rect x="15" y="11" width="10" height="6" rx="2" fill="#fff" fillOpacity=".6"/>
                <rect x="17" y="22" width="6" height="7" rx="1" fill="#0866FF" fillOpacity=".4"/>
            </svg>
        ),
        scopeKeys: ['business_management'],
        description: 'Access Business Manager accounts, assets, and permissions',
    },
    {
        id: 'pages_instagram',
        name: 'Pages & Instagram API',
        icon: (
            <svg viewBox="0 0 40 40" fill="none" className="w-8 h-8">
                <rect width="40" height="40" rx="8" fill="url(#ig-grad)"/>
                <defs>
                    <linearGradient id="ig-grad" x1="0" y1="40" x2="40" y2="0">
                        <stop offset="0%" stopColor="#FFDC80"/>
                        <stop offset="30%" stopColor="#F77737"/>
                        <stop offset="60%" stopColor="#C13584"/>
                        <stop offset="100%" stopColor="#405DE6"/>
                    </linearGradient>
                </defs>
                <circle cx="20" cy="20" r="7" stroke="#fff" strokeWidth="2.5"/>
                <circle cx="27.5" cy="12.5" r="2" fill="#fff"/>
            </svg>
        ),
        scopeKeys: ['pages_read_engagement', 'instagram_basic'],
        description: 'Read Page engagement signals and Instagram account data',
    },
];

function ApiGroup({ group, grantedScopes }) {
    const allGranted = group.scopeKeys.every(k => grantedScopes.includes(k));
    return (
        <div className={`bg-white border rounded-xl overflow-hidden ${allGranted ? 'border-green-200' : 'border-gray-200'}`}>
            <div className={`px-5 py-4 flex items-center gap-3 ${allGranted ? 'bg-green-50' : 'bg-gray-50'}`}>
                <div className="w-8 h-8 rounded-lg overflow-hidden flex-shrink-0">{group.icon}</div>
                <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold text-gray-900">{group.name}</p>
                    <p className="text-xs text-gray-500 truncate">{group.description}</p>
                </div>
                {allGranted ? (
                    <div className="flex items-center gap-1.5 text-green-700 flex-shrink-0">
                        <div className="w-5 h-5 rounded-full bg-green-500 flex items-center justify-center">
                            <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <span className="text-xs font-semibold">Access granted</span>
                    </div>
                ) : (
                    <span className="text-xs font-semibold text-amber-600 flex-shrink-0">Partial</span>
                )}
            </div>
            <div className="px-5 py-2">
                {group.scopeKeys.map(key => (
                    <div key={key} className="flex items-center gap-2 py-2 border-b border-gray-50 last:border-0">
                        <div className={`w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 ${grantedScopes.includes(key) ? 'bg-green-100' : 'bg-gray-100'}`}>
                            {grantedScopes.includes(key) ? (
                                <svg className="w-2.5 h-2.5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            ) : (
                                <svg className="w-2.5 h-2.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            )}
                        </div>
                        <span className="text-xs text-gray-700">{SCOPE_LABELS[key] ?? key}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function FacebookApiSuccess({ account_name, account_id, scopes, connected_at, expires_at }) {
    const grantedScopes = scopes ?? [];
    const allGranted = Object.keys(SCOPE_LABELS).every(k => grantedScopes.includes(k));

    return (
        <AuthenticatedLayout>
            <Head title="Facebook APIs Connected" />

            <div className="max-w-2xl mx-auto px-4 py-10">
                <div className={`rounded-2xl px-6 py-8 text-center mb-8 ${allGranted ? 'bg-[#1877F2]' : 'bg-amber-500'}`}>
                    <div className="w-16 h-16 rounded-full bg-white/20 flex items-center justify-center mx-auto mb-4">
                        {allGranted ? (
                            <svg className="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        ) : (
                            <svg className="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01" />
                            </svg>
                        )}
                    </div>
                    <h1 className="text-2xl font-bold text-white mb-1">
                        {allGranted ? 'Facebook APIs connected' : 'Partial access granted'}
                    </h1>
                    <p className="text-white/80 text-sm">{account_name} · {account_id}</p>
                    <p className="text-white/60 text-xs mt-1">
                        Authorised {new Date(connected_at).toLocaleString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                    </p>
                </div>

                {allGranted && (
                    <div className="bg-white border-2 border-green-300 rounded-xl px-5 py-4 mb-4">
                        <p className="text-xs font-bold text-green-700 uppercase tracking-wide mb-1">API Access Verification</p>
                        <p className="text-sm text-gray-800 leading-relaxed">
                            SiteToSpend has been granted OAuth 2.0 access to the Meta Marketing API and Business Management API for account <strong>{account_id}</strong>. All required permissions for Marketing API verification have been authorised.
                        </p>
                    </div>
                )}

                {expires_at && (
                    <div className="bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 mb-6 text-xs text-gray-500">
                        This token expires on <strong>{new Date(expires_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })}</strong>. Facebook long-lived tokens last ~60 days and require re-authorisation.
                    </div>
                )}

                <div className="space-y-3 mb-8">
                    {API_GROUPS.map(group => (
                        <ApiGroup key={group.id} group={group} grantedScopes={grantedScopes} />
                    ))}
                </div>

                <div className="flex gap-3">
                    <Link
                        href={route('facebook-api.verify')}
                        className="flex-1 flex items-center justify-center gap-2 text-white text-sm font-semibold px-6 py-3 rounded-xl transition-colors"
                        style={{ backgroundColor: '#1877F2' }}
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Verify live API access
                    </Link>
                    <Link
                        href={route('facebook-api.show')}
                        className="px-5 py-3 text-sm text-gray-600 hover:bg-gray-100 rounded-xl border border-gray-200 transition-colors"
                    >
                        Manage
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
