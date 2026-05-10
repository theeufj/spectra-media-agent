import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const SCOPE_LABELS = {
    'https://www.googleapis.com/auth/adwords':                      'Google Ads — Campaign management',
    'https://www.googleapis.com/auth/tagmanager.publish':           'Tag Manager — Publish containers',
    'https://www.googleapis.com/auth/tagmanager.edit.containers':   'Tag Manager — Edit containers',
    'https://www.googleapis.com/auth/tagmanager.readonly':          'Tag Manager — Read access',
    'https://www.googleapis.com/auth/analytics.edit':               'Google Analytics — Edit properties',
    'https://www.googleapis.com/auth/analytics.readonly':           'Google Analytics — Read reports',
};

const API_GROUPS = [
    {
        id: 'google_ads',
        name: 'Google Ads API',
        icon: (
            <svg className="w-5 h-5" viewBox="0 0 40 40" fill="none">
                <circle cx="20" cy="20" r="20" fill="#FBBC04"/>
                <path d="M12 28l8-16 8 16H12z" fill="#fff" fillOpacity=".9"/>
            </svg>
        ),
        scopeKeys: ['https://www.googleapis.com/auth/adwords'],
        description: 'Create campaigns, ad groups, keywords and bid strategies',
    },
    {
        id: 'tag_manager',
        name: 'Google Tag Manager API',
        icon: (
            <svg className="w-5 h-5" viewBox="0 0 40 40" fill="none">
                <circle cx="20" cy="20" r="20" fill="#4285F4"/>
                <rect x="12" y="12" width="7" height="7" rx="1.5" fill="#fff"/>
                <rect x="21" y="12" width="7" height="7" rx="1.5" fill="#fff" fillOpacity=".6"/>
                <rect x="12" y="21" width="7" height="7" rx="1.5" fill="#fff" fillOpacity=".6"/>
                <rect x="21" y="21" width="7" height="7" rx="1.5" fill="#fff" fillOpacity=".3"/>
            </svg>
        ),
        scopeKeys: [
            'https://www.googleapis.com/auth/tagmanager.publish',
            'https://www.googleapis.com/auth/tagmanager.edit.containers',
            'https://www.googleapis.com/auth/tagmanager.readonly',
        ],
        description: 'Publish tracking containers and manage tag configurations',
    },
    {
        id: 'analytics',
        name: 'Google Analytics API',
        icon: (
            <svg className="w-5 h-5" viewBox="0 0 40 40" fill="none">
                <circle cx="20" cy="20" r="20" fill="#34A853"/>
                <rect x="11" y="24" width="5" height="6" rx="1" fill="#fff"/>
                <rect x="18" y="18" width="5" height="12" rx="1" fill="#fff" fillOpacity=".8"/>
                <rect x="25" y="12" width="5" height="18" rx="1" fill="#fff" fillOpacity=".6"/>
            </svg>
        ),
        scopeKeys: [
            'https://www.googleapis.com/auth/analytics.edit',
            'https://www.googleapis.com/auth/analytics.readonly',
        ],
        description: 'Read GA4 performance reports and manage properties',
    },
];

function ApiGroup({ group, grantedScopes }) {
    const allGranted = group.scopeKeys.every(k => grantedScopes.includes(k));

    return (
        <div className={`bg-white border rounded-xl overflow-hidden ${allGranted ? 'border-green-200' : 'border-gray-200'}`}>
            <div className={`px-5 py-4 flex items-center gap-3 ${allGranted ? 'bg-green-50' : 'bg-gray-50'}`}>
                <div className="w-8 h-8 rounded-lg overflow-hidden flex-shrink-0">
                    {group.icon}
                </div>
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

export default function GoogleApiSuccess({ account_name, account_id, scopes, connected_at }) {
    const grantedScopes = scopes ?? [];
    const allScopeKeys = Object.keys(SCOPE_LABELS);
    const allGranted = allScopeKeys.every(k => grantedScopes.includes(k));

    return (
        <AuthenticatedLayout>
            <Head title="Google APIs Connected" />

            <div className="max-w-2xl mx-auto px-4 py-10">
                {/* Hero status */}
                <div className={`rounded-2xl px-6 py-8 text-center mb-8 ${allGranted ? 'bg-green-600' : 'bg-amber-500'}`}>
                    <div className="w-16 h-16 rounded-full bg-white/20 flex items-center justify-center mx-auto mb-4">
                        {allGranted ? (
                            <svg className="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        ) : (
                            <svg className="w-8 h-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
                            </svg>
                        )}
                    </div>
                    <h1 className="text-2xl font-bold text-white mb-1">
                        {allGranted ? 'Google APIs connected' : 'Partial access granted'}
                    </h1>
                    <p className="text-white/80 text-sm">
                        {account_name} · {account_id}
                    </p>
                    <p className="text-white/60 text-xs mt-1">
                        Authorised {new Date(connected_at).toLocaleString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })}
                    </p>
                </div>

                {/* Verification statement — prominent for screen recording */}
                {allGranted && (
                    <div className="bg-white border-2 border-green-300 rounded-xl px-5 py-4 mb-6">
                        <p className="text-xs font-bold text-green-700 uppercase tracking-wide mb-1">API Access Verification</p>
                        <p className="text-sm text-gray-800 leading-relaxed">
                            SiteToSpend has been granted OAuth 2.0 access to the Google Ads API, Google Tag Manager API, and Google Analytics API for account <strong>{account_id}</strong>. All required scopes for Standard Access have been authorised.
                        </p>
                    </div>
                )}

                {/* Per-API breakdown */}
                <div className="space-y-3 mb-8">
                    {API_GROUPS.map(group => (
                        <ApiGroup key={group.id} group={group} grantedScopes={grantedScopes} />
                    ))}
                </div>

                {/* Footer actions */}
                <div className="flex gap-3">
                    <Link
                        href={route('google-api.verify')}
                        className="flex-1 flex items-center justify-center gap-2 bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-6 py-3 rounded-xl transition-colors"
                    >
                        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                        Verify live API access
                    </Link>
                    <Link
                        href={route('google-api.show')}
                        className="px-5 py-3 text-sm text-gray-600 hover:bg-gray-100 rounded-xl border border-gray-200 transition-colors"
                    >
                        Manage
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
