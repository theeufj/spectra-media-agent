import React from 'react';
import { Link } from '@inertiajs/react';
import { brandTint } from '@/Components/Marketing/Hero';

function copyLines(lines) {
    return Array.isArray(lines) ? lines.filter(line => typeof line === 'string' && line.trim()) : [];
}

export function hasUsableAd(copy) {
    return copyLines(copy?.headlines).length > 0 && copyLines(copy?.descriptions).length > 0;
}

export default function DemoResultsPanel({ result, onSaveClick }) {
    if (!result) return null;

    const { url, ad_copy, visuals, forecast, notes = [], preview_token: previewToken } = result;

    const headlines = copyLines(ad_copy?.headlines);
    const descriptions = copyLines(ad_copy?.descriptions);
    const hasDraft = headlines.length > 0 && descriptions.length > 0;
    const canSave = hasDraft && typeof previewToken === 'string' && previewToken.trim().length > 0;

    const num = (n) => n == null ? '—' : Number(n).toLocaleString();
    const money = (n) => '$' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return (
        <div className="w-full max-w-5xl mx-auto bg-white rounded-xl shadow-xl overflow-hidden mt-8 border border-gray-100">
            <div className="p-5 sm:p-8">
                <h2 className="text-3xl font-bold tracking-tight text-gray-900">Your Google Search ad draft</h2>
                <p className="mt-2 break-words text-sm text-gray-600">Based on {url}</p>

                {hasDraft ? (
                    <article aria-label="Draft Google Search ad" className="mt-6 rounded-lg border border-gray-200 bg-white p-5 sm:p-6">
                        <p className="break-all text-sm text-gray-700">{url}</p>
                        <h3 className="mt-2 break-words text-xl font-medium leading-tight text-blue-800">{headlines.slice(0, 3).join(' | ')}</h3>
                        <p className="mt-2 break-words text-sm leading-relaxed text-gray-700">{descriptions.slice(0, 2).join(' ')}</p>
                    </article>
                ) : (
                    <p className="mt-4 text-gray-700">We could not create a complete ad from this page.</p>
                )}

                {notes.length > 0 && (
                    <ul className="mt-4 list-disc space-y-1 pl-5 text-sm text-gray-600">
                        {notes.map((note, i) => <li key={i}>{note}</li>)}
                    </ul>
                )}

                <div className="mt-6 rounded-lg border p-5 sm:p-6" style={{ backgroundColor: brandTint(5), borderColor: brandTint(20) }}>
                    <p className="mb-3 text-sm text-gray-700">
                        {canSave
                            ? 'Create a free account to keep this draft. You review the campaign and budget before launch.'
                            : 'This preview is unavailable to save. You can still start a free workspace.'}
                    </p>
                    <Link href={canSave ? `/register?preview_token=${encodeURIComponent(previewToken)}` : '/register'}
                        onClick={canSave ? onSaveClick : undefined}
                        className="inline-flex min-h-[44px] items-center justify-center rounded-lg bg-brand-dark px-6 py-3 font-semibold text-white hover:bg-brand-darker">
                        {canSave ? 'Save this ad draft' : 'Create a free account'}
                    </Link>
                </div>

                {(visuals?.colors?.length > 0 || visuals?.fonts?.length > 0 || visuals?.style_description) && (
                    <details className="mt-6 rounded-lg border border-gray-200 p-4">
                        <summary className="cursor-pointer font-semibold text-gray-800">Brand details from your website</summary>
                        <div className="mt-4 space-y-3 text-sm text-gray-700">
                            {visuals?.colors?.length > 0 && (
                                <ul className="flex flex-wrap gap-4" aria-label="Brand colours">
                                    {visuals.colors.map((color, i) => (
                                        <li key={i} className="flex items-center gap-2">
                                            <span aria-hidden="true" className="h-5 w-5 rounded-full border border-gray-200" style={{ backgroundColor: color }} />
                                            {color}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {visuals?.fonts?.length > 0 && <dl><dt className="font-medium">Typefaces</dt><dd>{visuals.fonts.join(', ')}</dd></dl>}
                            {visuals?.style_description && <p>{visuals.style_description}</p>}
                        </div>
                    </details>
                )}

                {/* Google's own numbers for this market. Everything here is measured
                    by Google except the conversion rate, which is labelled as ours. */}
                {forecast && (
                    <div className="mt-12">
                        <h3 className="text-xl font-bold text-gray-800 mb-1 flex items-center">
                            Google Keyword Planner forecast
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

            </div>
        </div>
    );
}
