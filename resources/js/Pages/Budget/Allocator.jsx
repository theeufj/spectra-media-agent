import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';

function PlatformCard({ name, color, data, pct }) {
    const roas = data.roas || 0;
    const roasColor = roas >= 3 ? 'text-green-600' : roas >= 1.5 ? 'text-yellow-600' : 'text-red-600';
    return (
        <div className="bg-white rounded-lg border border-gray-200 p-5">
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold text-gray-900">{name}</h3>
                <span className={`text-xs px-2 py-0.5 rounded ${color}`}>{pct}%</span>
            </div>
            <div className="grid grid-cols-2 gap-3">
                <div><p className="text-xs text-gray-500">Spend</p><p className="text-sm font-semibold">${data.spend?.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) || '0.00'}</p></div>
                <div><p className="text-xs text-gray-500">ROAS</p><p className={`text-sm font-semibold ${roasColor}`}>{roas}x</p></div>
                <div><p className="text-xs text-gray-500">Conversions</p><p className="text-sm font-semibold">{data.conversions || 0}</p></div>
                <div><p className="text-xs text-gray-500">CPA</p><p className="text-sm font-semibold">${data.cpa?.toFixed(2) || '—'}</p></div>
            </div>
            <div className="mt-3"><p className="text-xs text-gray-500">{data.campaigns} campaign{data.campaigns !== 1 ? 's' : ''} · {data.clicks?.toLocaleString() || 0} clicks</p></div>
        </div>
    );
}

export default function Allocator({ allocation, snapshot, recommendations }) {
    const { data, setData, put, processing } = useForm({
        total_monthly_budget: allocation?.total_monthly_budget || 1000,
        google_ads_pct: allocation?.google_ads_pct || 100,
        facebook_ads_pct: allocation?.facebook_ads_pct || 0,
        microsoft_ads_pct: allocation?.microsoft_ads_pct || 0,
        strategy: allocation?.strategy || 'performance',
        target_roas: allocation?.target_roas || '',
        target_cpa: allocation?.target_cpa || '',
        auto_rebalance: allocation?.auto_rebalance || false,
        rebalance_frequency: allocation?.rebalance_frequency || 'weekly',
    });

    const handleSave = (e) => {
        e.preventDefault();
        put(route('budget.update'), { preserveScroll: true });
    };

    const handleRebalance = () => {
        if (confirm('Rebalance now based on performance data?')) {
            router.post(route('budget.rebalance'), {}, { preserveScroll: true });
        }
    };

    const handleApplySuggested = () => {
        if (recommendations?.suggested_splits) {
            setData(prev => ({
                ...prev,
                google_ads_pct: recommendations.suggested_splits.google_ads_pct,
                facebook_ads_pct: recommendations.suggested_splits.facebook_ads_pct,
                microsoft_ads_pct: recommendations.suggested_splits.microsoft_ads_pct,
            }));
        }
    };

    const totalPct = parseFloat(data.google_ads_pct || 0) + parseFloat(data.facebook_ads_pct || 0) + parseFloat(data.microsoft_ads_pct || 0);

    return (
        <AuthenticatedLayout>
            <Head title="Budget Allocator" />
            <div className="py-8">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Cross-Channel Budget Allocator</h1>
                            <p className="mt-1 text-sm text-gray-500">Optimize spend across platforms based on performance.</p>
                        </div>
                        <div className="flex gap-2">
                            <a href={route('budget.history')} className="px-4 py-2 text-sm text-gray-600 border border-gray-200 rounded-lg hover:bg-gray-50">History</a>
                            <button onClick={handleRebalance} className="px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700">Rebalance Now</button>
                        </div>
                    </div>

                    {/* Platform Performance Cards */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                        <PlatformCard name="Google Ads" color="bg-blue-100 text-blue-700" data={snapshot?.google_ads || {}} pct={data.google_ads_pct} />
                        <PlatformCard name="Facebook Ads" color="bg-indigo-100 text-indigo-700" data={snapshot?.facebook_ads || {}} pct={data.facebook_ads_pct} />
                        <PlatformCard name="Microsoft Ads" color="bg-teal-100 text-teal-700" data={snapshot?.microsoft_ads || {}} pct={data.microsoft_ads_pct} />
                    </div>

                    {/* Recommendations */}
                    {recommendations?.suggested_splits && (
                        <div className="bg-amber-50 border border-amber-200 rounded-lg p-5 mb-8">
                            <div className="flex items-center justify-between mb-3">
                                <h3 className="text-sm font-semibold text-amber-900">AI Recommendation</h3>
                                {recommendations.estimated_improvement_pct > 0 && (
                                    <span className="text-xs px-2 py-0.5 bg-green-100 text-green-700 rounded">+{recommendations.estimated_improvement_pct}% ROAS</span>
                                )}
                            </div>
                            <ul className="space-y-1 mb-3">
                                {recommendations.reasons?.map((r, i) => <li key={i} className="text-sm text-amber-800">• {r}</li>)}
                            </ul>
                            <button onClick={handleApplySuggested} className="px-4 py-2 text-sm font-medium text-amber-800 bg-amber-100 rounded-lg hover:bg-amber-200">Apply Suggestion</button>
                        </div>
                    )}

                    {/* Allocation Form */}
                    <form onSubmit={handleSave} className="bg-white rounded-lg border border-gray-200 p-6">
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Total Monthly Budget</label>
                                <div className="relative">
                                    <span className="absolute left-3 top-2.5 text-gray-400">$</span>
                                    <input type="number" step="0.01" value={data.total_monthly_budget} onChange={e => setData('total_monthly_budget', e.target.value)} className="w-full pl-7 rounded-lg border-gray-300 text-sm" />
                                </div>
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Strategy</label>
                                <select value={data.strategy} onChange={e => setData('strategy', e.target.value)} className="w-full rounded-lg border-gray-300 text-sm">
                                    <option value="performance">Performance-Based</option>
                                    <option value="roas_target">ROAS Target</option>
                                    <option value="equal">Equal Split</option>
                                    <option value="manual">Manual</option>
                                </select>
                            </div>
                        </div>

                        <div className="mt-6">
                            <div className="flex items-center justify-between mb-2">
                                <label className="text-sm font-medium text-gray-700">Platform Split</label>
                                <span className={`text-xs ${Math.abs(totalPct - 100) > 0.5 ? 'text-red-500' : 'text-green-600'}`}>{totalPct.toFixed(1)}%</span>
                            </div>
                            {/* Stacked bar */}
                            <div className="flex h-4 rounded-full overflow-hidden mb-4">
                                <div className="bg-blue-500" style={{width: `${data.google_ads_pct}%`}} />
                                <div className="bg-indigo-500" style={{width: `${data.facebook_ads_pct}%`}} />
                                <div className="bg-teal-500" style={{width: `${data.microsoft_ads_pct}%`}} />
                            </div>
                            <div className="grid grid-cols-3 gap-4">
                                <div>
                                    <label className="block text-xs text-blue-600 mb-1">Google Ads %</label>
                                    <input type="number" step="0.1" min="0" max="100" value={data.google_ads_pct} onChange={e => setData('google_ads_pct', parseFloat(e.target.value) || 0)} className="w-full rounded-lg border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <label className="block text-xs text-indigo-600 mb-1">Facebook Ads %</label>
                                    <input type="number" step="0.1" min="0" max="100" value={data.facebook_ads_pct} onChange={e => setData('facebook_ads_pct', parseFloat(e.target.value) || 0)} className="w-full rounded-lg border-gray-300 text-sm" />
                                </div>
                                <div>
                                    <label className="block text-xs text-teal-600 mb-1">Microsoft Ads %</label>
                                    <input type="number" step="0.1" min="0" max="100" value={data.microsoft_ads_pct} onChange={e => setData('microsoft_ads_pct', parseFloat(e.target.value) || 0)} className="w-full rounded-lg border-gray-300 text-sm" />
                                </div>
                            </div>
                        </div>

                        {data.strategy === 'roas_target' && (
                            <div className="mt-4">
                                <label className="block text-sm font-medium text-gray-700 mb-1">Target ROAS</label>
                                <input type="number" step="0.1" min="0" value={data.target_roas} onChange={e => setData('target_roas', e.target.value)} placeholder="e.g. 3.0" className="w-48 rounded-lg border-gray-300 text-sm" />
                            </div>
                        )}

                        <div className="mt-6 flex items-center gap-4">
                            <label className="flex items-center gap-2">
                                <input type="checkbox" checked={data.auto_rebalance} onChange={e => setData('auto_rebalance', e.target.checked)} className="rounded border-gray-300 text-flame-orange-600" />
                                <span className="text-sm text-gray-700">Auto-rebalance</span>
                            </label>
                            {data.auto_rebalance && (
                                <select value={data.rebalance_frequency} onChange={e => setData('rebalance_frequency', e.target.value)} className="rounded-lg border-gray-300 text-sm">
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            )}
                        </div>

                        <div className="mt-6 flex justify-end">
                            <button type="submit" disabled={processing} className="px-6 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 disabled:opacity-50">
                                {processing ? 'Saving...' : 'Save Allocation'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
