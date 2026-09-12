import React from 'react';
import { Link } from '@inertiajs/react';
import { brandTint } from '@/Components/Marketing/Hero';

export default function DemoResultsPanel({ result }) {
    if (!result) return null;

    const { url, ad_copy, visuals, forecast, notes = [] } = result;

    /*
     * Nothing on this panel is invented.
     *
     * Each of these fields used to end in `|| "…"`, so when generation returned
     * nothing the page wrote its own ad — "Transform Your Business | Sign Up
     * Today", "Discover why thousands trust our platform" — under a heading
     * reading "Your AI-Generated Ad Package". A stranger's first contact with
     * the product was boilerplate presented as a reading of their website. An
     * empty result now says it is empty.
     */
    const headlines = ad_copy?.headlines?.filter(Boolean) ?? [];
    const descriptions = ad_copy?.descriptions?.filter(Boolean) ?? [];
    const hasAdCopy = headlines.length > 0;

    const num = (n) => Number(n || 0).toLocaleString();
    const money = (n) => '$' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return (
        <div className="w-full max-w-5xl mx-auto bg-white rounded-xl shadow-xl overflow-hidden mt-8 border border-gray-100">
            <div className="p-8">
                <div className="text-center mb-10">
                    <h2 className="text-3xl font-extrabold text-gray-900">Your AI-Generated Ad Package</h2>
                    <p className="mt-2 text-gray-600">Extracted from <span className="font-semibold">{url}</span></p>
                </div>

                {/* What we could not read, before what we did. */}
                {notes.length > 0 && (
                    <div className="mb-8 rounded-lg border border-amber-300 bg-amber-50 p-4">
                        <ul className="space-y-1">
                            {notes.map((note, i) => (
                                <li key={i} className="text-sm text-amber-900">{note}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="grid grid-cols-1 md:grid-cols-2 gap-10">
                    {/* Brand Identity / Visuals */}
                    <div>
                        <h3 className="text-xl font-bold text-gray-800 mb-4 flex items-center">
                            <span className="text-2xl mr-2">🎨</span> Extracted Brand Identity
                        </h3>
                        <div className="bg-gray-50 rounded-lg p-6 border border-gray-200 h-full">
                            <div className="mb-6">
                                <h4 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">Color Palette</h4>
                                <div className="flex flex-wrap gap-3">
                                    {visuals?.colors?.length > 0 ? (
                                        visuals.colors.map((color, idx) => (
                                            <div key={idx} className="flex flex-col items-center">
                                                <div
                                                    className="w-12 h-12 rounded-full border border-gray-300 shadow-sm"
                                                    style={{ backgroundColor: color }}
                                                ></div>
                                                <span className="text-xs mt-1 text-gray-600 uppercase">{color}</span>
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-sm text-gray-500">No specific colors extracted.</p>
                                    )}
                                </div>
                            </div>

                            <div className="mb-6">
                                <h4 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Typography</h4>
                                {visuals?.fonts?.length > 0 ? (
                                    <ul className="list-disc pl-5 text-gray-800">
                                        {visuals.fonts.map((font, idx) => (
                                            <li key={idx}>{font}</li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-gray-500">No typeface of its own — the page renders in the browser default.</p>
                                )}
                            </div>

                            <div>
                                <h4 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Visual Vibe</h4>
                                <p className="text-gray-800 text-sm leading-relaxed">
                                    {visuals?.style_description || <span className="text-gray-500">Nothing distinctive enough to name.</span>}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Google Ad Preview */}
                    <div>
                        <h3 className="text-xl font-bold text-gray-800 mb-4 flex items-center">
                            <span className="text-2xl mr-2">🔍</span> Google Ad Preview
                        </h3>
                        <div className="bg-white rounded-lg p-6 border border-gray-200 shadow-sm h-full">
                            {hasAdCopy ? (
                                <>
                                    <div className="flex items-center text-sm text-gray-600 mb-1">
                                        <span className="font-bold text-black mr-2">Ad</span> · {url}
                                    </div>
                                    <div className="text-blue-700 text-xl font-medium hover:underline cursor-pointer leading-tight mb-2">
                                        {headlines.slice(0, 3).join(' | ')}
                                    </div>
                                    <div className="text-gray-600 text-sm">
                                        {descriptions.slice(0, 2).join(' ')}
                                    </div>
                                </>
                            ) : (
                                <p className="text-sm text-gray-600">
                                    There wasn't enough on that page for us to write an ad worth showing you.
                                    That is usually a site that builds itself in the browser, or one that
                                    hasn't launched — the numbers below come from Google either way.
                                </p>
                            )}
                        </div>
                    </div>
                </div>

                {/* Google's own numbers for this market. Everything here is measured
                    by Google except the conversion rate, which is labelled as ours. */}
                {forecast && (
                    <div className="mt-12">
                        <h3 className="text-xl font-bold text-gray-800 mb-1 flex items-center">
                            <span className="text-2xl mr-2">📊</span> What this actually buys you
                        </h3>
                        <p className="text-sm text-gray-500 mb-4">
                            {forecast.budget_capped
                                ? <>Google Keyword Planner forecast at <span className="font-semibold text-gray-700">{money(forecast.budget)}/month</span>, bidding {money(forecast.max_cpc)} max CPC on the {forecast.keywords.length} keywords below.</>
                                : <>Google Keyword Planner forecast over {forecast.days} days, bidding {money(forecast.max_cpc)} max CPC on the {forecast.keywords.length} keywords below.</>}
                        </p>

                        <div className="grid grid-cols-2 md:grid-cols-4 gap-px bg-gray-200 border border-gray-200 rounded-lg overflow-hidden">
                            <div className="bg-white p-5">
                                <div className="text-3xl font-bold text-gray-900 tabular-nums">{num(forecast.clicks)}</div>
                                <div className="text-xs uppercase tracking-wider text-gray-500 mt-1">Clicks</div>
                            </div>
                            <div className="bg-white p-5">
                                <div className="text-3xl font-bold text-brand-dark tabular-nums">{num(forecast.conversions)}</div>
                                <div className="text-xs uppercase tracking-wider text-gray-500 mt-1">Conversions</div>
                            </div>
                            <div className="bg-white p-5">
                                <div className="text-3xl font-bold text-gray-900 tabular-nums">{money(forecast.cost)}</div>
                                <div className="text-xs uppercase tracking-wider text-gray-500 mt-1">Ad spend</div>
                            </div>
                            <div className="bg-white p-5">
                                <div className="text-3xl font-bold text-gray-900 tabular-nums">{num(forecast.impressions)}</div>
                                <div className="text-xs uppercase tracking-wider text-gray-500 mt-1">Impressions</div>
                            </div>
                        </div>

                        <div className="mt-4 overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-left text-xs uppercase tracking-wider text-gray-500 border-b border-gray-200">
                                        <th className="py-2 pr-4 font-semibold">Keyword</th>
                                        <th className="py-2 pr-4 font-semibold text-right">Searches / month</th>
                                        <th className="py-2 font-semibold text-right">Top-of-page bid</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {forecast.keywords.map((k) => (
                                        <tr key={k.keyword} className="border-b border-gray-100 last:border-0">
                                            <td className="py-2 pr-4 text-gray-800">{k.keyword}</td>
                                            <td className="py-2 pr-4 text-right text-gray-600 tabular-nums">{num(k.monthly_searches)}</td>
                                            <td className="py-2 text-right text-gray-600 tabular-nums">{money(k.cpc)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <p className="mt-4 text-xs text-gray-500 leading-relaxed">
                            Search volume, bids and cost per click are Google's own figures for your market.
                            {forecast.budget_capped && <> There is more demand here than {money(forecast.budget)}/month
                            can buy, so clicks and impressions are scaled to that budget.</>}
                            {' '}Conversions apply an assumed {(forecast.conversion_rate * 100).toFixed(1)}% conversion
                            rate to those clicks — your real rate depends on your landing page and offer.
                        </p>
                    </div>
                )}

                <div
                    className="mt-12 text-center rounded-lg border p-8"
                    style={{ backgroundColor: brandTint(10), borderColor: brandTint(20) }}
                >
                    <h3 className="text-2xl font-bold text-gray-900 mb-2">Ready to deploy these campaigns?</h3>
                    <p className="text-gray-600 mb-6">Our AI agents will build out your entire account structure, write dozens of variations, and manage the budget automatically.</p>
                    <Link
                        href={`/register?demo_url=${encodeURIComponent(url)}`}
                        className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-white bg-brand-dark hover:bg-brand-darker shadow-lg transition-colors w-full sm:w-auto"
                    >
                        Deploy Automatically — Start Free Trial
                    </Link>
                </div>
            </div>
        </div>
    );
}
