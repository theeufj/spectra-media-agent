import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState, useMemo } from 'react';

const MODEL_LABELS = {
    last_click: 'Last Click',
    first_click: 'First Click',
    linear: 'Linear',
    time_decay: 'Time Decay',
    position_based: 'Position Based',
};

const MODEL_DESCRIPTIONS = {
    last_click: '100% credit to the final touchpoint before conversion.',
    first_click: '100% credit to the first touchpoint in the journey.',
    linear: 'Equal credit distributed across all touchpoints.',
    time_decay: 'More credit to touchpoints closer to conversion (7-day half-life).',
    position_based: '40% first touch, 40% last touch, 20% split among middle.',
};

const CHANNEL_COLORS = {
    'google / cpc': { bg: 'bg-blue-100', text: 'text-blue-800', bar: 'bg-blue-500' },
    'facebook / cpc': { bg: 'bg-flame-orange-100', text: 'text-flame-orange-800', bar: 'bg-flame-orange-500' },
    'google / organic': { bg: 'bg-green-100', text: 'text-green-800', bar: 'bg-green-500' },
    'direct / none': { bg: 'bg-gray-100', text: 'text-gray-800', bar: 'bg-gray-500' },
    'email / email': { bg: 'bg-yellow-100', text: 'text-yellow-800', bar: 'bg-yellow-500' },
    'referral / referral': { bg: 'bg-purple-100', text: 'text-purple-800', bar: 'bg-purple-500' },
};

function getChannelColor(channel) {
    const key = channel.toLowerCase();
    return CHANNEL_COLORS[key] || { bg: 'bg-teal-100', text: 'text-teal-800', bar: 'bg-teal-500' };
}

function formatCurrency(value) {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
}

function SummaryCards({ summary }) {
    const cards = [
        {
            label: 'Total Conversions',
            value: summary.total_conversions,
            icon: (
                <svg className="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            ),
        },
        {
            label: 'Total Value',
            value: formatCurrency(summary.total_value),
            icon: (
                <svg className="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            ),
        },
        {
            label: 'Avg. Touchpoints',
            value: summary.avg_touchpoints,
            icon: (
                <svg className="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
            ),
        },
    ];

    return (
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            {cards.map((card) => (
                <div key={card.label} className="bg-white rounded-lg shadow-md p-6 flex items-center space-x-4">
                    <div className="flex-shrink-0">{card.icon}</div>
                    <div>
                        <p className="text-sm text-gray-500">{card.label}</p>
                        <p className="text-2xl font-bold text-gray-900">{card.value}</p>
                    </div>
                </div>
            ))}
        </div>
    );
}

function ChannelBar({ channels, maxValue }) {
    if (!channels.length) {
        return <p className="text-gray-400 text-sm py-4">No data available for this model.</p>;
    }

    return (
        <div className="space-y-3">
            {channels.map((ch) => {
                const pct = maxValue > 0 ? (ch.value / maxValue) * 100 : 0;
                const colors = getChannelColor(ch.channel);
                return (
                    <div key={ch.channel}>
                        <div className="flex justify-between items-center mb-1">
                            <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${colors.bg} ${colors.text}`}>
                                {ch.channel}
                            </span>
                            <div className="flex items-center space-x-4 text-sm">
                                <span className="text-gray-500">{ch.conversions.toFixed(1)} conv.</span>
                                <span className="font-semibold text-gray-900">{formatCurrency(ch.value)}</span>
                            </div>
                        </div>
                        <div className="w-full bg-gray-100 rounded-full h-2.5">
                            <div
                                className={`h-2.5 rounded-full ${colors.bar} transition-all duration-500`}
                                style={{ width: `${Math.max(pct, 1)}%` }}
                            />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function ModelComparison({ channelBreakdown }) {
    const [selectedModel, setSelectedModel] = useState('position_based');

    const channels = channelBreakdown[selectedModel] || [];
    const maxValue = channels.reduce((max, ch) => Math.max(max, ch.value), 0);

    return (
        <div className="bg-white rounded-lg shadow-md overflow-hidden mb-8">
            <div className="bg-gradient-to-r from-flame-orange-600 to-flame-orange-700 px-6 py-4">
                <h3 className="text-lg font-semibold text-white flex items-center">
                    <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    Channel Attribution by Model
                </h3>
            </div>

            <div className="p-6">
                {/* Model tabs */}
                <div className="flex flex-wrap gap-2 mb-6">
                    {Object.entries(MODEL_LABELS).map(([key, label]) => (
                        <button
                            key={key}
                            onClick={() => setSelectedModel(key)}
                            className={`px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                                selectedModel === key
                                    ? 'bg-flame-orange-600 text-white shadow-md'
                                    : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                {/* Model description */}
                <p className="text-sm text-gray-500 mb-4 italic">
                    {MODEL_DESCRIPTIONS[selectedModel]}
                </p>

                {/* Channel bars */}
                <ChannelBar channels={channels} maxValue={maxValue} />
            </div>
        </div>
    );
}

function ModelComparisonTable({ channelBreakdown }) {
    // Collect all unique channels across all models
    const allChannels = useMemo(() => {
        const set = new Set();
        Object.values(channelBreakdown).forEach(channels => {
            channels.forEach(ch => set.add(ch.channel));
        });
        return Array.from(set).sort();
    }, [channelBreakdown]);

    if (!allChannels.length) return null;

    function getValueForChannel(model, channel) {
        const channels = channelBreakdown[model] || [];
        const found = channels.find(ch => ch.channel === channel);
        return found ? found.value : 0;
    }

    return (
        <div className="bg-white rounded-lg shadow-md overflow-hidden mb-8">
            <div className="bg-gradient-to-r from-purple-600 to-purple-700 px-6 py-4">
                <h3 className="text-lg font-semibold text-white flex items-center">
                    <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                    </svg>
                    Side-by-Side Model Comparison
                </h3>
            </div>

            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Channel</th>
                            {Object.entries(MODEL_LABELS).map(([key, label]) => (
                                <th key={key} className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">{label}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {allChannels.map((channel) => {
                            const colors = getChannelColor(channel);
                            return (
                                <tr key={channel} className="hover:bg-gray-50">
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${colors.bg} ${colors.text}`}>
                                            {channel}
                                        </span>
                                    </td>
                                    {Object.keys(MODEL_LABELS).map((model) => (
                                        <td key={model} className="px-4 py-3 whitespace-nowrap text-right text-sm font-medium text-gray-900">
                                            {formatCurrency(getValueForChannel(model, channel))}
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

function TouchpointJourney({ touchpoints }) {
    if (!touchpoints.length) {
        return (
            <div className="bg-white rounded-lg shadow-md p-6 mb-8">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Recent Touchpoints</h3>
                <p className="text-gray-400 text-sm">No touchpoints recorded yet. Install the Spectra Pixel to start tracking.</p>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-lg shadow-md overflow-hidden mb-8">
            <div className="bg-gradient-to-r from-green-600 to-green-700 px-6 py-4">
                <h3 className="text-lg font-semibold text-white flex items-center">
                    <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    Recent Touchpoints
                </h3>
            </div>

            <div className="p-6">
                <div className="relative">
                    {/* Timeline line */}
                    <div className="absolute left-4 top-0 bottom-0 w-0.5 bg-gray-200" />

                    <div className="space-y-4">
                        {touchpoints.slice(0, 20).map((tp, idx) => {
                            const colors = getChannelColor(`${tp.utm_source || 'direct'} / ${tp.utm_medium || 'none'}`);
                            return (
                                <div key={tp.id || idx} className="relative flex items-start pl-10">
                                    {/* Timeline dot */}
                                    <div className={`absolute left-2.5 w-3 h-3 rounded-full ${colors.bar} ring-2 ring-white`} />

                                    <div className="flex-1 min-w-0">
                                        <div className="flex items-center space-x-2 mb-1">
                                            <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium ${colors.bg} ${colors.text}`}>
                                                {tp.utm_source || 'direct'} / {tp.utm_medium || 'none'}
                                            </span>
                                            {tp.utm_campaign && (
                                                <span className="text-xs text-gray-400">{tp.utm_campaign}</span>
                                            )}
                                        </div>
                                        <p className="text-sm text-gray-600 truncate">{tp.page_url}</p>
                                        <p className="text-xs text-gray-400 mt-0.5">
                                            {new Date(tp.touched_at).toLocaleString()}
                                        </p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {touchpoints.length > 20 && (
                    <p className="text-sm text-gray-400 mt-4 text-center">
                        Showing 20 of {touchpoints.length} touchpoints
                    </p>
                )}
            </div>
        </div>
    );
}

function RecentConversions({ conversions }) {
    if (!conversions.length) return null;

    return (
        <div className="bg-white rounded-lg shadow-md overflow-hidden mb-8">
            <div className="bg-gradient-to-r from-amber-600 to-amber-700 px-6 py-4">
                <h3 className="text-lg font-semibold text-white flex items-center">
                    <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                    Recent Conversions
                </h3>
            </div>

            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Value</th>
                            <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Touchpoints</th>
                            <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Date</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200">
                        {conversions.map((conv, idx) => (
                            <tr key={conv.id || idx} className="hover:bg-gray-50">
                                <td className="px-4 py-3 whitespace-nowrap">
                                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                        {conv.conversion_type}
                                    </span>
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-right text-sm font-semibold text-gray-900">
                                    {formatCurrency(conv.conversion_value)}
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-center text-sm text-gray-600">
                                    {(conv.touchpoints || []).length}
                                </td>
                                <td className="px-4 py-3 whitespace-nowrap text-right text-sm text-gray-500">
                                    {new Date(conv.created_at).toLocaleDateString()}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function Attribution({ campaign, summary, channelBreakdown, recentTouchpoints, conversions }) {
    return (
        <AuthenticatedLayout>
            <Head title={`Attribution — ${campaign.name}`} />

            <div className="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
                {/* Header */}
                <div className="mb-8">
                    <Link
                        href={route('campaigns.show', campaign.id)}
                        className="text-flame-orange-600 hover:text-flame-orange-800 text-sm font-medium inline-flex items-center mb-3"
                    >
                        <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                        </svg>
                        Back to Campaign
                    </Link>
                    <h1 className="text-3xl font-bold text-gray-900">Multi-Touch Attribution</h1>
                    <p className="text-gray-500 mt-1">
                        Understand how each channel contributes to conversions for <span className="font-medium text-gray-700">{campaign.name}</span>
                    </p>
                </div>

                {/* Summary cards */}
                <SummaryCards summary={summary} />

                {/* No data state */}
                {summary.total_conversions === 0 ? (
                    <div className="bg-white rounded-lg shadow-md p-12 text-center">
                        <svg className="mx-auto h-16 w-16 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        <h3 className="mt-4 text-lg font-medium text-gray-900">No attribution data yet</h3>
                        <p className="mt-2 text-sm text-gray-500 max-w-md mx-auto">
                            Install the Spectra Pixel on your website to start tracking touchpoints and conversions.
                            Attribution data will appear here once visitors begin interacting with your campaigns.
                        </p>
                        <div className="mt-6 bg-gray-50 rounded-lg p-4 max-w-lg mx-auto text-left">
                            <p className="text-sm font-medium text-gray-700 mb-2">Add this snippet before &lt;/body&gt;:</p>
                            <pre className="text-xs bg-gray-900 text-green-400 rounded p-3 overflow-x-auto">
{`<script src="${window.location.origin}/js/spectra-pixel.js"
        data-customer="${campaign.id}"
        defer></script>`}
                            </pre>
                        </div>
                    </div>
                ) : (
                    <>
                        {/* Channel attribution by model (interactive) */}
                        <ModelComparison channelBreakdown={channelBreakdown} />

                        {/* Side-by-side table */}
                        <ModelComparisonTable channelBreakdown={channelBreakdown} />

                        {/* Recent conversions */}
                        <RecentConversions conversions={conversions} />
                    </>
                )}

                {/* Touchpoint journey always shown if data exists */}
                <TouchpointJourney touchpoints={recentTouchpoints} />
            </div>
        </AuthenticatedLayout>
    );
}
