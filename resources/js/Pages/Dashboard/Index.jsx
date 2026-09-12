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
import ForecastPanel from '@/Components/ForecastPanel';
import { money, count } from '@/utils/format';
import { useCurrency } from '@/hooks/useCurrency';
import { platform as platformOf, platformHex, platformLabel } from '@/utils/platforms';
import QuickActions, { PendingTasks, CampaignHealthAlerts } from '@/Components/QuickActions';
import AgentActivityFeed from '@/Components/AgentActivityFeed';

// ─── Platform constants ─────────────────────────────────────────
/*
 * One map, keyed by the slug the API sends.
 *
 * There were three — PLATFORM_LABELS and PLATFORM_HEX keyed by 'google', and
 * PLATFORM_BG keyed by 'Google' — so the same platform was a different colour
 * in the spend bar than in the comparison bars, and a lookup that guessed the
 * casing wrong fell through to grey.
 *
 * The colours are NOT the platforms' own brand colours any more. Those are
 * #4285F4, #1877F2, #00A4EF and #0A66C2 — four blues. Run through the palette
 * validator, Google↔Facebook came back at ΔE 4.8 for NORMAL vision (the floor
 * is 15) and 3.9 under deuteranopia: in the stacked spend bar, nobody could
 * tell which segment was which, colour-blind or not. These four are a
 * validated categorical set — all-pairs ΔE 16.3 normal, 9.1 worst CVD — and
 * every chart that uses them also carries a text label, which is what the
 * validator's sub-3:1 contrast warning requires.
 */
/*
 * Labels and colours come from utils/platforms, which is the same table the
 * analytics pages now use. This file had its own — a fourth set of hues for the
 * same four platforms, so Google was #2a78d6 here and #4285F4 on the ROI page.
 * A platform's colour is its identity in a chart; it cannot change per screen.
 */

// Cost and revenue, as a validated pair (ΔE 24.0 normal, 23.1 protan).
// Cost was red-300, which reads as an error state — spending is the point of
// the product, not a fault.
const SERIES = { cost: '#2a78d6', revenue: '#1baf7a' };


// ─── Small reusable pieces ──────────────────────────────────────
function KpiCard({ label, value, sub, color }) {
    return (
        <div className="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <p className="text-xs text-gray-500 uppercase tracking-wide">{label}</p>
            <p className={`text-2xl font-bold mt-1 ${color || 'text-gray-900'}`}>{value}</p>
            {sub && <p className="text-xs text-gray-500 mt-1">{sub}</p>}
        </div>
    );
}

/**
 * Where the money went, across platforms.
 *
 * A 100%-wide bar in a single colour is not a chart — it encodes one number as
 * a full-width rectangle and says nothing the figure beside it does not. On
 * this account, which runs Google only, that was a whole card spent on a solid
 * blue rule. One platform now renders as the stat it is; the bar appears when
 * there is actually a split to show.
 */
function SpendBar({ platforms }) {
    const currency = useCurrency();
    const rows = Object.entries(platforms)
        .map(([name, data]) => ({ ...platformOf(name), cost: data.cost }))
        .filter((r) => r.cost > 0)
        .sort((a, b) => b.cost - a.cost);

    const total = rows.reduce((s, r) => s + r.cost, 0);
    if (total === 0) return null;

    if (rows.length === 1) {
        return (
            <p className="text-sm text-gray-600">
                All of it through{' '}
                <span className="font-semibold text-gray-900">{rows[0].label}</span> —{' '}
                <span className="font-semibold text-gray-900">{money(rows[0].cost, currency, { maximumFractionDigits: 0 })}</span>.
            </p>
        );
    }

    return (
        <div className="space-y-3">
            {/* 2px surface gaps between segments, so adjacent fills stay separable. */}
            <div className="flex h-6 gap-0.5 overflow-hidden rounded-full">
                {rows.map((r) => (
                    <div
                        key={r.label}
                        className="h-full first:rounded-l-full last:rounded-r-full"
                        style={{ width: `${(r.cost / total) * 100}%`, backgroundColor: r.hex }}
                    />
                ))}
            </div>
            <ul className="flex flex-wrap gap-x-5 gap-y-1.5 text-xs">
                {rows.map((r) => (
                    <li key={r.label} className="flex items-center gap-1.5">
                        <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: r.hex }} aria-hidden="true" />
                        <span className="text-gray-600">
                            {r.label} <span className="font-medium text-gray-900">{money(r.cost, currency, { maximumFractionDigits: 0 })}</span>{' '}
                            <span className="text-gray-500">({Math.round((r.cost / total) * 100)}%)</span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * One measure over time, as an area with a 2px cap.
 *
 * @param {Array<{date: string, value: number}>} points
 */
function Sparkline({ points, color, label, format }) {
    const currency = useCurrency();
    const formatValue = format ?? ((n) => money(n, currency, { maximumFractionDigits: 0 }));
    const [hover, setHover] = useState(null);

    const W = 640;
    const H = 96;
    const max = Math.max(...points.map((p) => p.value), 1);
    const stepX = points.length > 1 ? W / (points.length - 1) : 0;
    const xy = points.map((p, i) => [i * stepX, H - (p.value / max) * (H - 8) - 2]);
    const line = xy.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
    const area = `${line} L${W},${H} L0,${H} Z`;
    const peak = points.reduce((a, b) => (b.value > a.value ? b : a), points[0]);

    return (
        <figure className="min-w-0">
            <figcaption className="mb-1 flex items-baseline justify-between gap-3">
                <span className="flex items-center gap-2 text-sm font-medium text-gray-700">
                    <span className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: color }} aria-hidden="true" />
                    {label}
                </span>
                {/* One direct label — the peak — not a number on every point. */}
                <span className="text-xs text-gray-500">
                    peak <span className="font-semibold text-gray-900">{formatValue(peak?.value)}</span>
                </span>
            </figcaption>

            <div className="relative" onMouseLeave={() => setHover(null)}>
                <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" className="h-24 w-full" role="img"
                     aria-label={`${label} over time, peak ${formatValue(peak?.value)}`}>
                    <path d={area} fill={color} opacity="0.12" />
                    <path d={line} fill="none" stroke={color} strokeWidth="2" vectorEffect="non-scaling-stroke"
                          strokeLinejoin="round" strokeLinecap="round" />
                    {hover !== null && (
                        <circle cx={xy[hover][0]} cy={xy[hover][1]} r="4" fill={color} stroke="#fff" strokeWidth="2"
                                vectorEffect="non-scaling-stroke" />
                    )}
                </svg>

                {/* Hit targets are full-height columns, so they are bigger than the mark. */}
                <div className="absolute inset-0 flex">
                    {points.map((p, i) => (
                        <button
                            key={p.date}
                            type="button"
                            tabIndex={-1}
                            aria-hidden="true"
                            className="h-full flex-1"
                            onMouseEnter={() => setHover(i)}
                            onFocus={() => setHover(i)}
                        />
                    ))}
                </div>

                {hover !== null && (
                    <div
                        className="pointer-events-none absolute -top-1 z-10 -translate-x-1/2 -translate-y-full whitespace-nowrap rounded-md bg-gray-900 px-2 py-1 text-xs text-white shadow-lg"
                        style={{ left: `${(hover / Math.max(points.length - 1, 1)) * 100}%` }}
                    >
                        {points[hover].date}: <span className="font-semibold">{formatValue(points[hover].value)}</span>
                    </div>
                )}
            </div>
        </figure>
    );
}

/**
 * Cost and revenue over the period.
 *
 * Small multiples, not one chart. These are two measures on scales an order of
 * magnitude apart — on this account $629 of spend against $2,595 of revenue —
 * and the old chart put both against a single `max(cost, revenue)` axis. The
 * cost series was therefore drawn as a row of 6px hairlines while revenue used
 * the full height, which is the shared-axis version of the dual-axis mistake:
 * the smaller series becomes unreadable. Two panels, each with its own scale
 * and its own peak labelled, compares the shapes without lying about either.
 */
function DailyChart({ data }) {
    if (!data || data.length === 0) return null;

    const labels = [data[0]?.date, data[data.length - 1]?.date].filter(Boolean);

    return (
        <div className="space-y-5">
            <Sparkline points={data.map((d) => ({ date: d.date, value: d.cost }))} color={SERIES.cost} label="Cost" />
            <Sparkline points={data.map((d) => ({ date: d.date, value: d.revenue }))} color={SERIES.revenue} label="Revenue" />
            {/* Two endpoint labels rather than a rotated tick under every bar. */}
            <div className="flex justify-between text-xs text-gray-500">
                {labels.map((d) => <span key={d}>{d}</span>)}
            </div>
        </div>
    );
}

function FunnelBar({ stage, maxValue }) {
    const currency = useCurrency();
    const width = maxValue > 0 ? (stage.value / maxValue) * 100 : 0;
    // A label only fits inside the fill once the fill is wide enough to hold
    // it. Below that it was being clipped by the bar's own rounded end —
    // 16,024 impressions read fine, 420 clicks rendered as "20".
    const labelInside = width >= 18;
    const value = count(stage.value);

    return (
        <div className="flex items-center gap-4">
            <span className="w-28 shrink-0 text-sm font-medium text-gray-700">{stage.name}</span>
            <div className="relative h-6 flex-1 rounded-full bg-gray-100">
                <div
                    className="flex h-6 items-center justify-end rounded-full pr-2"
                    style={{
                        width: `${Math.max(width, 2)}%`,
                        backgroundImage: 'linear-gradient(to right, var(--color-brand-primary), var(--color-brand-dark))',
                    }}
                >
                    {labelInside && <span className="text-xs font-medium text-white">{value}</span>}
                </div>
                {!labelInside && (
                    <span
                        className="absolute top-1/2 -translate-y-1/2 text-xs font-semibold text-gray-900"
                        style={{ left: `calc(${Math.max(width, 2)}% + 8px)` }}
                    >
                        {value}
                    </span>
                )}
            </div>
            <span className="w-14 shrink-0 text-right text-xs text-gray-500">{stage.rate}%</span>
        </div>
    );
}

function PlatformComparisonBar({ platform, metric, maxValue }) {
    const currency = useCurrency();
    const width = maxValue > 0 ? (platform[metric] / maxValue) * 100 : 0;
    const color = platformHex(platform.platform);

    return (
        <div className="flex items-center gap-3">
            <span className="w-20 shrink-0 text-xs text-gray-600">{platform.platform}</span>
            <div className="h-4 flex-1 rounded-full bg-gray-100">
                <div className="h-4 rounded-full" style={{ width: `${Math.max(width, 1)}%`, backgroundColor: color }} />
            </div>
            <span className="w-20 shrink-0 text-right text-xs font-medium text-gray-900">
                {metric === 'cost' ? money(platform[metric], currency, { maximumFractionDigits: 0 }) : metric === 'roas' ? `${platform[metric]}x` : count(platform[metric] ?? 0)}
            </span>
        </div>
    );
}

// ─── Tab button helper ──────────────────────────────────────────
function TabBtn({ children }) {
    return (
        <Tab className={({ selected }) =>
            `shrink-0 whitespace-nowrap rounded-lg px-4 py-2.5 text-sm font-medium transition focus:outline-none ${
                selected
                    ? 'bg-white text-brand-darker shadow-sm border border-gray-200'
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
    const currency = useCurrency();
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
                                    className={`px-3 py-1.5 text-xs font-medium transition rounded-lg ${selectedDays === d ? 'bg-brand-dark text-white' : 'text-gray-600 hover:bg-gray-50'}`}
                                >{d}d</button>
                            ))}
                        </div>
                    </div>
                </div>
            }
        >
            <Head title="Performance Dashboard" />

            <div className="py-6 sm:py-10">
                <div className="max-w-7xl mx-auto">
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
                                <h3 className="text-sm font-medium text-gray-700">
                                    Creative Usage{creativeUsage.plan_name && creativeUsage.plan_name !== 'Free' ? ` — ${creativeUsage.plan_name} Plan` : ''}
                                </h3>
                                <a href={route('creative-usage')} className="text-brand-dark hover:text-brand-darker font-medium text-xs">View Details →</a>
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <DashboardUsageBar label="Images" used={creativeUsage.image_generations.used} limit={creativeUsage.image_generations.limit} bonus={creativeUsage.image_generations.bonus} />
                                <DashboardUsageBar label="Videos" used={creativeUsage.video_generations.used} limit={creativeUsage.video_generations.limit} bonus={creativeUsage.video_generations.bonus} />
                                <DashboardUsageBar label="Refinements" used={creativeUsage.refinements.used} limit={creativeUsage.refinements.limit} bonus={creativeUsage.refinements.bonus} />
                            </div>
                        </div>
                    )}

                    {/*
                        Only once a campaign exists. A cache miss here is a pair
                        of Keyword Planner calls against the shared MCC quota,
                        and an account with nothing set up has no budget to
                        frame the answer against anyway.
                    */}
                    {campaigns.length > 0 && (
                        <ForecastPanel className="mb-6" campaignId={campaigns[0]?.id} />
                    )}

                    {campaigns.length === 0 ? (
                        <NoCampaigns />
                    ) : (
                        <TabGroup>
                            {/*
                                Four tabs at px-4 are wider than a 390px screen,
                                and a plain flex row pushed the whole document
                                sideways rather than clipping. Scrolls in its own
                                track now, so the page itself never does.
                            */}
                            <TabList className="-mx-4 mb-6 flex gap-1 overflow-x-auto rounded-xl bg-gray-100 p-1 px-4 sm:mx-0 sm:px-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
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
                                                <KpiCard label="Ad Spend" value={money(displayKpis.cost || 0, currency, { maximumFractionDigits: 0 })} sub={`${selectedDays} days`} />
                                                <KpiCard label="Revenue" value={money(displayKpis.revenue || 0, currency, { maximumFractionDigits: 0 })} />
                                                <KpiCard label="ROAS" value={`${displayKpis.roas || 0}x`} sub={displayKpis.roas >= 3 ? 'Strong' : displayKpis.roas >= 1 ? 'Moderate' : 'Needs attention'} color={displayKpis.roas >= 2 ? 'text-green-600' : displayKpis.roas >= 1 ? 'text-yellow-600' : 'text-red-600'} />
                                                <KpiCard label="Conversions" value={count(displayKpis.conversions || 0)} color="text-green-600" />
                                                <KpiCard label="Avg CPA" value={money(displayKpis.cpa || 0, currency)} />
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
                                                    <p className="text-sm text-gray-500">Data will appear once your campaigns start running.</p>
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
                                                            <div className="h-3 w-3 rounded-full" style={{ backgroundColor: platformHex(p.platform) }} />
                                                            <span className="text-sm text-gray-700">{p.platform}: {p.spend_share}%</span>
                                                        </div>
                                                    ))}
                                                </div>
                                                <div className="flex h-4 rounded-full overflow-hidden bg-gray-200">
                                                    {crossPlatformComparison.filter(p => p.spend_share > 0).map(p => (
                                                        <div key={p.platform} style={{ width: `${p.spend_share}%`, backgroundColor: platformHex(p.platform) }} />
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
                                                                            <span className="h-2 w-2 rounded-full" style={{ backgroundColor: platformHex(p.platform) }} />
                                                                            {p.platform}
                                                                        </span>
                                                                    </td>
                                                                    <td className="py-2.5">{count(p.impressions ?? 0)}</td>
                                                                    <td className="py-2.5">{count(p.clicks ?? 0)}</td>
                                                                    <td className="py-2.5">{money(p.cost ?? 0, currency, { maximumFractionDigits: 0 })}</td>
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
                                                <div><p className="text-sm text-delft-blue-600">Monthly Projected Spend</p><p className="text-xl font-bold text-delft-blue-900">{money(projections.monthly_projected_spend ?? 0, currency, { maximumFractionDigits: 0 })}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Monthly Projected Revenue</p><p className="text-xl font-bold text-green-700">{money(projections.monthly_projected_revenue ?? 0, currency, { maximumFractionDigits: 0 })}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Monthly Projected Profit</p><p className={`text-xl font-bold ${projections.monthly_projected_profit >= 0 ? 'text-green-700' : 'text-red-700'}`}>{money(projections.monthly_projected_profit ?? 0, currency, { maximumFractionDigits: 0 })}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Budget Utilization</p><p className="text-xl font-bold text-delft-blue-900">{projections.budget_utilization}%</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Daily Budget (Total)</p><p className="text-xl font-bold text-delft-blue-900">${projections.daily_budget_total}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Quarterly Projected Spend</p><p className="text-xl font-bold text-delft-blue-900">{money(projections.quarterly_projected_spend ?? 0, currency, { maximumFractionDigits: 0 })}</p></div>
                                                <div><p className="text-sm text-delft-blue-600">Quarterly Projected Revenue</p><p className="text-xl font-bold text-green-700">{money(projections.quarterly_projected_revenue ?? 0, currency, { maximumFractionDigits: 0 })}</p></div>
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
                                                                <td className="py-3 px-4 text-right">{money(c.cost, currency, { maximumFractionDigits: 0 })}</td>
                                                                <td className="py-3 px-4 text-right">{money(c.revenue, currency, { maximumFractionDigits: 0 })}</td>
                                                                <td className="py-3 px-4 text-right">{c.conversions}</td>
                                                                <td className="py-3 px-4 text-right">
                                                                    <span className={c.roas >= 2 ? 'text-green-600 font-semibold' : c.roas >= 1 ? 'text-yellow-600' : 'text-red-600 font-semibold'}>{c.roas}x</span>
                                                                </td>
                                                                <td className="py-3 px-4 text-right">${c.cpa}</td>
                                                                <td className="py-3 px-4 text-right">
                                                                    <div className="flex items-center justify-end gap-2">
                                                                        <div className="w-16 bg-gray-200 rounded-full h-2">
                                                                            <div className={`h-2 rounded-full ${c.budget_utilization > 100 ? 'bg-red-500' : 'bg-brand-primary'}`} style={{ width: `${Math.min(100, c.budget_utilization)}%` }} />
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
                            <a href={route('analytics.attribution')} className="text-sm text-brand-dark hover:text-brand-darker font-medium">
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
