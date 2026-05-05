import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import SideNav from './SideNav';

const Badge = ({ children, color = 'gray' }) => {
    const colors = {
        green:  'bg-green-100 text-green-800',
        yellow: 'bg-yellow-100 text-yellow-800',
        blue:   'bg-blue-100 text-blue-800',
        gray:   'bg-gray-100 text-gray-500',
    };
    return (
        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${colors[color]}`}>
            {children}
        </span>
    );
};

const CopyButton = ({ text }) => {
    const [copied, setCopied] = useState(false);
    const copy = () => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };
    return (
        <button onClick={copy} className="ml-2 text-gray-400 hover:text-gray-600 transition-colors" title="Copy">
            {copied
                ? <svg className="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg>
                : <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
            }
        </button>
    );
};

export default function ConversionTracking({ aw_id, actions, attribution, signups_7d, signups_30d, customer_id }) {
    const provisioned = actions.filter(a => a.provisioned).length;

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Conversion Tracking</h2>}>
            <Head title="Conversion Tracking — Admin" />
            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8 space-y-8">

                    {/* Summary bar */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
                        <StatCard label="Google Ads Account" value={aw_id} small />
                        <StatCard label="Actions Provisioned" value={`${provisioned} / ${actions.length}`} />
                        <StatCard label="Signups (7d)" value={signups_7d} />
                        <StatCard label="Signups (30d)" value={signups_30d} />
                    </div>

                    {/* Conversion actions table */}
                    <div className="bg-white rounded-lg shadow overflow-hidden">
                        <div className="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-medium text-gray-900">Spectra Conversion Actions</h3>
                                <p className="text-sm text-gray-500 mt-0.5">
                                    Tracking sitetospend.com's own ad conversions — not customer accounts.
                                </p>
                            </div>
                            {customer_id && (
                                <a
                                    href={`https://ads.google.com/aw/conversions?ocid=${customer_id}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="text-sm text-blue-600 hover:underline"
                                >
                                    Open in Google Ads ↗
                                </a>
                            )}
                        </div>
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Event</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mode</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Value</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Label / send_to</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {actions.map(action => (
                                    <tr key={action.key} className="hover:bg-gray-50">
                                        <td className="px-6 py-4">
                                            <div className="text-sm font-medium text-gray-900">{action.name}</div>
                                            <div className="text-xs text-gray-400 mt-0.5 font-mono">{action.key}</div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <Badge color={action.mode === 'client' ? 'blue' : 'gray'}>
                                                {action.mode === 'client' ? 'Client (gtag)' : 'Server (API)'}
                                            </Badge>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-700">
                                            {action.value ? `$${action.value} ${action.currency}` : '—'}
                                        </td>
                                        <td className="px-6 py-4">
                                            {action.send_to ? (
                                                <div className="flex items-center">
                                                    <code className="text-xs bg-gray-100 px-2 py-1 rounded text-gray-700 break-all">
                                                        {action.send_to}
                                                    </code>
                                                    <CopyButton text={action.send_to} />
                                                </div>
                                            ) : (
                                                <span className="text-xs text-gray-400 italic">Not provisioned</span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4">
                                            {action.provisioned
                                                ? <Badge color="green">Provisioned</Badge>
                                                : <Badge color="yellow">Missing label</Badge>
                                            }
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {provisioned < actions.length && (
                            <div className="px-6 py-4 bg-yellow-50 border-t border-yellow-100 text-sm text-yellow-800">
                                Some actions are missing labels. Run{' '}
                                <code className="font-mono bg-yellow-100 px-1 rounded">php artisan conversions:provision</code>
                                {' '}on the server to provision them.
                            </div>
                        )}
                    </div>

                    {/* Attribution conversions from DB */}
                    {Object.keys(attribution).length > 0 && (
                        <div className="bg-white rounded-lg shadow overflow-hidden">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h3 className="text-lg font-medium text-gray-900">Attribution Conversions (DB)</h3>
                                <p className="text-sm text-gray-500 mt-0.5">Server-side events recorded locally.</p>
                            </div>
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Count</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Value</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {Object.entries(attribution).map(([type, row]) => (
                                        <tr key={type} className="hover:bg-gray-50">
                                            <td className="px-6 py-4 text-sm font-medium text-gray-900 font-mono">{type}</td>
                                            <td className="px-6 py-4 text-sm text-gray-700">{row.total}</td>
                                            <td className="px-6 py-4 text-sm text-gray-700">
                                                {row.value_sum ? `$${parseFloat(row.value_sum).toFixed(2)}` : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {/* How it works */}
                    <div className="bg-white rounded-lg shadow p-6">
                        <h3 className="text-base font-medium text-gray-900 mb-3">How It Works</h3>
                        <ul className="space-y-2 text-sm text-gray-600">
                            <li><span className="font-medium text-blue-700">Client (gtag):</span> Fires in the browser via <code className="bg-gray-100 px-1 rounded">trackConversion('event')</code> when the user takes an action.</li>
                            <li><span className="font-medium text-gray-700">Server (API):</span> Uploaded via the Google Ads Conversions API when a background job runs — requires a stored <code className="bg-gray-100 px-1 rounded">gclid</code> for the user.</li>
                            <li>Labels are stored in the <code className="bg-gray-100 px-1 rounded">settings</code> table and served to the frontend on every request via Inertia shared props.</li>
                            <li>Re-provision anytime: <code className="bg-gray-100 px-1 rounded">php artisan conversions:provision</code></li>
                        </ul>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}

const StatCard = ({ label, value, small = false }) => (
    <div className="bg-white rounded-lg shadow p-4">
        <p className="text-xs text-gray-500 uppercase tracking-wider">{label}</p>
        <p className={`mt-1 font-semibold text-gray-900 ${small ? 'text-sm break-all' : 'text-2xl'}`}>{value}</p>
    </div>
);
