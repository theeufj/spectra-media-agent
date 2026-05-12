import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useMemo } from 'react';
import { Tab, TabGroup, TabList, TabPanel, TabPanels } from '@headlessui/react';
import axios from 'axios';

import CampaignSelector from '@/Components/CampaignSelector';
import PerformanceStats from '@/Components/PerformanceStats';
import PerformanceChart from '@/Components/PerformanceChart';
import NoCampaigns from '@/Components/NoCampaigns';
import WaitingForData from '@/Components/WaitingForData';
import SetupProgressNav from '@/Components/SetupProgressNav';
import QuickActions, { PendingTasks, CampaignHealthAlerts } from '@/Components/QuickActions';
import AgentActivityFeed from '@/Components/AgentActivityFeed';

// ─── Platform constants ─────────────────────────────────────────
const PLATFORM_LABELS = { google: 'Google Ads', facebook: 'Facebook Ads', microsoft: 'Microsoft Ads', linkedin: 'LinkedIn Ads' };
const PLATFORM_HEX   = { google: '#4285F4', facebook: '#1877F2', microsoft: '#00A4EF', linkedin: '#0A66C2' };
const PLATFORM_BG    = { Google: 'bg-blue-500', Facebook: 'bg-indigo-500', Microsoft: 'bg-teal-500', LinkedIn: 'bg-sky-500' };

// ─── Small reusable pieces ──────────────────────────────────────
function KpiCard({ label, value, sub, color }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <p className="text-xs text-gray-500 uppercase tracking-wide">{label}</p>
            <p className={`text-2xl font-bold mt-1 ${color || 'text-gray-900'}`}>{value}</p>
            {sub && <p className="text-xs text-gray-400 mt-1">{sub}</p>}
        </div>
    );
}

function SpendBar({ platforms }) {
    const total = Object.values(platforms).reduce((s, p) => s + p.cost, 0);
    if (total === 0) return null;
    return (
        <div className="space-y-2">
            <div className="flex h-6 rounded-full overflow-hidden">
                {Object.entries(platforms).map(([name, data]) => {
                    const pct = (data.cost / total) * 100;
                    return <div key={name} className="h-full" style={{ width: `${pct}%`, backgroundColor: PLATFORM_HEX[name] || '#6B7280' }} title={`${PLATFORM_LABELS[name]}: $${data.cost.toLocaleString()} (${pct.toFixed(1)}%)`} />;
                })}
            </div>
            <div className="flex flex-wrap gap-4 text-xs">
                {Object.entries(platforms).map(([name, data]) => (
                    <div key={name} className="flex items-center gap-1.5">
                        <span className="w-3 h-3 rounded-full" style={{ backgroundColor: PLATFORM_HEX[name] }} />
                        <span className="text-gray-600">{PLATFORM_LABELS[name]}: ${data.cost.toLocaleString()}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function DailyChart({ data }) {
    if (!data || data.length === 0) return null;
    const maxVal = Math.max(...data.map(d => Math.max(d.cost, d.revenue)));
    const chartHeight = 200;
    return (
        <div className="overflow-x-auto">
            <div className="flex items-end gap-1 min-w-fit" style={{ height: chartHeight + 40 }}>
                {data.map((day, i) => {
                    const costH = maxVal > 0 ? (day.cost / maxVal) * chartHeight : 0;
                    const revH  = maxVal > 0 ? (day.revenue / maxVal) * chartHeight : 0;
                    return (
                        <div key={i} className="flex flex-col items-center gap-0.5" style={{ width: Math.max(16, 800 / data.length) }}>
                            <div className="flex items-end gap-px">
                                <div className="bg-red-300 rounded-t" style={{ height: costH, width: 6 }} title={`Cost: $${day.cost}`} />
                                <div className="bg-green-400 rounded-t" style={{ height: revH, width: 6 }} title={`Revenue: $${day.revenue}`} />
                            </div>
                            {i % Math.ceil(data.length / 10) === 0 && (
                                <span className="text-[10px] text-gray-400 mt-1 rotate-[-45deg] origin-top-left whitespace-nowrap">{day.date.split('-').slice(1).join('/')}</span>
                            )}
                        </div>
                    );
                })}
            </div>
            <div className="flex gap-4 mt-4 text-xs text-gray-500">
                <span className="flex items-center gap-1"><span className="w-3 h-3 bg-red-300 rounded" /> Cost</span>
                <span className="flex items-center gap-1"><span className="w-3 h-3 bg-green-400 rounded" /> Revenue</span>
            </div>
        </div>
    );
}

function FunnelBar({ stage, maxValue }) {
    const width = maxValue > 0 ? (stage.value / maxValue) * 100 : 0;
    return (
        <div className="flex items-center gap-4">
            <span className="text-sm font-medium text-gray-700 w-28">{stage.name}</span>
            <div className="flex-1 bg-gray-200 rounded-full h-6 relative">
                <div className="bg-gradient-to-r from-flame-orange-500 to-flame-orange-400 h-6 rounded-full flex items-center justify-end pr-2" style={{ width: `${Math.max(width, 2)}%` }}>
                    <span className="text-xs text-white font-medium">{stage.value.toLocaleString()}</span>
                </div>
            </div>
            <span className="text-xs text-gray-500 w-14 text-right">{stage.rate}%</span>
        </div>
    );
}

function PlatformComparisonBar({ platform, metric, maxValue }) {
    const width = maxValue > 0 ? (platform[metric] / maxValue) * 100 : 0;
    const color = PLATFORM_BG[platform.platform] || 'bg-gray-400';
    return (
        <div className="flex items-center gap-3">
            <span className="text-xs text-gray-600 w-20">{platform.platform}</span>
            <div className="flex-1 bg-gray-200 rounded-full h-4">
                <div className={`${color} h-4 rounded-full`} style={{ width: `${Math.max(width, 1)}%` }} />
            </div>
            <span className="text-xs font-medium text-gray-900 w-20 text-right">
                {metric === 'cost' ? `$${platform[metric]?.toLocaleString()}` : metric === 'roas' ? `${platform[metric]}x` : platform[metric]?.toLocaleString()}
            </span>
        </div>
    );
}

// ─── Tab button helper ──────────────────────────────────────────
function TabBtn({ children }) {
    return (
        <Tab className={({ selected }) =>
            `px-4 py-2.5 text-sm font-medium rounded-lg transition focus:outline-none ${
                selected
                    ? 'bg-white text-flame-orange-700 shadow-sm border border-gray-200'
                    : 'text-gray-500 hover:text-gray-700 hover:bg-white/60'
            }`
        }>
            {children}
        </Tab>
    );
}

// ═══════════════════════════════════════════════════════════════
// Main Dashboard Component
// ═══════════════════════════════════════════════════════════════
export default function Dashboard({ auth }) {
    const {
        campaigns, defaultCampaign, days: initialDays,
        usageStats, creativeUsage, pendingTasks, healthAlerts, agentActivities, flash,
        platformData: allPlatformData, campaignBreakdown, dailyTrend: allDailyTrend,
        projections, crossPlatformComparison, funnel, trackingStatus,
    } = usePage().props;

    const activeCustomer = auth.user?.active_customer;
    const [selectedCampaign, setSelectedCampaign] = useState(null); // null = All Campaigns
    const [performanceData, setPerformanceData] = useState(null);
    const [campaignRoi, setCampaignRoi] = useState(null);
    const [showFlash, setShowFlash] = useState(!!flash?.success);
    const [loading, setLoading] = useState(false);
    const [selectedDays, setSelectedDays] = useState(initialDays || 30);

    // Fetch per-campaign data when a campaign is selected
    useEffect(() => {
        if (!selectedCampaign) {
            setPerformanceData(null);
            setCampaignRoi(null);
            return;
        }
        setLoading(true);
        const endDate = new Date();
        const startDate = new Date();
        startDate.setDate(endDate.getDate() - selectedDays);
        const params = {
            start_date: startDate.toISOString().split('T')[0],
            end_date: endDate.toISOString().split('T')[0],
            days: selectedDays,
        };
        Promise.all([
            axios.get(route('api.campaigns.performance', { campaign: selectedCampaign.id, ...params })),
            axios.get(route('api.campaigns.roi', { campaign: selectedCampaign.id, days: selectedDays })),
        ]).then(([perfRes, roiRes]) => {
            setPerformanceData(perfRes.data);
            setCampaignRoi(roiRes.data);
        }).catch(err => console.error('Error fetching campaign data:', err))
          .finally(() => setLoading(false));
    }, [selectedCampaign, selectedDays]);

    // Derived KPIs from account-wide data
    const accountKpis = useMemo(() => {
        const pd = allPlatformData || {};
        const cost = Object.values(pd).reduce((s, p) => s + p.cost, 0);
        const rev  = Object.values(pd).reduce((s, p) => s + p.revenue, 0);
        const conv = Object.values(pd).reduce((s, p) => s + p.conversions, 0);
        return {
            cost, revenue: rev, conversions: conv,
            roas: cost > 0 ? (rev / cost).toFixed(2) : 0,
            cpa: conv > 0 ? (cost / conv).toFixed(2) : 0,
        };
    }, [allPlatformData]);

    // Show campaign-specific or account-wide KPIs
    const displayKpis = selectedCampaign && campaignRoi?.summary ? campaignRoi.summary : accountKpis;
    const displayPlatformData = selectedCampaign && campaignRoi?.platformData ? campaignRoi.platformData : (allPlatformData || {});
    const displayDailyTrend = selectedCampaign && campaignRoi?.dailyTrend ? campaignRoi.dailyTrend : (allDailyTrend || []);

    const handleDaysChange = (d) => {
        setSelectedDays(d);
        if (!selectedCampaign) {
            router.get(route('dashboard'), { days: d }, { preserveState: true, preserveScroll: true });
        }
    };

    const handleCampaignChange = (campaign) => {
        setSelectedCampaign(campaign);
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                    <h2 className="font-semibold text-lg text-gray-800 leading-tight">Dashboard</h2>
                    <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-4">
                        {campaigns.length > 0 && (
                            <CampaignSelector
                                campaigns={campaigns}
                                selectedCampaign={selectedCampaign}
                                setSelectedCampaign={handleCampaignChange}
                                showAllOption
                            />
                        )}
                        <div className="inline-flex rounded-lg border border-gray-200 bg-white">
                            {[7, 14, 30, 90].map(d => (
                                <button key={d} onClick={() => handleDaysChange(d)}
                                    className={`px-3 py-1.5 text-xs font-medium transition rounded-lg ${selectedDays === d ? 'bg-flame-orange-600 text-white' : 'text-gray-600 hover:bg-gray-50'}`}
                                >{d}d</button>
                            ))}
                        </div>
                    </div>
                </div>
            }
        >
            <Head title="Performance Dashboard" />

            <div className="py-6 sm:py-10">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <SetupProgressNav />

                    {trackingStatus?.provisioned && !trackingStatus?.installed && (
                        <div className="mb-4 flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                            <svg className="mt-0.5 h-5 w-5 flex-shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" strokeWidth="1.5" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                            </svg>
                            <div className="flex-1 text-sm">
                                <span className="font-semibold text-amber-900">Install your conversion tracking snippet</span>
                                <span className="ml-1 text-amber-800">— Your tracking is set up and ready. Paste the snippet on your website to start recording conversions.</span>
                            </div>
                            <a
                                href={trackingStatus.setup_url}
                                className="flex-shrink-0 rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-amber-700"
                            >
                                Install snippet
                            </a>
                        </div>
                    )}

                    {activeCustomer?.google_ads_customer_id && (
                        <div className="mb-4 flex items-center gap-2 text-sm text-gray-500">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0" /></svg>
                            <span>Account ID: <span className="font-mono font-medium text-gray-700">{activeCustomer.google_ads_customer_id}</span></span>
                        </div>
                    )}

                    {showFlash && flash?.success && (
                        <div className="mb-6 bg-green-50 border border-green-200 rounded-lg p-4 flex items-center justify-between">
                            <div className="flex items-center">
                                <svg className="w-5 h-5 text-green-500 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" /></svg>
                                <p className="text-sm font-medium text-green-800">{flash.success}</p>
                            </div>
                            <button onClick={() => setShowFlash(false)} className="text-green-500 hover:text-green-700">
                                <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" /></svg>
                            </button>
                        </div>
                    )}

                    {creativeUsage && !creativeUsage.is_unlimited && (
                        <div className="mb-6 bg-white overflow-hidden shadow-sm sm:rounded-lg p-5">
                            <div className="flex items-center justify-between mb-3">
                                <h3 className="text-sm font-medium text-gray-700">Creative Usage — {creativeUsage.plan_name} Plan</h3>
                                <a href={route('creative-usage')} className="text-flame-orange-600 hover:text-flame-orange-900 font-medium text-xs">View Details →</a>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <DashboardUsageBar label="Images" used={creativeUsage.image_generations.used} limit={creativeUsage.image_generations.limit} bonus={creativeUsage.image_generations.bonus} />
                                <DashboardUsageBar label="Videos" used={creativeUsage.video_generations.used} limit={creativeUsage.video_generations.limit} bonus={creativeUsage.video_generations.bonus} />
                                <DashboardUsageBar label="Refinements" used={creativeUsage.refinements.used} limit={creativeUsage.refinements.limit} bonus={creativeUsage.refinements.bonus} />
                            </div>
                        </div>
                    )}

                    {campaigns.length === 0 ? (
                        <NoCampaigns />
                    ) : (
                        <TabGroup>
                            <TabList className="flex gap-1 bg-gray-100 rounded-xl p-1 mb-6">
                                <TabBtn>Overview</TabBtn>
                                <TabBtn>Platforms</TabBtn>
                                <TabBtn>Campaigns</TabBtn>
                                <TabBtn>Activity</TabBtn>
                            </TabList>

                            <TabPanels>
                                {/* ─── OVERVIEW TAB ─── */}
                                <TabPanel className="space-y-6 focus:outline-none">
                                    {loading && <div className="p-8 bg-white rounded-xl border border-gray-200 text-center text-gray-500">Loading campaign data…</div>}

                                    {!loading && (
                                        <>
                                            {/* KPI Cards */}
                                            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
                                                <KpiCard label="Ad Spend" value={`$${Number(displayKpis.cost || 0).toLocaleString()}`} sub={`${selectedDays} days`} />
                                                <KpiCard label="Revenue" value={`$${Number(displayKpis.revenue || 0).toLocaleString()}`} />
                                                <KpiCard label="ROAS" value={`${displayKpis.roas || 0}x`} sub={displayKpis.roas >= 3 ? 'Strong' : displayKpis.roas >= 1 ? 'Moderate' : 'Needs attention'} color={displayKpis.roas >= 2 ? 'text-green-600' : displayKpis.roas >= 1 ? 'text-yellow-600' : 'text-red-600'} />
                                                <KpiCard label="Conversions" value={Number(displayKpis.conversions || 0).toLocaleString()} color="text-green-600" />
                                                <KpiCard label="Avg CPA" value={`$${displayKpis.cpa || 0}`} />
                                            </div>

                                            {/* Spend Allocation */}
                                            {Object.keys(displayPlatformData).length > 0 && (
                                                <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                                                    <h3 className="text-lg font-semibold text-gray-900 mb-4">Spend Allocation</h3>
                                                    <SpendBar platforms={displayPlatformData} />
                                                </div>
                                            )}

                                            {/* Daily Trend */}
                                            {displayDailyTrend.length > 0 && (
                                                <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                                                    <h3 className="text-lg font-semibold text-gray-900 mb-4">Daily Spend vs Revenue</h3>
                                                    <DailyChart data={displayDailyTrend} />
                                                </div>
                                            )}

                                            {/* Per-campaign performance chart (when campaign selected) */}
                                            {selectedCampaign && performanceData && (
                                                <div className="space-y-6">
                                                    <PerformanceStats stats={performanceData.summary} />
                                                    <PerformanceChart data={performanceData.daily_data} />
                                                </div>
                                            )}

                                            {selectedCampaign && !performanceData && !loading && <WaitingForData />}

                                            {/* Conversion Funnel (account-wide only) */}
                                            {!selectedCampaign && funnel?.stages && (
                                                <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                                                    <h2 className="text-lg font-semibold text-gray-900 mb-4">Conversion Funnel</h2>
                                                    <div className="space-y-3">
                                                        {funnel.stages.map((stage, i) => (
                                                            <FunnelBar key={i} stage={stage} maxValue={funnel.stages[0]?.value || 1} />
                                                        ))}
                                                    </div>
                                                    <div className="mt-4 grid grid-cols-3 gap-4 text-center pt-4 border-t">
                                                        <div><p className="text-xs text-gray-500">CPM</p><p className="text-sm font-bold">${funnel.cost_per_funnel_stage?.cpm ?? 0}</p></div>
                                                        <div><p className="text-xs text-gray-500">CPC</p><p className="text-sm font-bold">${funnel.cost_per_funnel_stage?.cpc ?? 0}</p></div>
                                                        <div><p className="text-xs text-gray-500">CPA</p><p className="text-sm font-bold">${funnel.cost_per_funnel_stage?.cpa ?? 0}</p></div>
                                                    </div>
                                                </div>
                                            )}

                                            {/* Empty state */}
                                            {Object.keys(displayPlatformData).length === 0 && !selectedCampaign && (
                                                <div className="bg-white rounded-xl border border-gray-200 p-12 text-center">
                                                    <p className="text-gray-500 mb-2">No performance data found for the last {selectedDays} days.</p>
                                                    <p className="text-sm text-gray-400">Data will appear once your campaigns start running.</p>
                                                </div>
                                            )}
                                        </>
                                    )}
                                </TabPanel>

                                {/* ─── PLATFORMS TAB ─── */}
                                <TabPanel className="space-y-6 focus:outline-none">
                                    {/* Cross-platform comparison */}
                                    {crossPlatformComparison && crossPlatformComparison.length > 0 ? (
                                        <>
                                            <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                                                <h2 className="text-lg font-semibold text-gray-900 mb-4">Spend Distribution</h2>
                                                <div className="flex gap-2 mb-4">
                                                    {crossPlatformComparison.filter(p => p.spend_share > 0).map(p => (
                                                        <div key={p.platform} className="flex items-center gap-2">
                                                            <div className={`w-3 h-3 rounded-full ${PLATFORM_BG[p.platform] || 'bg-gray-400'}`} />
                                                            <span className="text-sm text-gray-700">{p.platform}: {p.spend_share}%</span>
                                                        </div>
                                                    ))}
                                                </div>
                                                <div className="flex h-4 rounded-full overflow-hidden bg-gray-200">
                                                    {crossPlatformComparison.filter(p => p.spend_share > 0).map(p => (
                                                        <div key={p.platform} className={PLATFORM_BG[p.platform] || 'bg-gray-400'} style={{ width: `${p.spend_share}%` }} />
                                                    ))}
                                                </div>
                                            </div>

                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                {['impressions', 'clicks', 'cost', 'conversions', 'roas'].map(metric => {
                                                    const maxVal = Math.max(...crossPlatformComparison.map(p => p[metric] || 0));
                                                    return (
                                                        <div key={metric} className="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
                                                            <h3 className="text-sm font-semibold text-gray-900 mb-3 capitalize">{metric === 'cost' ? 'Spend' : metric}</h3>
                                                            <div className="space-y-2">
                                                                {crossPlatformComparison.map(p => <PlatformComparisonBar key={p.platform} platform={p} metric={metric} maxValue={maxVal} />)}
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>

                                            <div className="bg-white rounded-xl border border-gray-200 p-6 shadow-sm">
                                                <h2 className="text-lg font-semibold text-gray-900 mb-4">Platform Details</h2>
                                                <div className="overflow-x-auto">
                                                    <table className="w-full text-sm">
                                                        <thead>
                                                            <tr className="text-left text-gray-500 border-b">
                                                                <th className="pb-2 font-medium">Platform</th>
                                                                <th className="pb-2 font-medium">Impressions</th>
                                                                <th className="pb-2 font-medium">Clicks</th>
                                                                <th className="pb-2 font-medium">Spend</th>
                                                                <th className="pb-2 font-medium">Conversions</th>
                                                                <th className="pb-2 font-medium">ROAS</th>
                                                                <th className="pb-2 font-medium">Conv. Share</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {crossPlatformComparison.map(p => (
                                                                <tr key={p.platform} className="border-b border-gray-100">
                                                                    <td className="py-2.5 font-medium text-gray-900">
                                                                        <span className="flex items-center gap-2">
                                                                            <span className={`w-2 h-2 rounded-full ${PLATFORM_BG[p.platform] || 'bg-gray-400'}`} />
                                                                            {p.platform}
                                                                        </span>
                                                                    </td>
                                                                    <td className="py-2.5">{p.impressions?.toLocaleString()}</td>
                                                                    <td className="py-2.5">{p.clicks?.toLocaleString()}</td>
                                                                    <td className="py-2.5">${p.cost?.toLocaleString()}</td>
                                                                    <td className="py-2.5">{p.conversions}</td>
                                                                    <td className="py-2.5">{p.roas}x</td>
                                                                    <td className="py-2.5">{p.conversion_share}%</td>
                                                                </tr>
                                                            ))}
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </>
                                    ) : (
                                        <div className="bg-white rounded-xl border border-gray-200 p-12 text-center">
                                            <p className="text-gray-500">No cross-platform data available yet. Data will appear once campaigns are running on multiple platforms.</p>
                                        </div>
                                    )}
                                </TabPanel>

                                {/* ─── CAMPAIGNS TAB ─── */}
                                <TabPanel className="space-y-6 focus:outline-none">
                                    {/* Projections */}
                                    {projections && (
                                        <div className="bg-gradient-to-r from-delft-blue-50 to-air-superiority-blue-50 rounded-xl border border-delft-blue-200 p-6">
                                            <h3 className="text-lg font-semibold text-delft-blue-900 mb-4">Spending Projections</h3>
                                            <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                                                <div><p className="text-sm text-delft-blue-600">Daily Avg Spend</p><p className="text-xl font-bold text-delft-blue-900">${projections.daily_avg_spend}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Monthly Projected Spend</p><p className="text-xl font-bold text-delft-blue-900">${projections.monthly_projected_spend?.toLocaleString()}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Monthly Projected Revenue</p><p className="text-xl font-bold text-green-700">${projections.monthly_projected_revenue?.toLocaleString()}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Monthly Projected Profit</p><p className={`text-xl font-bold ${projections.monthly_projected_profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>${projections.monthly_projected_profit?.toLocaleString()}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Budget Utilization</p><p className="text-xl font-bold text-delft-blue-900">{projections.budget_utilization}%</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Daily Budget (Total)</p><p className="text-xl font-bold text-delft-blue-900">${projections.daily_budget_total}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Quarterly Projected Spend</p><p className="text-xl font-bold text-delft-blue-900">${projections.quarterly_projected_spend?.toLocaleString()}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Quarterly Projected Revenue</p><p className="text-xl font-bold text-green-700">${projections.quarterly_projected_revenue?.toLocaleString()}</p></div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Campaign ROI Breakdown */}
                                    {campaignBreakdown && campaignBreakdown.length > 0 ? (
                                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                                            <div className="p-6 border-b border-gray-200">
                                                <h3 className="text-lg font-semibold text-gray-900">Campaign ROI Breakdown</h3>
                                            </div>
                                            <div className="overflow-x-auto">
                                                <table className="w-full text-sm">
                                                    <thead className="bg-gray-50 text-gray-500 text-xs uppercase">
                                                        <tr>
                                                            <th className="py-3 px-4 text-left">Campaign</th>
                                                            <th className="py-3 px-4 text-right">Cost</th>
                                                            <th className="py-3 px-4 text-right">Revenue</th>
                                                            <th className="py-3 px-4 text-right">Conversions</th>
                                                            <th className="py-3 px-4 text-right">ROAS</th>
                                                            <th className="py-3 px-4 text-right">CPA</th>
                                                            <th className="py-3 px-4 text-right">Budget Used</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        {campaignBreakdown.map(c => (
                                                            <tr key={c.id} className="border-b border-gray-100 hover:bg-gray-50 cursor-pointer"
                                                                onClick={() => {
                                                                    const camp = campaigns.find(ca => ca.id === c.id);
                                                                    if (camp) handleCampaignChange(camp);
                                                                }}>
                                                                <td className="py-3 px-4 font-medium text-gray-900">{c.name}</td>
                                                                <td className="py-3 px-4 text-right">${c.cost.toLocaleString()}</td>
                                                                <td className="py-3 px-4 text-right">${c.revenue.toLocaleString()}</td>
                                                                <td className="py-3 px-4 text-right">{c.conversions}</td>
                                                                <td className="py-3 px-4 text-right">
                                                                    <span className={c.roas >= 2 ? 'text-green-600 font-semibold' : c.roas >= 1 ? 'text-yellow-600' : 'text-red-600 font-semibold'}>{c.roas}x</span>
                                                                </td>
                                                                <td className="py-3 px-4 text-right">${c.cpa}</td>
                                                                <td className="py-3 px-4 text-right">
                                                                    <div className="flex items-center justify-end gap-2">
                                                                        <div className="w-16 bg-gray-200 rounded-full h-2">
                                                                            <div className={`h-2 rounded-full ${c.budget_utilization > 100 ? 'bg-red-500' : 'bg-flame-orange-500'}`} style={{ width: `${Math.min(100, c.budget_utilization)}%` }} />
                                                                        </div>
                                                                        <span className="text-xs text-gray-500">{c.budget_utilization}%</span>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="bg-white rounded-xl border border-gray-200 p-12 text-center">
                                            <p className="text-gray-500">No campaign ROI data available yet.</p>
                                        </div>
                                    )}
                                </TabPanel>

                                {/* ─── ACTIVITY TAB ─── */}
                                <TabPanel className="focus:outline-none">
                                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                        <div className="space-y-6">
                                            <QuickActions />
                                            <PendingTasks tasks={pendingTasks || []} />
                                        </div>
                                        <div className="space-y-6">
                                            <AgentActivityFeed
                                                initialActivities={agentActivities || []}
                                                campaignId={selectedCampaign?.id}
                                            />
                                            <CampaignHealthAlerts alerts={healthAlerts || []} />
                                        </div>
                                    </div>
                                </TabPanel>
                            </TabPanels>
                        </TabGroup>
                    )}

                    {/* Attribution link */}
                    {campaigns.length > 0 && (
                        <div className="mt-6 text-center">
                            <a href={route('analytics.attribution')} className="text-sm text-flame-orange-600 hover:text-flame-orange-800 font-medium">
                                View Attribution Analysis →
                            </a>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

function DashboardUsageBar({ label, used, limit, bonus }) {
    const total = limit + bonus;
    const pct = total > 0 ? Math.min((used / total) * 100, 100) : 0;
    let color = 'bg-green-500';
    if (pct >= 80) color = 'bg-red-500';
    else if (pct >= 50) color = 'bg-yellow-500';

    return (
        <div>
            <div className="flex justify-between mb-1">
                <span className="text-sm font-medium text-gray-700">{label}</span>
                <span className="text-sm font-medium text-gray-700">{used} / {total}</span>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-2.5">
                <div className={`${color} h-2.5 rounded-full transition-all`} style={{ width: `${pct}%` }}></div>
            </div>
        </div>
    );
}
