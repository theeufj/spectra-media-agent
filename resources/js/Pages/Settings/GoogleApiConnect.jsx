import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';

const SCOPE_LABELS = {
    'https://www.googleapis.com/auth/adwords':                      { name: 'Google Ads', desc: 'Create and manage ad campaigns, read performance data' },
    'https://www.googleapis.com/auth/tagmanager.publish':           { name: 'Tag Manager — Publish', desc: 'Publish container versions to live environments' },
    'https://www.googleapis.com/auth/tagmanager.edit.containers':   { name: 'Tag Manager — Edit', desc: 'Create and edit tags, triggers, and variables' },
    'https://www.googleapis.com/auth/tagmanager.readonly':          { name: 'Tag Manager — Read', desc: 'Read container configuration and versions' },
    'https://www.googleapis.com/auth/analytics.edit':               { name: 'Google Analytics — Edit', desc: 'Manage GA4 properties, streams, and conversion events' },
    'https://www.googleapis.com/auth/analytics.readonly':           { name: 'Google Analytics — Read', desc: 'Read Analytics reports and performance data' },
};

function ScopeRow({ uri }) {
    const label = SCOPE_LABELS[uri] ?? { name: uri, desc: '' };
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

export default function GoogleApiConnect({ connection }) {
    const { errors } = usePage().props;

    const allScopes = Object.keys(SCOPE_LABELS);

    return (
        <AuthenticatedLayout>
            <Head title="Connect Google APIs" />

            <div className="max-w-2xl mx-auto px-4 py-10">
                {/* Header */}
                <div className="flex items-center gap-3 mb-8">
                    <div className="w-10 h-10 rounded-xl bg-white border border-gray-200 flex items-center justify-center shadow-sm">
                        <svg className="w-5 h-5" viewBox="0 0 24 24" fill="none">
                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#FBBC05"/>
                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                        </svg>
                    </div>
                    <div>
                        <h1 className="text-xl font-bold text-gray-900">Connect Google APIs</h1>
                        <p className="text-sm text-gray-500">Required for API access verification</p>
                    </div>
                </div>

                {errors?.oauth && (
                    <div className="mb-6 bg-red-50 border border-red-200 rounded-lg px-4 py-3 text-sm text-red-700">
                        {errors.oauth}
                    </div>
                )}

                {/* Already connected banner */}
                {connection && (
                    <div className="mb-6 bg-green-50 border border-green-200 rounded-xl p-4 flex items-start gap-3">
                        <div className="w-5 h-5 rounded-full bg-green-500 flex items-center justify-center flex-shrink-0 mt-0.5">
                            <svg className="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </div>
                        <div>
                            <p className="text-sm font-semibold text-green-800">Google APIs connected</p>
                            <p className="text-xs text-green-600 mt-0.5">
                                Last authorised {new Date(connection.connected_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' })}
                            </p>
                        </div>
                    </div>
                )}

                {/* Scope list */}
                <div className="bg-white border border-gray-200 rounded-xl overflow-hidden mb-6">
                    <div className="px-5 py-4 border-b border-gray-100 bg-gray-50">
                        <p className="text-xs font-semibold text-gray-500 uppercase tracking-wide">Permissions requested</p>
                    </div>
                    <div className="px-5">
                        {allScopes.map(scope => <ScopeRow key={scope} uri={scope} />)}
                    </div>
                </div>

                {/* Why section */}
                <div className="bg-blue-50 border border-blue-100 rounded-xl px-5 py-4 mb-8">
                    <p className="text-xs font-semibold text-blue-700 uppercase tracking-wide mb-2">Why these permissions?</p>
                    <p className="text-sm text-blue-800 leading-relaxed">
                        SiteToSpend's Spectra agents manage Google Ads campaigns, publish GTM containers, and read GA4 performance data on your behalf. These scopes are required by Google for Standard API access verification.
                    </p>
                </div>

                {/* Action buttons */}
                <div className="flex gap-3">
                    <a
                        href={route('google-api.redirect')}
                        className="flex-1 flex items-center justify-center gap-2 bg-gray-900 hover:bg-gray-800 text-white text-sm font-semibold px-6 py-3 rounded-xl transition-colors"
                    >
                        <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none">
                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#fff"/>
                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#fff" fillOpacity=".7"/>
                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z" fill="#fff" fillOpacity=".5"/>
                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#fff" fillOpacity=".3"/>
                        </svg>
                        {connection ? 'Re-authorise with Google' : 'Authorise with Google'}
                    </a>
                    {connection && (
                        <button
                            onClick={() => { if (confirm('Remove Google API connection?')) router.post(route('google-api.disconnect')); }}
                            className="px-5 py-3 text-sm text-red-600 hover:bg-red-50 rounded-xl border border-red-200 transition-colors"
                        >
                            Disconnect
                        </button>
                    )}
                </div>

                <p className="text-xs text-gray-400 text-center mt-4">
                    You will be redirected to Google's authorisation screen. SiteToSpend never shares your credentials.
                </p>
            </div>
        </AuthenticatedLayout>
    );
}
