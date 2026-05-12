import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const platformColors = {
    google: { bg: 'bg-blue-50', border: 'border-blue-200', text: 'text-blue-700', badge: 'bg-blue-100 text-blue-800' },
    facebook: { bg: 'bg-indigo-50', border: 'border-indigo-200', text: 'text-indigo-700', badge: 'bg-indigo-100 text-indigo-800' },
    linkedin: { bg: 'bg-sky-50', border: 'border-sky-200', text: 'text-sky-700', badge: 'bg-sky-100 text-sky-800' },
    microsoft: { bg: 'bg-emerald-50', border: 'border-emerald-200', text: 'text-emerald-700', badge: 'bg-emerald-100 text-emerald-800' },
};

function ScenarioCard({ scenarioKey, scenario }) {
    const colors = platformColors[scenario.platform] || platformColors.google;

    return (
        <div className={`rounded-lg border ${colors.border} ${colors.bg} p-5`}>
            <div className="flex items-start justify-between mb-3">
                <h3 className={`font-semibold ${colors.text}`}>{scenario.name}</h3>
                <span className={`text-xs font-medium px-2 py-1 rounded-full ${colors.badge}`}>
                    {scenario.platform}
                </span>
            </div>
            <p className="text-sm text-gray-600 mb-3">{scenario.reason}</p>
            <div className="flex items-center gap-4 text-xs text-gray-500 mb-3">
                <span>Budget: ${scenario.daily_budget}/day</span>
                <span>CTR: {(scenario.metrics.ctr * 100).toFixed(1)}%</span>
                <span>CPA: ${scenario.metrics.cpa}</span>
            </div>
            {scenario.problem && (
                <div className="bg-red-50 border border-red-200 rounded p-3 text-sm text-red-700">
                    <strong>Problem:</strong> {scenario.problem}
                </div>
            )}
            {!scenario.problem && (
                <div className="bg-green-50 border border-green-200 rounded p-3 text-sm text-green-700">
                    Healthy campaign — baseline for comparison
                </div>
            )}
        </div>
    );
}

export default function SandboxIndex({ scenarios, existingSandbox }) {
    const handleLaunch = () => {
        router.post(route('sandbox.launch'));
    };

    const handleDelete = () => {
        if (confirm('This will delete your sandbox and all its data. Continue?')) {
            router.delete(route('sandbox.destroy', existingSandbox.id));
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Sandbox Simulation</h2>}
        >
            <Head title="Sandbox Simulation" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                    {/* Hero */}
                    <div className="bg-gradient-to-r from-violet-600 to-indigo-600 rounded-xl p-8 text-white">
                        <h1 className="text-2xl font-bold mb-2">Test Our AI Agents — Risk Free</h1>
                        <p className="text-violet-100 max-w-2xl">
                            Launch a sandbox environment with 5 realistic ad campaigns across Google, Facebook,
                            LinkedIn, and Microsoft. Our AI agents will analyze the data and show you exactly how
                            they optimize, alert, and heal campaigns — no real ad spend required.
                        </p>
                    </div>

                    {/* Existing Sandbox */}
                    {existingSandbox && (
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h3 className="font-semibold text-gray-900">Active Sandbox</h3>
                                    <p className="text-sm text-gray-500">
                                        {existingSandbox.campaign_count} campaigns •
                                        Expires {new Date(existingSandbox.expires_at).toLocaleDateString()}
                                    </p>
                                </div>
                                <div className="flex gap-3">
                                    <a
                                        href={route('sandbox.results', existingSandbox.id)}
                                        className="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition"
                                    >
                                        {existingSandbox.has_results ? 'View Results' : 'Check Progress'}
                                    </a>
                                    <button
                                        onClick={handleDelete}
                                        className="inline-flex items-center px-4 py-2 bg-white border border-red-300 text-red-700 text-sm font-medium rounded-lg hover:bg-red-50 transition"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Scenarios */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 className="text-lg font-semibold text-gray-900 mb-1">Campaign Scenarios</h2>
                        <p className="text-sm text-gray-500 mb-6">
                            Each sandbox includes these 5 campaigns, each designed to trigger different agent behaviors.
                        </p>

                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            {Object.entries(scenarios).map(([key, scenario]) => (
                                <ScenarioCard key={key} scenarioKey={key} scenario={scenario} />
                            ))}
                        </div>
                    </div>

                    {/* Agents */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 className="text-lg font-semibold text-gray-900 mb-4">Agents That Will Run</h2>
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            {[
                                { name: 'Health Check Agent', desc: 'Diagnoses overall account health and flags systemic issues' },
                                { name: 'Campaign Alert Service', desc: 'Detects budget overspend, performance drops, and anomalies' },
                                { name: 'Optimization Agent', desc: 'AI-powered analysis with confidence-scored recommendations' },
                                { name: 'Budget Intelligence', desc: 'Time-of-day and day-of-week budget multiplier optimization' },
                                { name: 'Search Term Mining', desc: 'Finds high-value keywords and negative keyword opportunities' },
                                { name: 'Creative Intelligence', desc: 'Identifies winning/losing ad creative patterns' },
                                { name: 'Self-Optimising Agent', desc: 'Automatically detects and fixes common campaign problems' },
                            ].map((agent) => (
                                <div key={agent.name} className="border border-gray-200 rounded-lg p-4">
                                    <h4 className="font-medium text-gray-900 text-sm">{agent.name}</h4>
                                    <p className="text-xs text-gray-500 mt-1">{agent.desc}</p>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Launch Button */}
                    {!existingSandbox && (
                        <div className="flex justify-center">
                            <button
                                onClick={handleLaunch}
                                className="inline-flex items-center px-8 py-3 bg-violet-600 text-white text-base font-semibold rounded-xl hover:bg-violet-700 shadow-lg shadow-violet-200 transition"
                            >
                                Launch Sandbox Environment
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
