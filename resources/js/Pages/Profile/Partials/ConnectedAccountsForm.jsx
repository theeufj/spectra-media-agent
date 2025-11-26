import { router } from '@inertiajs/react';
import { useState } from 'react';

const platformInfo = {
    google: {
        name: 'Google',
        description: 'Google Ads & Tag Manager access',
        icon: (
            <svg className="w-6 h-6" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
        ),
        color: 'bg-white',
        connectUrl: '/auth/google/redirect',
    },
    facebook: {
        name: 'Facebook',
        description: 'Facebook login',
        icon: (
            <svg className="w-6 h-6" viewBox="0 0 24 24" fill="#1877F2">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
        ),
        color: 'bg-white',
        connectUrl: '/auth/facebook/redirect',
    },
    facebook_ads: {
        name: 'Facebook Ads',
        description: 'Facebook Ads Manager access',
        icon: (
            <svg className="w-6 h-6" viewBox="0 0 24 24" fill="#1877F2">
                <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
            </svg>
        ),
        color: 'bg-white',
        connectUrl: '/auth/facebook-ads/redirect',
    },
    google_ads: {
        name: 'Google Ads',
        description: 'Google Ads account access',
        icon: (
            <svg className="w-6 h-6" viewBox="0 0 24 24">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
        ),
        color: 'bg-white',
        connectUrl: '/auth/google/redirect',
    },
};

export default function ConnectedAccountsForm({ connections = [], className = '' }) {
    const [disconnecting, setDisconnecting] = useState(null);

    const handleDisconnect = (connectionId) => {
        if (!confirm('Are you sure you want to disconnect this account? You may need to reconnect it to use certain features.')) {
            return;
        }

        setDisconnecting(connectionId);
        router.delete(route('profile.disconnect', connectionId), {
            preserveScroll: true,
            onFinish: () => setDisconnecting(null),
        });
    };

    // Check which platforms are connected
    const connectedPlatforms = connections.map(c => c.platform);
    const hasGoogle = connectedPlatforms.includes('google') || connectedPlatforms.includes('google_ads');
    const hasFacebookAds = connectedPlatforms.includes('facebook_ads');

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">Connected Ad Accounts</h2>
                <p className="mt-1 text-sm text-gray-600">
                    Connect your advertising accounts to create and manage campaigns. These connections allow Spectra to manage your ads on your behalf.
                </p>
            </header>

            {/* Prominent CTA if no ad accounts connected */}
            {!hasGoogle && !hasFacebookAds && (
                <div className="mt-6 p-6 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-lg text-white">
                    <div className="flex items-start gap-4">
                        <div className="flex-shrink-0">
                            <svg className="w-10 h-10 text-white opacity-90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <div className="flex-1">
                            <h3 className="text-xl font-semibold">Connect Your Ad Accounts</h3>
                            <p className="mt-2 text-indigo-100">
                                To create and manage advertising campaigns, you need to connect at least one ad platform. 
                                Connect your Google or Facebook account to get started.
                            </p>
                            <div className="mt-4 flex flex-wrap gap-3">
                                <a
                                    href="/auth/google/redirect"
                                    className="inline-flex items-center gap-2 px-4 py-2 bg-white text-gray-800 rounded-lg font-medium hover:bg-gray-100 transition-colors"
                                >
                                    <svg className="w-5 h-5" viewBox="0 0 24 24">
                                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                                    </svg>
                                    Connect Google Ads
                                </a>
                                <a
                                    href="/auth/facebook-ads/redirect"
                                    className="inline-flex items-center gap-2 px-4 py-2 bg-white text-gray-800 rounded-lg font-medium hover:bg-gray-100 transition-colors"
                                >
                                    <svg className="w-5 h-5" viewBox="0 0 24 24" fill="#1877F2">
                                        <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                    </svg>
                                    Connect Facebook Ads
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Connected accounts list */}
            <div className="mt-6 space-y-4">
                {connections.length > 0 ? (
                    connections.map((connection) => {
                        const info = platformInfo[connection.platform] || {
                            name: connection.platform,
                            description: 'Connected account',
                            icon: null,
                            color: 'bg-gray-100',
                        };

                        return (
                            <div
                                key={connection.id}
                                className={`flex items-center justify-between p-4 border rounded-lg ${
                                    connection.is_expired ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-white'
                                }`}
                            >
                                <div className="flex items-center gap-4">
                                    <div className={`p-2 rounded-lg ${info.color} border border-gray-200`}>
                                        {info.icon}
                                    </div>
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h4 className="font-medium text-gray-900">{info.name}</h4>
                                            {connection.is_expired ? (
                                                <span className="px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800 rounded-full">
                                                    Expired
                                                </span>
                                            ) : (
                                                <span className="px-2 py-0.5 text-xs font-medium bg-green-100 text-green-800 rounded-full">
                                                    Connected
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-sm text-gray-500">
                                            {connection.account_name || info.description}
                                        </p>
                                        <p className="text-xs text-gray-400 mt-1">
                                            Connected {connection.connected_at}
                                            {connection.expires_at && !connection.is_expired && (
                                                <> · Expires {connection.expires_at}</>
                                            )}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    {connection.is_expired && info.connectUrl && (
                                        <a
                                            href={info.connectUrl}
                                            className="px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700"
                                        >
                                            Reconnect
                                        </a>
                                    )}
                                    <button
                                        onClick={() => handleDisconnect(connection.id)}
                                        disabled={disconnecting === connection.id}
                                        className="px-3 py-1.5 text-sm font-medium text-red-600 bg-red-50 rounded-md hover:bg-red-100 disabled:opacity-50"
                                    >
                                        {disconnecting === connection.id ? 'Disconnecting...' : 'Disconnect'}
                                    </button>
                                </div>
                            </div>
                        );
                    })
                ) : null}

                {/* Available connections to add */}
                {(hasGoogle || hasFacebookAds) && (
                    <div className="pt-4 border-t border-gray-200">
                        <h4 className="text-sm font-medium text-gray-700 mb-3">Add More Accounts</h4>
                        <div className="flex flex-wrap gap-3">
                            {!hasGoogle && (
                                <a
                                    href="/auth/google/redirect"
                                    className="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                                >
                                    <svg className="w-5 h-5" viewBox="0 0 24 24">
                                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                                    </svg>
                                    Connect Google Ads
                                </a>
                            )}
                            {!hasFacebookAds && (
                                <a
                                    href="/auth/facebook-ads/redirect"
                                    className="inline-flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"
                                >
                                    <svg className="w-5 h-5" viewBox="0 0 24 24" fill="#1877F2">
                                        <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/>
                                    </svg>
                                    Connect Facebook Ads
                                </a>
                            )}
                        </div>
                    </div>
                )}
            </div>
        </section>
    );
}
