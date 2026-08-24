import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import SideNav from './SideNav';

/**
 * Admin monitoring view of everything a customer's workspace has produced:
 * brand guidelines, campaign strategies, ad copy, and creative. The point is
 * catching a bad extraction or off-brand creative before the customer does.
 */
export default function CustomerWorkspace({ auth }) {
    const { customer, brandGuideline, campaigns, harvestedAssets, knowledge } = usePage().props;
    const [lightbox, setLightbox] = useState(null);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Admin - Customer Workspace</h2>}
        >
            <Head title={`Workspace - ${customer.business_name || customer.name}`} />

            <div className="flex">
                <SideNav />
                <div className="flex-1 py-8 px-6 max-w-6xl space-y-6">
                    {/* Header */}
                    <div className="flex items-start justify-between flex-wrap gap-3">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">{customer.business_name || customer.name}</h1>
                            <p className="text-sm text-gray-500">
                                {customer.website && <a href={customer.website} target="_blank" rel="noreferrer" className="text-flame-orange-600 hover:underline">{customer.website}</a>}
                                {' · '}{customer.country} · {customer.currency_code}
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Link href={route('admin.customers.show', customer.id)} className="px-3 py-1.5 text-sm bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                Account Detail
                            </Link>
                            <Link href={route('admin.customers.dashboard', customer.id)} className="px-3 py-1.5 text-sm bg-white border border-gray-300 rounded-md hover:bg-gray-50">
                                Performance
                            </Link>
                        </div>
                    </div>

                    {/* Knowledge summary strip */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <StatTile label="Knowledge pages" value={knowledge.pages} warn={knowledge.pages < 5} />
                        <StatTile label="Last crawled" value={knowledge.last_crawled_at ? new Date(knowledge.last_crawled_at).toLocaleDateString() : 'never'} warn={!knowledge.last_crawled_at} />
                        <StatTile label="Harvested assets" value={knowledge.harvested_total} />
                        <StatTile label="Campaigns" value={campaigns.length} />
                    </div>

                    {/* Brand guidelines */}
                    <section className="bg-white shadow-sm rounded-lg p-6">
                        <div className="flex items-center justify-between mb-4">
                            <h2 className="text-lg font-semibold text-gray-900">Brand Guidelines</h2>
                            {brandGuideline ? (
                                <div className="flex items-center gap-2 text-xs">
                                    {brandGuideline.extraction_quality_score != null && (
                                        <span className={`px-2 py-0.5 rounded-full font-medium ${brandGuideline.extraction_quality_score >= 7 ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
                                            Quality {brandGuideline.extraction_quality_score}/10
                                        </span>
                                    )}
                                    <span className={`px-2 py-0.5 rounded-full font-medium ${brandGuideline.user_verified ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                                        {brandGuideline.user_verified ? 'Customer verified' : 'Unverified'}
                                    </span>
                                </div>
                            ) : (
                                <span className="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">None extracted</span>
                            )}
                        </div>

                        {brandGuideline ? (
                            <div className="grid md:grid-cols-2 gap-6 text-sm">
                                <div className="space-y-4">
                                    <Field label="Brand voice">
                                        {brandGuideline.brand_voice?.description || JSON.stringify(brandGuideline.brand_voice)}
                                    </Field>
                                    <Field label="Tone">
                                        <ChipList items={brandGuideline.tone_attributes} />
                                    </Field>
                                    <Field label="Selling propositions">
                                        <BulletList items={brandGuideline.unique_selling_propositions} />
                                    </Field>
                                    <Field label="Do not use">
                                        <ChipList items={brandGuideline.do_not_use} tone="red" />
                                    </Field>
                                </div>
                                <div className="space-y-4">
                                    <Field label="Colour palette">
                                        <div className="flex gap-2 flex-wrap">
                                            {(Array.isArray(brandGuideline.color_palette) ? brandGuideline.color_palette : Object.values(brandGuideline.color_palette || {}))
                                                .flat()
                                                .filter(c => typeof c === 'string' && c.startsWith('#'))
                                                .slice(0, 12)
                                                .map((c, i) => (
                                                    <span key={i} className="inline-flex items-center gap-1 text-xs text-gray-600">
                                                        <span className="w-6 h-6 rounded border border-gray-200 inline-block" style={{ backgroundColor: c }} />
                                                        {c}
                                                    </span>
                                                ))}
                                        </div>
                                    </Field>
                                    <Field label="Target audience">
                                        {brandGuideline.target_audience?.primary || JSON.stringify(brandGuideline.target_audience)}
                                    </Field>
                                    <Field label="Messaging themes">
                                        <BulletList items={brandGuideline.messaging_themes} />
                                    </Field>
                                    <p className="text-xs text-gray-400">
                                        Extracted {brandGuideline.extracted_at ? new Date(brandGuideline.extracted_at).toLocaleString() : '—'}
                                    </p>
                                </div>
                            </div>
                        ) : (
                            <p className="text-sm text-gray-500">No brand guidelines exist for this customer — the site scan either hasn't run or couldn't extract enough. Campaigns generated without them will be generic.</p>
                        )}
                    </section>

                    {/* Campaigns */}
                    {campaigns.length === 0 && (
                        <section className="bg-white shadow-sm rounded-lg p-6 text-sm text-gray-500">
                            No campaigns yet.
                        </section>
                    )}

                    {campaigns.map((campaign) => (
                        <section key={campaign.id} className="bg-white shadow-sm rounded-lg p-6 space-y-5">
                            <div className="flex items-start justify-between flex-wrap gap-2">
                                <div>
                                    <h2 className="text-lg font-semibold text-gray-900">{campaign.name}</h2>
                                    <p className="text-xs text-gray-500">
                                        ID {campaign.id} · created {new Date(campaign.created_at).toLocaleDateString()}
                                        {campaign.auto_generated_at && ' · auto-generated'}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2 text-xs">
                                    <StatusChip value={campaign.status} />
                                    <span className="px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 font-medium">
                                        ${Number(campaign.daily_budget || 0).toFixed(0)}/day
                                    </span>
                                    {campaign.auto_generated_at && (
                                        <span className={`px-2 py-0.5 rounded-full font-medium ${campaign.budget_confirmed_at ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'}`}>
                                            {campaign.budget_confirmed_at ? 'Budget confirmed' : 'Budget unconfirmed'}
                                        </span>
                                    )}
                                </div>
                            </div>

                            {/* Strategies */}
                            {(campaign.strategies || []).map((strategy) => (
                                <div key={strategy.id} className="border border-gray-200 rounded-lg p-4 space-y-3">
                                    <div className="flex items-center justify-between flex-wrap gap-2">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium text-gray-900">{strategy.platform}</span>
                                            {strategy.campaign_type && <span className="text-xs text-gray-500">({strategy.campaign_type})</span>}
                                        </div>
                                        <div className="flex items-center gap-2 text-xs">
                                            <span className={`px-2 py-0.5 rounded-full font-medium ${strategy.signed_off_at ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'}`}>
                                                {strategy.signed_off_at ? 'Signed off' : 'Not signed off'}
                                            </span>
                                            <StatusChip value={strategy.deployment_status || 'not deployed'} />
                                        </div>
                                    </div>

                                    {strategy.deployment_error && (
                                        <p className="text-xs text-red-600 bg-red-50 rounded p-2">{strategy.deployment_error}</p>
                                    )}

                                    {strategy.ad_copy_strategy && (
                                        <Collapsible label="Strategy brief">
                                            <p className="whitespace-pre-wrap">{strategy.ad_copy_strategy}</p>
                                            {strategy.imagery_strategy && <p className="mt-2 whitespace-pre-wrap"><strong>Imagery:</strong> {strategy.imagery_strategy}</p>}
                                            {strategy.video_strategy && <p className="mt-2 whitespace-pre-wrap"><strong>Video:</strong> {strategy.video_strategy}</p>}
                                        </Collapsible>
                                    )}

                                    {(strategy.ad_copies || []).map((copy) => (
                                        <Collapsible key={copy.id} label={`Ad copy — ${copy.platform}${copy.should_deploy === false ? ' (not deploying)' : ''}`}>
                                            <div className="grid sm:grid-cols-2 gap-4">
                                                <div>
                                                    <p className="font-medium text-gray-700 mb-1">Headlines</p>
                                                    <BulletList items={copy.headlines} />
                                                </div>
                                                <div>
                                                    <p className="font-medium text-gray-700 mb-1">Descriptions</p>
                                                    <BulletList items={copy.descriptions} />
                                                </div>
                                            </div>
                                        </Collapsible>
                                    ))}

                                    <MediaGrid images={strategy.image_collaterals} videos={strategy.video_collaterals} onOpen={setLightbox} />
                                </div>
                            ))}

                            {/* Campaign-level media (wizard uploads, seeds, shared videos) */}
                            {((campaign.image_collaterals || []).length > 0 || (campaign.video_collaterals || []).length > 0) && (
                                <div className="border border-dashed border-gray-300 rounded-lg p-4">
                                    <p className="text-xs font-medium text-gray-500 uppercase tracking-wide mb-3">Campaign-level media (uploads, AI seeds, shared videos)</p>
                                    <MediaGrid images={campaign.image_collaterals} videos={campaign.video_collaterals} onOpen={setLightbox} />
                                </div>
                            )}
                        </section>
                    ))}

                    {/* Harvested assets */}
                    {harvestedAssets.length > 0 && (
                        <section className="bg-white shadow-sm rounded-lg p-6">
                            <h2 className="text-lg font-semibold text-gray-900 mb-1">Harvested Website Assets</h2>
                            <p className="text-xs text-gray-500 mb-4">Latest {harvestedAssets.length} of {knowledge.harvested_total} pulled from the customer's own site. These feed AI generation when no explicit seeds exist.</p>
                            <div className="grid grid-cols-3 sm:grid-cols-6 gap-3">
                                {harvestedAssets.map((asset) => (
                                    <button key={asset.id} onClick={() => setLightbox(asset.cloudfront_url)} className="relative group text-left">
                                        <img src={asset.cloudfront_url} alt={asset.classification} className="w-full h-24 object-cover rounded border border-gray-200" />
                                        <span className="absolute bottom-1 left-1 px-1.5 py-0.5 rounded bg-black/60 text-white text-[10px]">{asset.classification}</span>
                                    </button>
                                ))}
                            </div>
                        </section>
                    )}
                </div>
            </div>

            {/* Lightbox */}
            {lightbox && (
                <div className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center p-8 cursor-zoom-out" onClick={() => setLightbox(null)}>
                    <img src={lightbox} alt="" className="max-h-full max-w-full rounded shadow-2xl" />
                </div>
            )}
        </AuthenticatedLayout>
    );
}

const StatTile = ({ label, value, warn = false }) => (
    <div className={`rounded-lg p-3 border ${warn ? 'bg-yellow-50 border-yellow-200' : 'bg-white border-gray-200'}`}>
        <p className="text-xs text-gray-500">{label}</p>
        <p className={`text-lg font-semibold ${warn ? 'text-yellow-800' : 'text-gray-900'}`}>{value}</p>
    </div>
);

const Field = ({ label, children }) => (
    <div>
        <p className="text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{label}</p>
        <div className="text-gray-800">{children || <span className="text-gray-400">—</span>}</div>
    </div>
);

const ChipList = ({ items, tone = 'gray' }) => {
    if (!items?.length) return <span className="text-gray-400">—</span>;
    const cls = tone === 'red' ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-700';

    return (
        <div className="flex flex-wrap gap-1.5">
            {items.map((item, i) => (
                <span key={i} className={`px-2 py-0.5 rounded-full text-xs ${cls}`}>{typeof item === 'string' ? item : JSON.stringify(item)}</span>
            ))}
        </div>
    );
};

const BulletList = ({ items }) => {
    if (!items?.length) return <span className="text-gray-400">—</span>;

    return (
        <ul className="list-disc list-inside space-y-0.5 text-gray-800">
            {items.map((item, i) => <li key={i}>{typeof item === 'string' ? item : JSON.stringify(item)}</li>)}
        </ul>
    );
};

const Collapsible = ({ label, children }) => {
    const [open, setOpen] = useState(false);

    return (
        <div className="border border-gray-100 rounded">
            <button type="button" onClick={() => setOpen(o => !o)} className="w-full flex items-center justify-between px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                <span>{label}</span>
                <span className="text-gray-400">{open ? '−' : '+'}</span>
            </button>
            {open && <div className="px-3 pb-3 text-sm text-gray-700">{children}</div>}
        </div>
    );
};

const MediaGrid = ({ images = [], videos = [], onOpen }) => {
    if (!images?.length && !videos?.length) return null;

    return (
        <div>
            {images?.length > 0 && (
                <div className="grid grid-cols-3 sm:grid-cols-6 gap-3 mb-3">
                    {images.map((img) => (
                        <button key={img.id} onClick={() => onOpen(img.cloudfront_url)} className="relative group text-left">
                            <img src={img.cloudfront_url} alt="" className="w-full h-24 object-cover rounded border border-gray-200" />
                            <div className="absolute top-1 left-1 flex flex-col gap-0.5">
                                {img.is_seed && <span className="px-1.5 py-0.5 rounded bg-purple-600 text-white text-[10px]">seed</span>}
                                {img.should_deploy === false && !img.is_seed && <span className="px-1.5 py-0.5 rounded bg-gray-700 text-white text-[10px]">off</span>}
                            </div>
                            <span className="absolute bottom-1 left-1 px-1.5 py-0.5 rounded bg-black/60 text-white text-[10px]">{img.source || 'ai'}</span>
                        </button>
                    ))}
                </div>
            )}
            {videos?.length > 0 && (
                <ul className="space-y-1 text-sm">
                    {videos.map((vid) => (
                        <li key={vid.id} className="flex items-center gap-2">
                            <span>🎬</span>
                            <StatusChip value={vid.status} />
                            {vid.cloudfront_url
                                ? <a href={vid.cloudfront_url} target="_blank" rel="noreferrer" className="text-flame-orange-600 hover:underline">Watch video</a>
                                : <span className="text-gray-400">no file yet</span>}
                            {vid.youtube_video_id && <span className="text-xs text-gray-400">YT: {vid.youtube_video_id}</span>}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
};

const STATUS_STYLES = {
    active: 'bg-green-100 text-green-700',
    verified: 'bg-green-100 text-green-700',
    deployed: 'bg-green-100 text-green-700',
    completed: 'bg-green-100 text-green-700',
    deploying: 'bg-blue-100 text-blue-700',
    pending: 'bg-yellow-100 text-yellow-700',
    pending_admin_deployment: 'bg-yellow-100 text-yellow-700',
    deploy_unverified: 'bg-yellow-100 text-yellow-700',
    draft: 'bg-gray-100 text-gray-600',
    paused: 'bg-gray-100 text-gray-600',
    skipped_plan: 'bg-gray-200 text-gray-700',
    failed: 'bg-red-100 text-red-700',
};

const StatusChip = ({ value }) => (
    <span className={`px-2 py-0.5 rounded-full text-xs font-medium ${STATUS_STYLES[value] || 'bg-gray-100 text-gray-600'}`}>
        {String(value || '—').replaceAll('_', ' ')}
    </span>
);
