import { SetupStages } from '@/Components/SetupJourney';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, router, usePage } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import PrimaryButton from '@/Components/PrimaryButton';
import CollateralGenerationModal from '@/Components/CollateralGenerationModal';
import ConfirmationModal from '@/Components/ConfirmationModal';
import BudgetConfirmation from '@/Components/BudgetConfirmation';
import ForecastPanel from '@/Components/ForecastPanel';
import CampaignCopilot from '@/Components/CampaignCopilot';
import { useJobWatch } from '@/hooks/useJobWatch';
import { usePolling } from '@/hooks/usePolling';
import { brandTint } from '@/Components/Marketing/Hero';

// Collateral Summary Card Component
const CollateralSummaryCard = ({ campaign }) => {
    const summary = campaign.collateral_summary || { ad_copies: 0, images: 0, videos: 0, total: 0 };
    const hasCollateral = summary.total > 0;
    const hasSignedOffStrategies = campaign.strategies?.some(s => s.signed_off_at);

    if (!hasSignedOffStrategies) return null;

    return (
        <div className="mb-8 bg-white rounded-lg shadow-md overflow-hidden">
            <div className="bg-gradient-to-r from-brand-dark to-brand-darker px-6 py-4">
                <h3 className="text-lg font-semibold text-white flex items-center">
                    <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                    </svg>
                    Campaign Collateral
                </h3>
            </div>
            <div className="p-6">
                {hasCollateral ? (
                    <>
                        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4 mb-6">
                            <div className="text-center p-3 sm:p-4 bg-blue-50 rounded-lg">
                                <div className="text-2xl sm:text-3xl font-bold text-blue-600">{summary.ad_copies}</div>
                                <div className="text-xs sm:text-sm text-gray-600">Ad Copies</div>
                            </div>
                            <div className="text-center p-3 sm:p-4 bg-green-50 rounded-lg">
                                <div className="text-2xl sm:text-3xl font-bold text-green-600">{summary.images}</div>
                                <div className="text-xs sm:text-sm text-gray-600">Images</div>
                            </div>
                            <div className="text-center p-3 sm:p-4 bg-purple-50 rounded-lg">
                                <div className="text-2xl sm:text-3xl font-bold text-purple-600">{summary.videos}</div>
                                <div className="text-xs sm:text-sm text-gray-600">Videos</div>
                            </div>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            {campaign.strategies?.filter(s => s.signed_off_at).map(strategy => (
                                <Link
                                    key={strategy.id}
                                    href={route('campaigns.collateral.show', { campaign: campaign.uuid, strategy: strategy.uuid })}
                                    className="inline-flex items-center px-4 py-2 bg-brand-dark text-white rounded-lg hover:bg-brand-darker transition text-sm"
                                >
                                    <span className="mr-2">{strategy.platform}</span>
                                    <span className="bg-brand-primary px-2 py-0.5 rounded text-xs">
                                        {(strategy.ad_copies_count || 0) + (strategy.image_collaterals_count || 0) + (strategy.video_collaterals_count || 0)} items
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </>
                ) : (
                    <div className="text-center py-6">
                        <div className="animate-pulse flex flex-col items-center">
                            <svg className="w-12 h-12 text-brand-primary mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <p className="text-gray-600 font-medium">Generating your collateral...</p>
                            <p className="text-sm text-gray-500 mt-1">Your assets appear as each one is ready</p>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

// A reusable component for a single strategy card
/*
   campaignUuid, not campaignId.

   The uuid sweep renamed the prop at the call site and left the signature
   alone, so this destructured a prop nobody passes and the only line that
   needed it reached for `campaigns` — a variable belonging to the page
   component, which does not exist out here at module scope. The result was a
   ReferenceError, and a ReferenceError in render is the whole page.

   It hid because the line sits behind `isSignedOff`: the View Collateral link
   only renders once a strategy has been signed off, so the crash arrived at
   the exact moment the customer approved their campaign and never before.
*/
export const StrategyCard = ({ strategy, campaignUuid, onSignOff }) => {
    const [isEditing, setIsEditing] = useState(false);
    const { data, setData, put, processing } = useForm({
        ad_copy_strategy: strategy.ad_copy_strategy,
        imagery_strategy: strategy.imagery_strategy,
        video_strategy: strategy.video_strategy,
    });

    const handleUpdate = (e) => {
        e.preventDefault();
        put(route('strategies.update', strategy.uuid), {
            onSuccess: () => setIsEditing(false),
        });
    };

    const handleSignOff = () => {
        onSignOff(strategy);
    };

    const isSignedOff = !!strategy.signed_off_at;

    /*
       What was produced, as one phrase.

       Three coloured pills — blue for copy, green for images, purple for video
       — said the same thing in more space and more colours, and the colours
       carried no meaning: nothing distinguishes an image from a video that a
       reader needs a palette for.
    */
    const collateralSummary = [
        [strategy.ad_copies_count, 'ad copy', 'ad copies'],
        [strategy.image_collaterals_count, 'image', 'images'],
        [strategy.video_collaterals_count, 'video', 'videos'],
    ]
        .filter(([n]) => n > 0)
        .map(([n, one, many]) => `${n} ${n === 1 ? one : many}`)
        .join(', ');

    return (
        <div className={`p-6 rounded-xl shadow-sm border ${isSignedOff ? 'bg-gray-50 border-gray-200' : 'bg-white border-gray-200'}`}>
            <div className="flex justify-between items-center mb-5 pb-4 border-b border-gray-100">
                <div>
                    <h3 className="text-xl font-bold text-delft-blue">{strategy.platform}</h3>
                    <p className="text-xs text-gray-500 mt-0.5">AI-generated strategy</p>
                </div>
                {!isSignedOff && !isEditing && (
                    <button onClick={() => setIsEditing(true)} className="inline-flex items-center gap-1.5 text-sm font-medium text-brand-dark hover:text-brand-darker hover:bg-brand-tint-10 px-3 py-1.5 rounded-lg transition">
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        Edit
                    </button>
                )}
            </div>

            {strategy.creative_concepts?.length > 0 && <div className="mb-5 grid gap-3 sm:grid-cols-3">{strategy.creative_concepts.map((concept, index) => <div key={index} className="rounded-lg border border-gray-200 bg-white p-4"><p className="text-xs text-gray-500">Concept {index + 1}</p><h4 className="mt-1 font-semibold text-gray-900">{concept.selling_idea}</h4><p className="mt-2 text-sm text-gray-600">{concept.evidence}</p>{concept.visual_style && <p className="mt-3 text-xs font-medium text-brand-dark">{concept.visual_style.replaceAll('_', ' ')} · {concept.composition.replaceAll('_', ' ')}</p>}<p className="mt-2 text-sm text-gray-600">{concept.visual}</p></div>)}</div>}
            {isEditing ? (
                <form onSubmit={handleUpdate} className="space-y-4">
                    <div>
                        <label className="font-bold text-jet">Ad Copy Strategy</label>
                        <textarea value={data.ad_copy_strategy} onChange={e => setData('ad_copy_strategy', e.target.value)} className="w-full mt-1 border-gray-300 rounded-md shadow-sm" />
                    </div>
                    <div>
                        <label className="font-bold text-jet">Imagery Strategy</label>
                        <textarea value={data.imagery_strategy} onChange={e => setData('imagery_strategy', e.target.value)} className="w-full mt-1 border-gray-300 rounded-md shadow-sm" />
                    </div>
                    {strategy.campaign_type !== 'search' && <div>
                        <label className="font-bold text-jet">Video Strategy</label>
                        <textarea value={data.video_strategy} onChange={e => setData('video_strategy', e.target.value)} className="w-full mt-1 border-gray-300 rounded-md shadow-sm" />
                    </div>}
                    <div className="flex justify-end space-x-2">
                        <button type="button" onClick={() => setIsEditing(false)} className="text-sm text-gray-600">Cancel</button>
                        <PrimaryButton disabled={processing}>Save Changes</PrimaryButton>
                    </div>
                </form>
            ) : (
                /*
                   Signed off, so the brief steps out of the way.
                   
                   These three paragraphs are instructions to the model, and
                   they are worth reading once — before approving them. After
                   approval the thing the customer came for is the creative, and
                   leaving a wall of strategy prose above it buries the work
                   under its own brief. Still one click away, because someone
                   checking why a creative looks the way it does needs it.
                */
                <details className="group">
                    <summary className="cursor-pointer list-none text-sm text-gray-500 hover:text-gray-700 transition">
                        <span className="group-open:hidden">Show the brief this was built from</span>
                        <span className="hidden group-open:inline">Hide the brief</span>
                    </summary>
                    <div className="mt-4 space-y-5">
                        <div>
                            <h4 className="flex items-center gap-2 text-sm font-semibold text-jet mb-1.5"><span>✍️</span>Ad Copy Strategy</h4>
                            <p className="text-gray-600 leading-relaxed whitespace-pre-wrap">{strategy.ad_copy_strategy}</p>
                        </div>
                        <div className="pt-5 border-t border-gray-100">
                            <h4 className="flex items-center gap-2 text-sm font-semibold text-jet mb-1.5"><span>🖼️</span>Imagery Strategy</h4>
                            <p className="text-gray-600 leading-relaxed whitespace-pre-wrap">{strategy.imagery_strategy}</p>
                        </div>
                        {strategy.campaign_type !== 'search' && <div className="pt-5 border-t border-gray-100">
                            <h4 className="flex items-center gap-2 text-sm font-semibold text-jet mb-1.5"><span>🎬</span>Video Strategy</h4>
                            <p className="text-gray-600 leading-relaxed whitespace-pre-wrap">{strategy.video_strategy}</p>
                        </div>}
                    </div>
                </details>
            )}

            {isSignedOff ? (
                /*
                   A quiet status line, not a green slab.
                   
                   bg-green-100 across the full width, centred, with the button
                   sitting inside the tint — nothing else in the application
                   looks like that, which is exactly why it read as unfinished.
                   Approval is the unremarkable case here; every strategy on
                   this page is signed off, so colouring each one as a success
                   banner spends emphasis on the status quo. A check, the date,
                   what was produced, and the one action worth taking.
                */
                <div className="mt-6 pt-5 border-t border-gray-100 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2 text-sm text-gray-500">
                        <svg className="w-4 h-4 text-green-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                        <span>
                            Approved {new Date(strategy.signed_off_at).toLocaleDateString()}
                            {collateralSummary ? ` · ${collateralSummary}` : ''}
                        </span>
                    </div>
                    <Link
                        href={route('campaigns.collateral.show', { campaign: campaignUuid, strategy: strategy.uuid })}
                        className="px-4 py-2 text-sm font-medium bg-brand-dark text-white rounded-lg hover:bg-brand-darker transition whitespace-nowrap"
                    >
                        Review ads
                    </Link>
                </div>
            ) : (
                <div className="mt-6 pt-5 border-t border-gray-100">
                    <button
                        onClick={handleSignOff}
                        disabled={processing}
                        className="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-lg bg-emerald-600 text-white font-semibold shadow-sm hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg>
                        {processing ? 'Signing off…' : 'Approve direction & prepare ads'}
                    </button>
                    <p className="mt-2 text-center text-xs text-gray-500">Locks the strategy and starts generating your ad creative — you can edit it first.</p>
                </div>
            )}
        </div>
    );
};

// Elapsed time is not evidence that a research or generation stage completed.
const StrategyGenerationLoader = ({ elapsedSeconds, campaignName }) => (
    <section className="mb-8 rounded-xl border border-gray-200 bg-white p-8" role="status">
        <h2 className="text-xl font-semibold text-gray-900">Preparing your campaign</h2>
        <p className="mt-2 text-gray-600">We are waiting for the campaign plan for {campaignName}. It will appear here when saved.</p>
        <p className="mt-4 text-sm text-gray-500">{Math.floor(elapsedSeconds / 60)}m {elapsedSeconds % 60}s elapsed</p>
        {elapsedSeconds > 300 && <p className="mt-3 text-sm text-amber-800">This is taking longer than expected. Your business details are saved; you can return from your dashboard.</p>}
    </section>
);

export default function Show({ auth, campaign, canRegenerate = true, conversionTracking = null, selfFunded = false, setupOnly = false }) {
    const [campaigns, setCampaign] = useState(campaign);
    // Saves return fresh Inertia props without remounting this page. Keep the
    // review card in sync so it shows the brief that was actually saved.
    useEffect(() => {
        setCampaign(campaign);
    }, [campaign]);
    // Whether generation is still running, as far as this page knows. Seeded
    // from the server render, then driven by the watch below.
    const [isPolling, setIsPolling] = useState(
        campaign.is_generating_strategies ||
        (campaign.strategies.length === 0 && campaign.strategy_generation_started_at)
    );
    const [showGenerationModal, setShowGenerationModal] = useState(false);
    const [confirmModal, setConfirmModal] = useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
    const [elapsedSeconds, setElapsedSeconds] = useState(0);
    const [copilotOpen, setCopilotOpen] = useState(false);
    const { post, processing } = useForm();

    // Watch strategy generation to completion.
    //
    // This was a hand-rolled setInterval + raw fetch whose only failure
    // handling was console.error. Any non-OK response — an expired session, a
    // deploy mid-poll, a 403 — left the spinner turning with nothing said,
    // until a five-minute timeout finally showed "taking longer than expected".
    // The user could not tell a slow generation from a dead page, and the only
    // way out was to navigate in again from somewhere else.
    //
    // useJobWatch distinguishes those: `failed` (the server reported an error),
    // `timeout` (took too long), and `disconnected` (the endpoint itself has
    // been failing), so the page can say which.
    const { phase: watchPhase, data: watchData } = useJobWatch(
        route('api.campaigns.show', { campaign: campaigns.uuid }),
        {
            enabled: isPolling,
            interval: 10000,
            timeoutMs: 5 * 60 * 1000,
            isFailed: (d) => Boolean(d?.strategy_generation_error),
            isDone: (d) => Boolean(d?.strategies?.length) && !d?.is_generating_strategies,
        }
    );

    // Mirror each poll onto the rendered campaign so strategies appear the
    // moment generation finishes.
    useEffect(() => {
        if (watchData) {
            setCampaign(watchData);
        }
    }, [watchData]);

    /*
       Keep watching for the collateral, not just the strategies.

       isPolling covered strategy generation only, so it switched itself off the
       moment strategies existed — which is the moment collateral generation
       starts. The card underneath then sat on "Generating your collateral…"
       until the customer thought to refresh, on the screen they had just been
       told to wait on. Same defect as the collateral page had, one page over.

       A signed-off strategy with nothing attached to it is work still arriving.
       The endpoint already returns the counts; nothing was asking for them.
    */
    /*
       Whether pictures are probably still on their way.

       Derived from how long ago the strategy was signed off rather than from a
       flag, because there is no server field that means this and the
       timestamps already answer it. Images take a couple of minutes; past
       IMAGE_WAIT_MS nothing more is coming, and a strategy that will never
       have images — video-only, or an exhausted allowance — must not strand
       anyone watching for one.
    */
    const IMAGE_WAIT_MS = 5 * 60 * 1000;

    const stillGeneratingImages = (s) => {
        if ((s.image_collaterals_count || 0) > 0) return false;

        const signedOff = new Date(s.signed_off_at).getTime();

        return Number.isFinite(signedOff) && (Date.now() - signedOff) < IMAGE_WAIT_MS;
    };

    /*
       One definition, used by all three of the wait, the poll and the jump.

       They disagreed, and the disagreement froze the page. The wait and the
       poll both counted ad copy as "something arrived" while the jump insisted
       on a picture — so the moment ad copy landed, which is first and fastest,
       polling stopped and the jump refused. The card sat on "Generating your
       collateral... this usually takes 1-2 minutes" over nine images that had
       finished two minutes earlier, and nothing would ever move it.

       The question every one of them is asking is the same: is this strategy
       still waiting for something?
    */
    const stillWaiting = (s) => Boolean(s.signed_off_at)
        && ((s.image_collaterals_count || 0) === 0)
        && (stillGeneratingImages(s) || ((s.ad_copies_count || 0) + (s.video_collaterals_count || 0)) === 0);

    const awaitingCollateral = (campaigns.strategies || []).some(stillWaiting);

    const { data: collateralPoll } = usePolling(
        awaitingCollateral ? route('api.campaigns.show', { campaign: campaigns.uuid }) : null,
        {
            interval: 8000,
            // Stops when nothing is still waiting — the same question the
            // page and the jump ask, so they cannot disagree about the answer.
            until: (d) => ! (d?.strategies || []).some(stillWaiting),
        }
    );

    useEffect(() => {
        if (collateralPoll) {
            setCampaign(collateralPoll);
        }
    }, [collateralPoll]);

    /*
       When the wait is over, go where they were waiting to get to.

       Signing off leads to a modal, then this page, then a card that says
       "Generating your collateral" — and then, once it had arrived, a second
       click to go and look at it. Standing here was only ever about seeing the
       creative; making that last step manual is what turns a two-minute wait
       into something that feels broken.

       Only after an actual wait. The ref is set while polling is live, so
       opening this page for a campaign whose collateral finished last week
       leaves you on the page you asked for.
    */
    const waitedForCollateralRef = useRef(false);

    useEffect(() => {
        if (awaitingCollateral) {
            waitedForCollateralRef.current = true;

            return;
        }

        if (! waitedForCollateralRef.current) return;

        /*
           Wait for a picture, not for anything at all.

           This matched on the sum of all three counts, and ad copy is written
           first and fastest — so it fired the moment the copy landed and
           dropped the customer onto a collateral page with no creative on it.
           They came to see the ads; arriving before any exist is barely better
           than the spinner it replaced.

           A strategy that will never have images (video-only, or an exhausted
           allowance) still advances on whatever it does have, so this cannot
           strand anyone waiting for something that is not coming.
        */
        const ready = (campaigns.strategies || []).find(
            s => s.signed_off_at && (s.image_collaterals_count || 0) > 0
        ) || (campaigns.strategies || []).find(
            s => s.signed_off_at
                && ! stillGeneratingImages(s)
                && ((s.ad_copies_count || 0) + (s.video_collaterals_count || 0)) > 0
        );

        if (! ready) return;

        waitedForCollateralRef.current = false;
        router.visit(route('campaigns.collateral.show', { campaign: campaigns.uuid, strategy: ready.uuid }));
    }, [awaitingCollateral, campaigns.strategies, campaigns.uuid]);

    useEffect(() => {
        if (['done', 'failed', 'timeout', 'disconnected'].includes(watchPhase)) {
            setIsPolling(false);
        }
    }, [watchPhase]);

    const pollingError = ['failed', 'timeout', 'disconnected'].includes(watchPhase);

    // Elapsed time counter for generation loading state
    useEffect(() => {
        if (!isPolling) return;
        const startTime = campaigns.strategy_generation_started_at
            ? new Date(campaigns.strategy_generation_started_at).getTime()
            : Date.now();
        const tick = () => setElapsedSeconds(Math.floor((Date.now() - startTime) / 1000));
        tick();
        const interval = setInterval(tick, 1000);
        return () => clearInterval(interval);
    }, [isPolling, campaigns.strategy_generation_started_at]);

    const handleSignOffStrategy = (strategy) => {
        setConfirmModal({
            show: true,
            title: 'Sign Off Strategy',
            message: `Are you sure you want to sign off on the ${strategy.platform} strategy? This will lock it and you won't be able to edit it anymore.`,
            onConfirm: () => {
                setConfirmModal({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
                post(route('campaigns.strategies.sign-off', { campaign: campaigns.uuid, strategy: strategy.uuid }), {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        // Update local state with fresh data from server
                        setCampaign(page.props.campaign);
                    }
                });
            },
            isDestructive: false,
            confirmText: 'Sign Off Strategy'
        });
    };

    const handleSignOffAll = () => {
        setConfirmModal({
            show: true,
            title: 'Sign Off All Strategies',
            message: 'Are you sure you want to sign off on all strategies? This will lock them and start generating collateral for all platforms.',
            onConfirm: () => {
                setConfirmModal({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
                post(route('campaigns.sign-off-all', { campaign: campaigns.uuid }), {
                    preserveScroll: true,
                    onSuccess: (page) => {
                        setShowGenerationModal(true);
                        // Update local state with fresh data
                        setCampaign(page.props.campaign);
                    }
                });
            },
            isDestructive: false,
            confirmText: 'Sign Off All'
        });
    };

    const allStrategiesSignedOff = campaigns.strategies.every(strategy => !!strategy.signed_off_at);
    const anyStrategiesSignedOff = campaigns.strategies.some(strategy => !!strategy.signed_off_at);

    const handleRegenerate = (force = false) => {
        setConfirmModal({
            show: true,
            title: force ? 'Force Regenerate Strategies' : 'Regenerate Strategies',
            message: force
                ? 'Your current strategy and creative stay available while a replacement is generated. After it succeeds, the new strategy replaces them and needs your approval.'
                : 'This will delete all current strategies and generate new ones using AI. Are you sure?',
            onConfirm: () => {
                setConfirmModal({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
                router.post(route('campaigns.regenerate-strategies', { campaign: campaigns.uuid }), { force: force ? 1 : 0 }, {
                    preserveScroll: true,
                    onSuccess: () => {
                        // Re-arming the watch resets its phase, and pollingError
                        // is derived from that — nothing else to clear.
                        setIsPolling(true);
                        setElapsedSeconds(0);
                    }
                });
            },
            isDestructive: true,
            confirmText: force ? 'Yes, Force Regenerate' : 'Regenerate'
        });
    };

    /*
       Two decisions, two screens.

       This page asked the customer to set a daily budget and to sign off on
       the strategies behind it, stacked in one scroll with a market forecast
       between them. They are separate questions — what am I willing to spend,
       and is this the right campaign — and answering the second means
       scrolling past the first, which is already answered. On a one-time setup
       it is also the page where they press Create, so the length was standing
       between them and the thing they paid for.

       The budget comes first because it is the input the forecast and the ad
       group bids are framed against, and because the server will not deploy an
       auto-generated campaign until it is confirmed. Once set, it collapses to
       one line with a way back — ?budget=1 returns here deliberately, so
       changing your mind is a link rather than a dead end.
    */
    const [showForecast, setShowForecast] = useState(false);
    const pageUrl = usePage().url;
    const revisitingBudget = pageUrl.includes('budget=1');
    const budgetStage = Boolean(campaign.auto_generated_at)
        && (! campaign.budget_confirmed_at || revisitingBudget);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-jet leading-tight">
                {budgetStage ? `Your campaign budget` : `Your campaign — ${campaigns.name}`}
            </h2>}
        >
            <Head title={`Your campaign — ${campaigns.name}`} />
            {setupOnly && <div className="mx-auto max-w-7xl px-4 pt-6"><SetupStages stage={1} /></div>}

            {/* Leads the page for campaigns we generated: it is the one thing
                standing between the customer and deploying, and without it they
                would click Deploy and be told to confirm a budget with nowhere
                to do it. */}
            {/*
                The deployment status page (campaigns.deployment-status) existed
                with no inbound link from anywhere — a route the customer could
                only reach by typing it. It is the only place that shows
                per-platform deployment state and the error message when one
                fails, which is exactly what someone whose ads have not appeared
                is looking for.
            */}
            {! budgetStage && campaign.strategies?.some(s => s.deployed_at || s.deployment_status) && (
                <div className="mx-auto max-w-7xl mb-6">
                    <Link
                        href={route('campaigns.deployment-status', { campaign: campaign.uuid })}
                        className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        View deployment status
                    </Link>
                </div>
            )}

            {budgetStage && (
                <div className="mx-auto max-w-7xl">
                    {/* The prop, not the polled local state: confirming the
                        budget redirects back with fresh props, and the local
                        copy is only seeded once so it would still show as
                        unconfirmed. */}
                    <BudgetConfirmation
                        campaign={campaign}
                        currency={campaign.currency_code || 'USD'}
                        selfFunded={selfFunded}
                        setupOnly={setupOnly}
                    />

                    <p className="mt-4 text-sm text-gray-500">
                        Next: the campaign and ads we wrote, for you to read and approve.
                    </p>
                </div>
            )}

            {/* The answered question, kept visible but out of the way. The
                forecast lives with it: it is a framing of the budget, so it
                belongs to that decision rather than to the strategy review. */}
            {! budgetStage && campaign.auto_generated_at && campaign.budget_confirmed_at && (
                <div className="mx-auto max-w-7xl mb-6">
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-5 py-3">
                        <p className="text-sm text-gray-700">
                            <span className="font-semibold">Daily budget</span>{' '}
                            {campaign.currency_code || 'USD'} {Number(campaign.daily_budget || 0).toFixed(2)}
                            {selfFunded && <span className="text-gray-500"> — billed to you by Google, not by us</span>}
                        </p>
                        <div className="flex items-center gap-4">
                            <button
                                type="button"
                                onClick={() => setShowForecast(v => ! v)}
                                className="text-sm font-medium text-gray-600 underline hover:text-gray-900"
                            >
                                {showForecast ? 'Hide forecast' : 'What this buys'}
                            </button>
                            <Link
                                href={`${route('campaigns.show', { campaign: campaigns.uuid })}?budget=1`}
                                className="text-sm font-medium text-brand-dark underline hover:text-brand-darker"
                            >
                                Change
                            </Link>
                        </div>
                    </div>

                    {showForecast && (
                        <ForecastPanel
                            className="mt-3"
                            campaignId={campaign.id}
                            monthlyBudget={Number(campaign.daily_budget || 0) * 30}
                        />
                    )}
                </div>
            )}

            {/* Conversion tracking, surfaced where the launch decision happens:
                without the snippet the ads run blind, and the setup page was
                previously only reachable from an email. */}
            {! budgetStage && conversionTracking && (
                <div className="mx-auto max-w-7xl">
                    {conversionTracking.installed ? (
                        <div className="rounded-lg border border-green-200 bg-green-50 px-5 py-3 text-sm text-green-800 flex items-center gap-2">
                            <span aria-hidden="true">✓</span>
                            Conversion tracking is active — every lead and sale from this campaign will be counted.
                        </div>
                    ) : (
                        <div className="rounded-lg border border-amber-300 bg-amber-50 p-5 flex flex-col sm:flex-row sm:items-center gap-4">
                            <div className="flex-1 min-w-0">
                                <p className="font-semibold text-amber-900">Install your tracking snippet before launch</p>
                                <p className="text-sm text-amber-800 mt-0.5">
                                    {conversionTracking.container_id
                                        ? <>Your Google Tag Manager container <code className="font-mono bg-amber-100 px-1.5 py-0.5 rounded">{conversionTracking.container_id}</code> is ready — add the snippet to your website so every lead and sale your ads bring is counted from day one.</>
                                        : 'Set up conversion tracking so every lead and sale your ads bring is counted from day one.'}
                                </p>
                            </div>
                            <Link
                                href={conversionTracking.setup_url}
                                className="flex-shrink-0 px-5 py-2.5 bg-brand-primary hover:bg-brand-dark text-white rounded-md font-semibold text-sm"
                            >
                                Get the snippet →
                            </Link>
                        </div>
                    )}
                </div>
            )}

            <ConfirmationModal
                show={confirmModal.show}
                onClose={() => setConfirmModal({ show: false, title: '', message: '', onConfirm: null, isDestructive: false })}
                onConfirm={confirmModal.onConfirm}
                title={confirmModal.title}
                message={confirmModal.message}
                confirmText={confirmModal.confirmText}
                isDestructive={confirmModal.isDestructive}
                confirmButtonClass={confirmModal.confirmButtonClass}
            />

            <CollateralGenerationModal
                show={showGenerationModal}
                onClose={() => setShowGenerationModal(false)}
            />

            {/* Step 2. Not merely hidden on the budget screen — unmounted, so
                the strategy cards and their collateral polling do not run
                behind a decision that has not been made yet. */}
            {! budgetStage && (
            <div className="py-12">
                <div className="max-w-7xl mx-auto">
                    {isPolling && (
                        <StrategyGenerationLoader
                            elapsedSeconds={elapsedSeconds}
                            campaignName={campaigns.name}
                        />
                    )}

                    {pollingError && (
                        <div className="mb-8 p-6 bg-red-100 border border-red-400 text-red-700 rounded-lg">
                            <div className="flex items-center">
                                <svg className="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div>
                                    {/* Which of the three it is matters: a lost
                                        connection is fixed by reloading, a slow
                                        generation is fixed by waiting, and a
                                        real failure is neither. The old panel
                                        said "taking longer than expected" for
                                        all of them. */}
                                    <p className="font-semibold">
                                        {watchPhase === 'disconnected'
                                            ? 'Lost connection to the server'
                                            : campaigns.strategy_generation_error
                                                ? 'Strategy generation failed'
                                                : 'Strategy generation is taking longer than expected'}
                                    </p>
                                    <p className="text-sm mt-1">
                                        {watchPhase === 'disconnected'
                                            ? 'We stopped receiving updates — your session may have expired. Reload the page to pick up where this got to.'
                                            : campaigns.strategy_generation_error
                                                ? campaigns.strategy_generation_error
                                                : 'Please refresh the page in a moment or contact support if this persists.'}
                                    </p>
                                    <button
                                        type="button"
                                        onClick={() => router.reload()}
                                        className="mt-3 px-4 py-2 bg-red-700 text-white text-sm rounded-md font-semibold hover:bg-red-800"
                                    >
                                        Reload
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Collateral Summary */}
                    <CollateralSummaryCard campaign={campaigns} />

                    {campaigns.strategies && campaigns.strategies.length > 0 && (
                        <div className="mb-8 flex justify-end gap-3">
                            {!anyStrategiesSignedOff && (
                                <button
                                    onClick={() => handleRegenerate(false)}
                                    disabled={processing || isPolling || !canRegenerate}
                                    title={!canRegenerate ? 'Regenerating strategies is available on paid plans — upgrade to use it.' : undefined}
                                    className="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 disabled:opacity-50 transition"
                                >
                                    <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Regenerate Strategies
                                </button>
                            )}
                            {anyStrategiesSignedOff && (
                                <button
                                    onClick={() => handleRegenerate(true)}
                                    disabled={processing || isPolling || !canRegenerate}
                                    title={!canRegenerate ? 'Regenerating strategies is available on paid plans — upgrade to use it.' : undefined}
                                    className="inline-flex items-center px-4 py-2 bg-white border border-red-300 rounded-md font-semibold text-xs text-red-700 uppercase tracking-widest shadow-sm hover:bg-red-50 disabled:opacity-50 transition"
                                >
                                    <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Force Regenerate
                                </button>
                            )}
                            <PrimaryButton onClick={handleSignOffAll} disabled={processing || allStrategiesSignedOff}>
                                {allStrategiesSignedOff ? 'Creative direction approved' : 'Approve direction & prepare ads'}
                            </PrimaryButton>
                        </div>
                    )}

                    {campaigns.strategies && campaigns.strategies.length > 0 ? (
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            {campaigns.strategies.map(strategy => (
                                <StrategyCard
                                    key={strategy.id}
                                    strategy={strategy}
                                    campaignUuid={campaigns.uuid}
                                    onSignOff={handleSignOffStrategy}
                                />
                            ))}
                        </div>
                    ) : (
                        !isPolling && (
                            <div className="text-center p-8 bg-mint-cream rounded-lg">
                                <p className="text-gray-600">No strategies generated yet.</p>
                            </div>
                        )
                    )}
                </div>
            </div>
            )}

            {/* Campaign Copilot */}
            <CampaignCopilot campaignUuid={campaigns.uuid} isOpen={copilotOpen} onClose={() => setCopilotOpen(false)} />

            {/* Copilot FAB */}
            {!copilotOpen && (
                <button
                    onClick={() => setCopilotOpen(true)}
                    className="fixed bottom-6 right-6 z-40 bg-brand-dark text-white p-4 rounded-full shadow-lg hover:bg-brand-darker hover:scale-105 transition-all group"
                    title="Ask Campaign Copilot"
                >
                    <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
                    </svg>
                    <span className="absolute right-full mr-3 top-1/2 -translate-y-1/2 bg-gray-900 text-white text-xs px-2 py-1 rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity">
                        Campaign Copilot
                    </span>
                </button>
            )}
        </AuthenticatedLayout>
    );
}
