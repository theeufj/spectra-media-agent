import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';

const SCOPE_LABELS = {
    'ads_management':       { name: 'Ads Management',        desc: 'Create, edit, and delete ad campaigns, ad sets, and ads' },
    'ads_read':             { name: 'Ads Read',              desc: 'Read ad campaign performance, insights, and reporting data' },
    'business_management':  { name: 'Business Management',   desc: 'Manage Business Manager assets, pages, and user permissions' },
    'pages_read_engagement':{ name: 'Pages Read Engagement', desc: 'Read Page content, follower counts, and post engagement' },
    'instagram_basic':      { name: 'Instagram Basic',       desc: 'Read Instagram account information linked to Pages' },
};

const FBLogo = (
    <svg className="w-5 h-5" viewBox="0 0 24 24" fill="#1877F2">
        <path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047v-2.66c0-3.025 1.792-4.697 4.533-4.697 1.312 0 2.686.236 2.686.236v2.97h-1.513c-1.491 0-1.956.93-1.956 1.886v2.265h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>
    </svg>
);

function ScopeRow({ scopeKey }) {
    const label = SCOPE_LABELS[scopeKey] ?? { name: scopeKey, desc: '' };
    return (
        <div className="flex items-start gap-3 py-3 border-b border-gray-100 last:border-0">
            <div className="mt-0.5 w-5 h-5 rounded-full bg-blue-100 flex items-center justify-center flex-shrink-0">
                <svg className="w-3 h-3 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4" />
                </svg>
            </div>
            <div>
                <p className="text-sm font-medium text-gray-900">{label.name}</p>
                <p className="text-xs text-gray-500 mt-0.5">{label.desc}</p>
            </div>
        </div>
    );
}

export default function FacebookApiConnect({ connection }) {
    const { errors } = usePage().props;
    const allScopes = Object.keys(SCOPE_LABELS);

    const soonExpiring = connection?.expires_at && (() => {
        const expiresAt = new Date(connection.expires_at);
        const daysLeft = (expiresAt - Date.now()) / (1000 * 60 * 60 * 24);
        return daysLeft < 7 ? expiresAt.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : null;
    })();

    return (
        <AuthenticatedLayout>
            <Head title="Connect Facebook APIs" />

            <div className="max-w-2xl mx-auto px-4 py-10">
                <div className="flex items-center gap-3 mb-8">
                    <div className="w-10 h-10 rounded-xl bg-white border border-gray-200 flex items-center justify-center shadow-sm">
                        {FBLogo}
                    </div>
                    <div>
                        <h1 className="text-xl font-bold text-gray-900">Connect Facebook APIs</h1>
                        <p className="text-sm text-gray-500">Required for Meta API access verification</p>
                    </div>
                </div>

                {errors?.oauth && (
                    <div className="mb-6 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
                        {errors.oauth}
                    </div>
                )}

                {connection && (
                    <div className="mb-6 bg-green-50 border border-green-200 rounded-xl p-4 flex items-start gap-3">
                        <div className="w-5 h-5 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <div>
                            <p className="text-sm font-semibold text-green-800">Facebook APIs connected</p>
                            <p className="text-xs text-green-600 mt-0.5">
                                Last authorised {new Date(connection.connected_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })}
                            </p>
                        </div>
                    </div>
                )}

                {soonExpiring && (
                    <div className="mb-6 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800">
                        Token expires on <strong>{soonExpiring}</strong> — re-authorise to get a fresh 60-day token.
                    </div>
                )}

                <div className="bg-white border border-gray-200 rounded-xl overflow-hidden mb-6">
                    <div className="px-5 py-4 border-b border-gray-100 bg-gray-50">
                        <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Permissions requested</p>
                    </div>
                    <div className="px-5">
                        {allScopes.map(scope => <ScopeRow key={scope} scopeKey={scope} />)}
                    </div>
                </div>

                <div className="bg-blue-50 border border-blue-100 rounded-xl px-5 py-4 mb-8">
                    <p className="text-xs font-semibold text-blue-700 uppercase tracking-wide mb-2">Why these permissions?</p>
                    <p className="text-sm text-blue-800 leading-relaxed">
                        SiteToSpend's Spectra agents manage Facebook Ads campaigns and read Business Manager data on your behalf. These permissions are required by Meta for Marketing API access verification.
                    </p>
                </div>

                <div className="flex gap-3">
                    <a
                        href={route('facebook-api.redirect')}
                        className="flex-1 flex items-center justify-center gap-2 text-white text-sm font-semibold px-6 py-3 rounded-xl transition-colors"
                        style={{ backgroundColor: '#1877F2' }}
                        onMouseOver={e => e.currentTarget.style.backgroundColor = '#166FE5'}
                        onMouseOut={e => e.currentTarget.style.backgroundColor = '#1877F2'}
                    >
                        {FBLogo}
                        {connection ? 'Re-authorise with Facebook' : 'Authorise with Facebook'}
                    </a>
                    {connection && (
                        <button
                            onClick={() => { if (confirm('Remove Facebook API connection?')) router.post(route('facebook-api.disconnect')); }}
                            className="px-5 py-3 text-sm text-red-600 hover:bg-red-50 rounded-xl border border-red-200 transition-colors"
                        >
                            Disconnect
                        </button>
                    )}
                </div>

                <p className="text-xs text-gray-400 text-center mt-4">
                    You will be redirected to Facebook's authorisation screen. SiteToSpend never shares your credentials.
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
