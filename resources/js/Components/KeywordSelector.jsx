import { useState, useCallback } from 'react';

const matchTypeColors = {
    BROAD: 'bg-blue-100 text-blue-700',
    PHRASE: 'bg-purple-100 text-purple-700',
    EXACT: 'bg-green-100 text-green-700',
};

const intentColors = {
    transactional: 'bg-green-100 text-green-700',
    commercial: 'bg-blue-100 text-blue-700',
    informational: 'bg-gray-100 text-gray-600',
    navigational: 'bg-purple-100 text-purple-700',
};

export default function KeywordSelector({ value = [], onChange, landingPage = '' }) {
    const [seedInput, setSeedInput] = useState('');
    const [urlInput, setUrlInput] = useState(landingPage);
    const [maxKeywords, setMaxKeywords] = useState(20);
    const [loading, setLoading] = useState(false);
    const [suggestions, setSuggestions] = useState([]);
    const [clusters, setClusters] = useState([]);
    const [negatives, setNegatives] = useState([]);
    const [error, setError] = useState(null);
    const [manualInput, setManualInput] = useState('');
    const [manualMatchType, setManualMatchType] = useState('BROAD');
    const [activeTab, setActiveTab] = useState('research'); // 'research' | 'manual'

    const selectedTexts = new Set(value.map(k => k.text.toLowerCase()));

    const runResearch = useCallback(async () => {
        if (!seedInput.trim() && !urlInput.trim()) return;
        setLoading(true);
        setError(null);

        try {
            const response = await fetch('/keywords/inline-research', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    seed_keywords: seedInput,
                    landing_page: urlInput || undefined,
                    max_keywords: maxKeywords,
                }),
            });

            if (!response.ok) {
                const data = await response.json();
                throw new Error(data.error || 'Research failed');
            }

            const data = await response.json();
            setSuggestions(data.keywords || []);
            setClusters(data.clusters || []);
            setNegatives(data.negative_keywords || []);
        } catch (err) {
            setError(err.message);
        } finally {
            setLoading(false);
        }
    }, [seedInput, urlInput, maxKeywords]);

    const addKeyword = (kw) => {
        if (selectedTexts.has(kw.text.toLowerCase())) return;
        onChange([...value, {
            text: kw.text,
            match_type: kw.match_type || 'BROAD',
            avg_monthly_searches: kw.avg_monthly_searches ?? null,
            competition_index: kw.competition_index ?? null,
            intent: kw.intent ?? null,
            cluster: kw.cluster ?? null,
            funnel_stage: kw.funnel_stage ?? null,
        }]);
    };

    const removeKeyword = (text) => {
        onChange(value.filter(k => k.text.toLowerCase() !== text.toLowerCase()));
    };

    const addAllSuggestions = () => {
        const newKeywords = suggestions
            .filter(kw => !selectedTexts.has(kw.text.toLowerCase()))
            .map(kw => ({
                text: kw.text,
                match_type: kw.match_type || 'BROAD',
                avg_monthly_searches: kw.avg_monthly_searches ?? null,
                competition_index: kw.competition_index ?? null,
                intent: kw.intent ?? null,
                cluster: kw.cluster ?? null,
                funnel_stage: kw.funnel_stage ?? null,
            }));
        onChange([...value, ...newKeywords]);
    };

    const addManualKeyword = () => {
        const text = manualInput.trim();
        if (!text) return;
        if (selectedTexts.has(text.toLowerCase())) {
            setManualInput('');
            return;
        }
        onChange([...value, { text, match_type: manualMatchType }]);
        setManualInput('');
    };

    const updateMatchType = (text, newType) => {
        onChange(value.map(k =>
            k.text.toLowerCase() === text.toLowerCase() ? { ...k, match_type: newType } : k
        ));
    };

    const formatVolume = (v) => {
        if (v == null) return '—';
        if (v >= 1000) return `${(v / 1000).toFixed(1)}K`;
        return v.toLocaleString();
    };

    return (
        <div className="space-y-6">
            {/* Tab Toggle */}
            <div className="flex border-b border-gray-200">
                <button
                    type="button"
                    onClick={() => setActiveTab('research')}
                    className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
                        activeTab === 'research'
                            ? 'border-flame-orange-500 text-flame-orange-600'
                            : 'border-transparent text-gray-500 hover:text-gray-700'
                    }`}
                >
                    🔍 AI Research
                </button>
                <button
                    type="button"
                    onClick={() => setActiveTab('manual')}
                    className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors ${
                        activeTab === 'manual'
                            ? 'border-flame-orange-500 text-flame-orange-600'
                            : 'border-transparent text-gray-500 hover:text-gray-700'
                    }`}
                >
                    ✏️ Add Manually
                </button>
            </div>

            {activeTab === 'research' && (
                <div className="space-y-4">
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Seed Keywords</label>
                            <textarea
                                value={seedInput}
                                onChange={e => setSeedInput(e.target.value)}
                                placeholder="e.g. plumber, emergency plumbing, drain repair"
                                rows={2}
                                className="w-full rounded-lg border-gray-300 text-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                            />
                        </div>
                        <div className="space-y-3">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Landing Page URL</label>
                                <input
                                    type="url"
                                    value={urlInput}
                                    onChange={e => setUrlInput(e.target.value)}
                                    placeholder="https://example.com"
                                    className="w-full rounded-lg border-gray-300 text-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                                />
                            </div>
                            <div className="flex items-center gap-3">
                                <label className="text-sm text-gray-500">Max:</label>
                                <select
                                    value={maxKeywords}
                                    onChange={e => setMaxKeywords(parseInt(e.target.value))}
                                    className="rounded-lg border-gray-300 text-sm"
                                >
                                    <option value={10}>10</option>
                                    <option value={20}>20</option>
                                    <option value={30}>30</option>
                                    <option value={50}>50</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center justify-between">
                        <p className="text-xs text-gray-400">
                            Uses Google Keyword Planner + AI to find relevant keywords with volume & competition data.
                        </p>
                        <button
                            type="button"
                            onClick={runResearch}
                            disabled={loading || (!seedInput.trim() && !urlInput.trim())}
                            className="px-5 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {loading ? (
                                <span className="flex items-center gap-2">
                                    <svg className="animate-spin h-4 w-4" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" /><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" /></svg>
                                    Researching...
                                </span>
                            ) : 'Research Keywords'}
                        </button>
                    </div>

                    {error && (
                        <div className="bg-red-50 border border-red-200 rounded-lg p-3 text-sm text-red-700">{error}</div>
                    )}

                    {/* Suggestions Table */}
                    {suggestions.length > 0 && (
                        <div>
                            <div className="flex items-center justify-between mb-2">
                                <h4 className="text-sm font-semibold text-gray-900">{suggestions.length} Keywords Found</h4>
                                <button
                                    type="button"
                                    onClick={addAllSuggestions}
                                    className="text-xs font-medium text-flame-orange-600 hover:text-flame-orange-800"
                                >
                                    + Add All
                                </button>
                            </div>
                            <div className="bg-white rounded-lg border border-gray-200 overflow-hidden max-h-64 overflow-y-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Keyword</th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Match</th>
                                            <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Volume</th>
                                            <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Comp.</th>
                                            <th className="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase w-16"></th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-100">
                                        {suggestions.map((kw, i) => {
                                            const isSelected = selectedTexts.has(kw.text.toLowerCase());
                                            return (
                                                <tr key={i} className={`${isSelected ? 'bg-green-50' : 'hover:bg-gray-50'}`}>
                                                    <td className="px-3 py-2 text-sm text-gray-900">{kw.text}</td>
                                                    <td className="px-3 py-2">
                                                        <span className={`text-xs px-2 py-0.5 rounded ${matchTypeColors[kw.match_type] || 'bg-gray-100 text-gray-600'}`}>
                                                            {kw.match_type}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2 text-right text-sm text-gray-600">{formatVolume(kw.avg_monthly_searches)}</td>
                                                    <td className="px-3 py-2 text-right text-sm text-gray-600">{kw.competition_index ?? '—'}</td>
                                                    <td className="px-3 py-2 text-center">
                                                        {isSelected ? (
                                                            <span className="text-green-600 text-xs font-medium">Added ✓</span>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                onClick={() => addKeyword(kw)}
                                                                className="text-xs text-flame-orange-600 hover:text-flame-orange-800 font-medium"
                                                            >
                                                                + Add
                                                            </button>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {/* Clusters */}
                    {clusters.length > 0 && (
                        <div>
                            <h4 className="text-sm font-semibold text-gray-900 mb-2">AI Keyword Clusters</h4>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                                {clusters.map((cluster, i) => (
                                    <div key={i} className="bg-gray-50 rounded-lg border border-gray-200 p-3">
                                        <div className="flex items-center justify-between mb-1.5">
                                            <span className="text-xs font-semibold text-gray-900">{cluster.cluster_name}</span>
                                            {cluster.intent && (
                                                <span className={`text-xs px-1.5 py-0.5 rounded ${intentColors[cluster.intent] || 'bg-gray-100 text-gray-600'}`}>
                                                    {cluster.intent}
                                                </span>
                                            )}
                                        </div>
                                        <div className="flex flex-wrap gap-1">
                                            {cluster.keywords?.map((kw, j) => (
                                                <span key={j} className="text-xs bg-white border border-gray-200 rounded px-1.5 py-0.5 text-gray-600">{kw}</span>
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Negatives */}
                    {negatives.length > 0 && (
                        <div>
                            <h4 className="text-sm font-semibold text-gray-900 mb-2">Suggested Negative Keywords</h4>
                            <div className="flex flex-wrap gap-1.5">
                                {negatives.map((nk, i) => (
                                    <span key={i} className="px-2 py-0.5 text-xs bg-red-50 text-red-600 rounded border border-red-200">{nk}</span>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            )}

            {activeTab === 'manual' && (
                <div className="space-y-3">
                    <p className="text-sm text-gray-500">Add keywords manually. You can set the match type for each.</p>
                    <div className="flex gap-2">
                        <input
                            type="text"
                            value={manualInput}
                            onChange={e => setManualInput(e.target.value)}
                            onKeyDown={e => e.key === 'Enter' && (e.preventDefault(), addManualKeyword())}
                            placeholder="Enter a keyword..."
                            className="flex-1 rounded-lg border-gray-300 text-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                        />
                        <select
                            value={manualMatchType}
                            onChange={e => setManualMatchType(e.target.value)}
                            className="rounded-lg border-gray-300 text-sm"
                        >
                            <option value="BROAD">Broad</option>
                            <option value="PHRASE">Phrase</option>
                            <option value="EXACT">Exact</option>
                        </select>
                        <button
                            type="button"
                            onClick={addManualKeyword}
                            disabled={!manualInput.trim()}
                            className="px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 disabled:opacity-50"
                        >
                            Add
                        </button>
                    </div>
                </div>
            )}

            {/* Selected Keywords */}
            {value.length > 0 && (
                <div>
                    <div className="flex items-center justify-between mb-2">
                        <h4 className="text-sm font-semibold text-gray-900">
                            Selected Keywords ({value.length})
                        </h4>
                        <button
                            type="button"
                            onClick={() => onChange([])}
                            className="text-xs text-red-500 hover:text-red-700"
                        >
                            Clear All
                        </button>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {value.map((kw, i) => (
                            <div key={i} className="flex items-center gap-1 bg-white border border-gray-200 rounded-lg pl-3 pr-1 py-1">
                                <span className="text-sm text-gray-900">{kw.text}</span>
                                <select
                                    value={kw.match_type}
                                    onChange={e => updateMatchType(kw.text, e.target.value)}
                                    className="text-xs border-0 bg-transparent p-0 pr-5 focus:ring-0 text-gray-500"
                                >
                                    <option value="BROAD">Broad</option>
                                    <option value="PHRASE">Phrase</option>
                                    <option value="EXACT">Exact</option>
                                </select>
                                {kw.avg_monthly_searches != null && (
                                    <span className="text-xs text-gray-400">{formatVolume(kw.avg_monthly_searches)}/mo</span>
                                )}
                                <button
                                    type="button"
                                    onClick={() => removeKeyword(kw.text)}
                                    className="ml-1 p-1 text-gray-400 hover:text-red-500 rounded"
                                >
                                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Skip Hint */}
            {value.length === 0 && suggestions.length === 0 && (
                <div className="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <p className="text-sm text-blue-700">
                        <strong>This step is optional.</strong> If you skip it, our AI will automatically research and select keywords when deploying your campaign.
                        Add keywords here if you want more control over which search terms trigger your ads.
                    </p>
                </div>
            )}
        </div>
    );
}
