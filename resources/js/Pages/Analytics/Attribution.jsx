import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

const MODEL_INFO = {
    last_click: { name: 'Last Click', description: '100% credit to the final touchpoint before conversion.' },
    first_click: { name: 'First Click', description: '100% credit to the first touchpoint in the journey.' },
    linear: { name: 'Linear', description: 'Equal credit distributed across all touchpoints.' },
    time_decay: { name: 'Time Decay', description: 'More credit to recent touchpoints (7-day half-life).' },
    position_based: { name: 'Position Based', description: '40% first touch, 40% last touch, 20% distributed among middle.' },
};

function TouchpointTimeline({ touchpoints }) {
    return (
        <div className="flex items-center gap-1 overflow-x-auto py-4">
            {touchpoints.map((tp, i) => (
                <div key={i} className="flex items-center">
                    <div className="flex flex-col items-center min-w-[120px]">
                        <div className="w-10 h-10 rounded-full bg-flame-orange-100 text-flame-orange-700 flex items-center justify-center text-xs font-bold">{i + 1}</div>
                        <p className="text-xs font-medium text-gray-900 mt-1">{tp.channel}</p>
                        <p className="text-[10px] text-gray-500">{tp.campaign}</p>
                    </div>
                    {i < touchpoints.length - 1 && (
                        <div className="w-8 h-px bg-gray-300 mx-1" />
                    )}
                </div>
            ))}
            <div className="flex flex-col items-center min-w-[80px]">
                <div className="w-10 h-10 rounded-full bg-green-100 text-green-700 flex items-center justify-center text-xs font-bold">$</div>
                <p className="text-xs font-medium text-green-700 mt-1">Conversion</p>
            </div>
        </div>
    );
}

function ModelCard({ modelKey, attributions }) {
    const info = MODEL_INFO[modelKey] || { name: modelKey, description: '' };
    if (!attributions || attributions.length === 0) return null;

    const maxCredit = Math.max(...attributions.map((a) => a.credit || 0));

    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            <h3 className="text-sm font-semibold text-gray-900">{info.name}</h3>
            <p className="text-xs text-gray-500 mb-3">{info.description}</p>
            <div className="space-y-2">
                {attributions.map((a, i) => (
                    <div key={i} className="flex items-center gap-3">
                        <span className="text-xs text-gray-600 w-32 truncate">{a.channel || `Touch ${i + 1}`}</span>
                        <div className="flex-1 bg-gray-200 rounded-full h-3">
                            <div
                                className="bg-flame-orange-500 h-3 rounded-full"
                                style={{ width: `${maxCredit > 0 ? (a.credit / maxCredit * 100) : 0}%` }}
                            />
                        </div>
                        <span className="text-xs font-medium text-gray-900 w-16 text-right">
                            {((a.credit || 0) * 100).toFixed(1)}%
                        </span>
                        <span className="text-xs text-gray-500 w-16 text-right">
                            ${(a.value || 0).toFixed(2)}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function Attribution({ models = {}, touchpoints = [] }) {
    const modelKeys = Object.keys(models);

    return (
        <AuthenticatedLayout>
            <Head title="Attribution Models" />
            <div className="py-8">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    <a href={route('analytics.index')} className="text-sm text-flame-orange-600 hover:underline mb-1 inline-block">← Back to Analytics</a>
                    <h1 className="text-2xl font-bold text-gray-900 mb-1">Attribution Models</h1>
                    <p className="text-sm text-gray-500 mb-6">Compare how different attribution models distribute conversion credit across touchpoints.</p>

                    {/* Journey Timeline */}
                    {touchpoints.length > 0 && (
                        <div className="bg-white rounded-lg border border-gray-200 p-6 mb-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-2">Sample Conversion Journey</h2>
                            <TouchpointTimeline touchpoints={touchpoints} />
                        </div>
                    )}

                    {/* Attribution Models */}
                    {modelKeys.length > 0 ? (
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            {modelKeys.map((key) => (
                                <ModelCard key={key} modelKey={key} attributions={models[key]} />
                            ))}
                        </div>
                    ) : (
                        <div className="bg-white rounded-lg border border-gray-200 p-12 text-center">
                            <p className="text-gray-500">No attribution data available. Set up conversion tracking to see attribution models.</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
