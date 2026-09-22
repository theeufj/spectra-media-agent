import { SetupStages } from '@/Components/SetupJourney';
import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { money, count } from '@/utils/format';
import { groupConcepts, FORMAT_LABELS } from '@/utils/collateral';
import { useCurrency } from '@/hooks/useCurrency';
import RefineImageModal from '@/Components/RefineImageModal';
import ExtendVideoModal from '@/Components/ExtendVideoModal';
import SubscriptionRequiredModal from '@/Components/SubscriptionRequiredModal';
import DeploymentDisabledModal from '@/Components/DeploymentDisabledModal';
import AdSpendSetupModal from '@/Components/AdSpendSetupModal';
import ConfirmationModal from '@/Components/ConfirmationModal';
import Modal from '@/Components/Modal';
import CreativeSizesModal from '@/Components/CreativeSizesModal';
import AdPreviewPanel from '@/Components/AdPreview';
import { useToast } from '@/Components/Toast';
import { useCollateralGeneration } from '@/hooks/useCollateralGeneration';

/**
 * Make a clickable div behave as a checkbox for everyone.
 *
 * Approving creative for deployment — which ad copy, which images, which
 * videos actually go live — was three `<div onClick>` handlers with
 * `cursor-pointer` and nothing else. No keyboard path, no role, no state
 * announced. The ad-copy panel even instructs "Check the box to include this
 * ad copy in deployment" above a thing that is not a checkbox and cannot be
 * checked without a mouse.
 *
 * Returns the props rather than a component so the existing markup and its
 * conditional borders stay exactly as they are.
 */
function approvalToggle({ checked, onToggle, label, enabled = true }) {
    if (!enabled) return {};

    return {
        role: 'checkbox',
        'aria-checked': checked,
        'aria-label': label,
        tabIndex: 0,
        onClick: onToggle,
        onKeyDown: (e) => {
            // Space is what a checkbox answers to; Enter is what people try.
            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                onToggle();
            }
        },
    };
}

/**
 * What an account that cannot download yet is asked to do about it.
 *
 * Every one of these said "Upgrade" and pointed at the monthly plans. A
 * setup-only customer has already chosen "one payment, nothing recurring" and
 * paid or intends to pay US$999 — sending them to a menu of subscriptions
 * under a button marked Upgrade asks them to buy the thing they explicitly
 * did not want, and hides the one they did. The $999 card is on that page, but
 * being on the page is not the same as being what was asked for.
 */
export function UnlockAction({ setupOnly, label, className }) {
    if (setupOnly) {
        return (
            <button
                onClick={(e) => { e.stopPropagation(); router.post(route('setup-fee.checkout')); }}
                className={className}
            >
                🔒 {label} — pay your US$999
            </button>
        );
    }

    return (
        <a href={route('subscription.pricing')} onClick={(e) => e.stopPropagation()} className={className}>
            🔒 Upgrade to {label.toLowerCase()}
        </a>
    );
}

export default function Collateral({ campaign, currentStrategy, allStrategies, adCopy, imageCollaterals, videoCollaterals, collateralErrors = {}, hasActiveSubscription, hasPaymentMethod, deploymentEnabled, managedBillingEnabled, adSpendCredit, creativeUsage, harvestedAssetCount = 0, setupOnly = false, generationPending = false, supportsVideo = true, reviewSummary = {}, creativeReview = null }) {
    const currency = useCurrency();
    const { auth } = usePage().props;
    const isSubscribed = hasActiveSubscription || auth.user?.subscription_status === 'active';
    const toast = useToast();
    const [activeTab, setActiveTab] = useState(currentStrategy.platform);
    const { generatingAdCopy, setGeneratingAdCopy, generatingImage, setGeneratingImage, generatingVideo, setGeneratingVideo, collateral, setCollateral, isPolling, setIsPolling, collateralError, setCollateralError } = useCollateralGeneration({ currentStrategy, adCopy, imageCollaterals, videoCollaterals, generationPending, creativeReview });
    const [editingImage, setEditingImage] = useState(null);
    const [extendingVideo, setExtendingVideo] = useState(null);

    // Which concept is open in the size viewer, if any.
    const [viewingConcept, setViewingConcept] = useState(null);
    const [showDeployModal, setShowDeployModal] = useState(false);
    const [showSubscriptionModal, setShowSubscriptionModal] = useState(false);
    const [showDeploymentDisabledModal, setShowDeploymentDisabledModal] = useState(false);
    const [showAdSpendSetupModal, setShowAdSpendSetupModal] = useState(false);
    const [showPreview, setShowPreview] = useState(false);
    const [harvestedAssets, setHarvestedAssets] = useState([]);
    const [showHarvestedPanel, setShowHarvestedPanel] = useState(false);
    const [loadingHarvested, setLoadingHarvested] = useState(false);
    const [harvestingInProgress, setHarvestingInProgress] = useState(false);
    const [confirmModal, setConfirmModal] = useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
    const [uploadingImages, setUploadingImages] = useState(false);
    const [uploadingVideo, setUploadingVideo] = useState(false);
    const [imageUploadErrors, setImageUploadErrors] = useState([]);
    const [deployDropdownOpen, setDeployDropdownOpen] = useState(false);
    const deployDropdownRef = useRef(null);

    // Function to handle tab changes
    const handleTabChange = (platform) => {
        setActiveTab(platform);
    };

    const handleGenerateAdCopy = (strategyUuid, platform) => {
        setConfirmModal({
            show: true,
            title: 'Generate Ad Copy',
            message: `Are you sure you want to generate ad copy for ${platform}? This will overwrite any existing ad copy for this platform.`,
            onConfirm: () => {
                setConfirmModal({ ...confirmModal, show: false });
                setGeneratingAdCopy(true);
                setIsPolling(true);
                router.post(route('campaigns.ad-copy.store', { campaign: campaign.uuid, strategy: strategyUuid }), { platform: platform }, {
                    onError: (errors) => {
                        setGeneratingAdCopy(false);
                        setIsPolling(false);
                        toast.error('Failed to generate ad copy: ' + (errors.platform || 'An unknown error occurred.'));
                    },
                    preserveScroll: true,
                });
            },
            isDestructive: false
        });
    };

    const handleGenerateImage = (strategyUuid) => {
        setConfirmModal({
            show: true,
            title: 'Generate Image',
            message: 'Are you sure you want to generate an image for this strategy? This will dispatch a background job.',
            onConfirm: () => {
                setConfirmModal({ ...confirmModal, show: false });
                setGeneratingImage(true);
                setIsPolling(true);
                router.post(route('campaigns.collateral.image.store', { campaign: campaign.uuid, strategy: strategyUuid }), {}, {
                    onError: (errors) => {
                        setGeneratingImage(false);
                        setIsPolling(false);
                        toast.error('Failed to start image generation: ' + (errors.message || 'An unknown error occurred.'));
                    },
                    preserveScroll: true,
                });
            },
            isDestructive: false
        });
    };

    const handleGenerateVideo = (strategyUuid, platform) => {
        setConfirmModal({
            show: true,
            title: 'Generate Video',
            message: 'Are you sure you want to generate a video for this strategy? This can take several minutes.',
            onConfirm: () => {
                setConfirmModal({ ...confirmModal, show: false });
                setGeneratingVideo(true);
                setIsPolling(true);
                router.post(route('campaigns.collateral.video.store', { campaign: campaign.uuid, strategy: strategyUuid }), { platform }, {
                    onError: (errors) => {
                        setGeneratingVideo(false);
                        setIsPolling(false);
                        toast.error('Failed to start video generation: ' + (errors.message || 'An unknown error occurred.'));
                    },
                    preserveScroll: true,
                });
            },
            isDestructive: false
        });
    };

    const handleRefinementStart = () => {
        setIsPolling(true);
    };

    const handleToggleCollateral = (type, id, field = 'should_deploy') => {
        // Optimistic update — local state responds immediately; server persists in background
        setCollateral(prev => {
            if (type === 'ad_copy') {
                return { ...prev, adCopy: { ...prev.adCopy, [field]: !prev.adCopy[field] } };
            }
            if (type === 'image') {
                return {
                    ...prev,
                    imageCollaterals: prev.imageCollaterals.map(img =>
                        img.id === id ? { ...img, [field]: !img[field] } : img
                    ),
                };
            }
            if (type === 'video') {
                return {
                    ...prev,
                    videoCollaterals: prev.videoCollaterals.map(vid =>
                        vid.id === id ? { ...vid, [field]: !vid[field] } : vid
                    ),
                };
            }
            return prev;
        });

        router.post(route('deployment.toggle-collateral'), { type, id, field }, {
            preserveScroll: true,
            onError: (errors) => {
                console.error('Failed to toggle collateral status:', errors);
            },
        });
    };

    /**
     * A card is one photograph stored in three ad sizes, so approving it has
     * to approve all three. Toggling only the square deployed a campaign with
     * its landscape and display sizes silently left behind.
     */
    const handleToggleConcept = (conceptGroup, field = 'should_deploy') => {
        const next = field === 'should_deploy' ? ! conceptGroup.deployed : ! conceptGroup.cover[field];

        setCollateral(prev => ({
            ...prev,
            imageCollaterals: prev.imageCollaterals.map(img =>
                conceptGroup.ids.includes(img.id) ? { ...img, [field]: next } : img
            ),
        }));

        conceptGroup.ids.forEach(id => {
            router.post(route('deployment.toggle-collateral'), { type: 'image', id, field, value: next }, {
                preserveScroll: true,
                onError: (errors) => console.error('Failed to toggle collateral status:', errors),
            });
        });
    };

    const handleImageUpload = (strategyUuid, files) => {
        if (!files || files.length === 0) return;
        setUploadingImages(true);
        setImageUploadErrors([]);

        const formData = new FormData();
        Array.from(files).forEach((file) => {
            formData.append('images[]', file);
        });

        router.post(route('campaigns.collateral.image.upload', { campaign: campaign.uuid, strategy: strategyUuid }), formData, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setUploadingImages(false);
                setIsPolling(true);
            },
            onError: (errors) => {
                setUploadingImages(false);
                const msgs = Object.values(errors).flat();
                setImageUploadErrors(msgs);
                toast.error('Upload failed: ' + msgs.join(' '));
            },
        });
    };

    const handleVideoUpload = (strategyUuid, file) => {
        if (!file) return;
        setUploadingVideo(true);

        const formData = new FormData();
        formData.append('video', file);

        router.post(route('campaigns.collateral.video.upload', { campaign: campaign.uuid, strategy: strategyUuid }), formData, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setUploadingVideo(false);
                setIsPolling(true);
            },
            onError: (errors) => {
                setUploadingVideo(false);
                const msgs = Object.values(errors).flat();
                toast.error('Upload failed: ' + msgs.join(' '));
            },
        });
    };

    const handleDeleteCollateral = (type, id, name) => {
        setConfirmModal({
            show: true,
            title: `Delete ${type === 'image' ? 'Image' : 'Video'}`,
            message: `Are you sure you want to delete this uploaded ${type}? This cannot be undone.`,
            onConfirm: () => {
                setConfirmModal({ ...confirmModal, show: false });
                const routeName = type === 'image' ? 'image-collaterals.destroy' : 'video-collaterals.destroy';
                const param = type === 'image' ? { image_collateral: id } : { video: id };
                router.delete(route(routeName, param), {
                    preserveScroll: true,
                    onSuccess: () => {
                        setIsPolling(true);
                    },
                });
            },
            isDestructive: true,
        });
    };

    // --- Harvested Assets ---
    const loadHarvestedAssets = () => {
        setLoadingHarvested(true);
        fetch(route('harvested-assets.index'))
            .then(r => r.json())
            .then(data => {
                setHarvestedAssets(data.assets || []);
                setLoadingHarvested(false);
            })
            .catch(() => setLoadingHarvested(false));
    };

    const handleHarvest = () => {
        setHarvestingInProgress(true);
        router.post(route('harvested-assets.harvest'), {}, {
            preserveScroll: true,
            onSuccess: () => {
                setHarvestingInProgress(false);
                toast.success('Asset harvesting started — images will appear as they are processed.');
            },
            onError: () => setHarvestingInProgress(false),
        });
    };

    const handleUseHarvestedAsset = (assetId, variant = 'original', asSeed = false) => {
        router.post(route('harvested-assets.use', { asset: assetId }), {
            campaign_id: campaign.id,
            strategy_id: currentStrategy.id,
            variant,
            as_seed: asSeed,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setIsPolling(true);
                toast.success(asSeed
                    ? 'Added as AI seed — generated creatives will use it as reference.'
                    : 'Asset added to collateral.');
            },
        });
    };

    const handleDeploy = async () => {
        if (['pending', 'reviewing', 'revising'].includes(collateral.creativeReview?.status)) {
            toast.info('Your creative is still being prepared and checked. Review the finished set before creating your ads.');
            return;
        }
        // Check subscription first
        if (!hasActiveSubscription) {
            setShowSubscriptionModal(true);
            return;
        }

        // Check if deployment is enabled
        if (!deploymentEnabled) {
            setShowDeploymentDisabledModal(true);
            return;
        }

        // Budget confirmation BEFORE money: the deploy endpoint refuses
        // unconfirmed auto-generated campaigns, so charging the prepay first
        // took the user's money for a deploy that was then rejected.
        if (campaign?.auto_generated_at && !campaign?.budget_confirmed_at) {
            toast.warning('Please confirm your daily budget first — taking you there now.');
            router.visit(route('campaigns.show', campaign.uuid));
            return;
        }

        // Payment problems block deploying regardless of balance. 'paused'
        // lives on payment_status; the old check compared it against status,
        // a field that never holds that value, so this warning never fired.
        if (managedBillingEnabled && adSpendCredit?.payment_status === 'paused') {
            toast.warning('Your ad spend billing is paused due to a payment issue. Please update your payment method in Billing → Ad Spend before deploying.');
            return;
        }

        // Check if ad spend billing is set up and has enough balance for this campaign
        if (managedBillingEnabled) {
            const totalBudget = Number(campaign?.total_budget || 0);
            const startDate = campaign?.start_date ? new Date(campaign.start_date) : null;
            const endDate = campaign?.end_date ? new Date(campaign.end_date) : null;
            const durationDays = (startDate && endDate)
                ? Math.max(1, Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24)) + 1)
                : 30;
            const dailyBudget = campaign?.daily_budget
                ? Number(campaign.daily_budget)
                : (totalBudget > 0 ? totalBudget / durationDays : 50);
            const daysToCharge = Math.min(7, durationDays);
            const requiredFunds = dailyBudget * daysToCharge;
            const currentBalance = adSpendCredit?.current_balance ?? 0;
            const needsFunding = !adSpendCredit || currentBalance < requiredFunds;

            if (needsFunding) {
                setShowAdSpendSetupModal(true);
                return;
            }
        }

        setConfirmModal({
            show: true,
            title: setupOnly ? 'Create your ads' : 'Deploy Collateral',
            message: setupOnly
                ? 'These go into your Google Ads account paused — nothing spends until you switch them on.'
                : 'Are you sure you want to deploy the selected collateral?',
            onConfirm: () => confirmDeploy(),
            confirmText: setupOnly ? 'Create my ads' : 'Deploy',
            confirmButtonClass: 'bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800',
            isDestructive: false
        });
    };

    const handleAdSpendSetupSuccess = (result) => {
        setShowAdSpendSetupModal(false);
        const charged = Number(result.credit_amount) || 0;
        const balance = Number(result.new_balance) || 0;
        // Only say "payment successful" when a charge actually occurred; otherwise the
        // existing credit already covered the campaign and nothing was charged.
        const message = charged > 0
            ? `Charged ${money(charged, currency)}. Your ad spend credit is now ${money(balance, currency)}. Ready to deploy?`
            : `You already have ${money(balance, currency)} in ad spend credit — no additional charge needed. Ready to deploy?`;
        setConfirmModal({
            show: true,
            title: 'Deploy Collateral',
            message,
            onConfirm: () => confirmDeploy(),
            confirmText: 'Deploy Now',
            confirmButtonClass: 'bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800',
            isDestructive: false
        });
    };

    const confirmDeploy = () => {
        // On success the server redirects to the deployment-status page, whose
        // flash toast covers the messaging — no client toast needed here.
        router.post(route('deployment.deploy'), {
            campaign_id: campaign.id,
        }, {
            preserveScroll: true,
            onError: (errors) => {
                console.error('Deployment errors:', errors);
                toast.error(errors.message || 'Deployment failed. Please check the console for details.');
            },
        });
    };

    const handleDeployPlatform = (strategy) => {
        setDeployDropdownOpen(false);

        const review = strategy.id === currentStrategy.id ? collateral.creativeReview : strategy.creative_review;
        if (['pending', 'reviewing', 'revising'].includes(review?.status)) {
            toast.info('Your creative is still being prepared and checked. Review the finished set before creating your ads.');
            return;
        }

        if (!hasActiveSubscription) { setShowSubscriptionModal(true); return; }
        if (!deploymentEnabled) { setShowDeploymentDisabledModal(true); return; }

        setConfirmModal({
            show: true,
            title: `Deploy ${strategy.platform}`,
            message: `Deploy only the ${strategy.platform} strategy? This will create or update ads on that platform only.`,
            onConfirm: () => {
                router.post(route('deployment.deploy-platform'), {
                    campaign_id: campaign.id,
                    strategy_id: strategy.id,
                }, {
                    preserveScroll: true,
                    onSuccess: () => toast.success(`${strategy.platform} deployment initiated!`),
                    onError: (errors) => toast.error(errors.message || `${strategy.platform} deployment failed.`),
                });
            },
            confirmText: `Deploy ${strategy.platform}`,
            confirmButtonClass: 'bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800',
            isDestructive: false,
        });
    };

    // Close dropdown when clicking outside
    useEffect(() => {
        if (!deployDropdownOpen) return;
        const handler = (e) => {
            if (deployDropdownRef.current && !deployDropdownRef.current.contains(e.target)) {
                setDeployDropdownOpen(false);
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [deployDropdownOpen]);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col gap-4 sm:flex-row sm:justify-between sm:items-center">
                    {/* currentStrategy.name is frequently empty, which rendered
                        "Collateral for Spring Lead Gen -" with a trailing dash
                        and nothing after it. Fall back to the platform, which
                        is what the reader actually wants to know. */}
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Review your ads — {campaign.name}
                        {(currentStrategy.name || currentStrategy.platform)
                            ? ` — ${currentStrategy.name || currentStrategy.platform}`
                            : ''}
                    </h2>
                    <div className="relative" ref={deployDropdownRef}>
                        <div className="flex">
                            <button
                                onClick={handleDeploy}
                                className="px-4 py-2 bg-brand-dark text-white rounded-l-lg hover:bg-brand-darker transition font-medium"
                            >
                                {/* Deploy is our word for it. To someone who paid us
                                    once to build an account they will run themselves,
                                    the button creates the ads — it does not start
                                    them. */}
                                {setupOnly ? (['deployed', 'verified'].includes(currentStrategy.deployment_status) ? 'Update paused ads' : 'Create paused ads') : (['deployed', 'verified'].includes(currentStrategy.deployment_status) ? 'Update deployed ads' : 'Deploy All')}
                            </button>
                            <button
                                onClick={() => setDeployDropdownOpen(o => !o)}
                                className="px-2 py-2 bg-brand-dark text-white rounded-r-lg hover:bg-brand-darker transition border-l border-white/30"
                                aria-label={setupOnly ? 'Create ads for one platform' : 'Deploy individual platform'}
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                        </div>
                        {deployDropdownOpen && (
                            <div className="absolute right-0 mt-1 w-56 bg-white border border-gray-200 rounded-lg shadow-lg z-50">
                                <div className="px-3 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider border-b border-gray-100">
                                    {setupOnly ? 'Create for one platform' : 'Deploy single platform'}
                                </div>
                                {allStrategies.map(strategy => (
                                    <button
                                        key={strategy.id}
                                        onClick={() => handleDeployPlatform(strategy)}
                                        className="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"
                                    >
                                        <span className="w-2 h-2 rounded-full bg-green-500 flex-shrink-0" />
                                        {strategy.platform}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            }
        >
            <Head title="Review your ads" />

            <SubscriptionRequiredModal 
                show={showSubscriptionModal} 
                onClose={() => setShowSubscriptionModal(false)} 
            />

            <DeploymentDisabledModal 
                show={showDeploymentDisabledModal} 
                onClose={() => setShowDeploymentDisabledModal(false)} 
            />

            <AdSpendSetupModal
                show={showAdSpendSetupModal}
                onClose={() => setShowAdSpendSetupModal(false)}
                onSuccess={handleAdSpendSetupSuccess}
                campaign={campaign}
                campaignName={campaign.name}
                existingCredit={adSpendCredit}
                hasPaymentMethod={hasPaymentMethod}
            />

            <ConfirmationModal
                show={confirmModal.show}
                onClose={() => setConfirmModal({ ...confirmModal, show: false })}
                onConfirm={confirmModal.onConfirm}
                title={confirmModal.title}
                message={confirmModal.message}
                confirmText={confirmModal.confirmText}
                cancelText={confirmModal.cancelText}
                confirmButtonClass={confirmModal.confirmButtonClass}
                isDestructive={confirmModal.isDestructive}
            />

            <div className="py-12">
                <div className="max-w-7xl mx-auto">

                    {setupOnly && <SetupStages stage={2} />}
                    {['deploying', 'deployed', 'verified'].includes(currentStrategy.deployment_status) && (
                        <div role="status" className={`mb-6 rounded-lg border p-5 ${currentStrategy.deployment_status === 'deploying' ? 'border-blue-200 bg-blue-50 text-blue-900' : 'border-green-200 bg-green-50 text-green-900'}`}>
                            <h2 className="font-semibold">{currentStrategy.deployment_status === 'deploying'
                                ? 'Your ads are being created'
                                : (setupOnly ? 'Your paused ads have been created' : 'Your campaign has been deployed')}</h2>
                            <p className="mt-1 text-sm">{currentStrategy.deployment_status === 'verified'
                                ? 'Campaign creation has been verified on the platform.'
                                : 'Open deployment status for the latest progress and verification result.'}</p>
                            <Link href={route('campaigns.deployment-status', { campaign: campaign.uuid || campaign.id })} className="mt-3 inline-block font-semibold underline">View deployment status →</Link>
                        </div>
                    )}
                    <section className="mb-8 grid gap-6 rounded-xl border border-gray-200 bg-white p-6 lg:grid-cols-2">
                        <div>
                            <h1 className="text-2xl font-semibold text-gray-900">Your ad, as a customer could see it</h1>
                            <p className="mt-2 mb-5 text-sm text-gray-600">Review the message and destination, then approve the assets below.{setupOnly && ' Creating the ads leaves the campaign paused.'}</p>
                            {collateral.adCopy ? <AdPreviewPanel adCopy={collateral.adCopy} images={collateral.imageCollaterals} platform={currentStrategy.platform} campaignType={currentStrategy.campaign_type} brandName={reviewSummary.business_name} websiteUrl={reviewSummary.destination || campaign.landing_page_url || ''} />
                                : <p role="status" className="rounded-lg bg-gray-50 p-5 text-gray-600">{isPolling ? 'Your ad copy is being prepared. The preview will appear here.' : 'Ad copy is not available yet. Use Generate ad copy below to retry.'}</p>}
                        </div>
                        <div className="lg:border-l lg:border-gray-100 lg:pl-6">
                            <h2 className="font-semibold text-gray-900">Campaign details</h2>
                            <dl className="mt-4 space-y-4 text-sm">
                                <div><dt className="text-gray-500">Daily budget</dt><dd className="mt-1 font-medium text-gray-900">{money(reviewSummary.daily_budget ?? campaign.daily_budget, reviewSummary.currency || currency)} per day</dd></div>
                                <div><dt className="text-gray-500">Audience</dt><dd className="mt-1 text-gray-900">{reviewSummary.audience || campaign.target_market || 'See campaign targeting'}</dd></div>
                                <div><dt className="text-gray-500">Destination</dt><dd className="mt-1 break-all text-gray-900">{reviewSummary.destination || campaign.landing_page_url}</dd></div>
                                <div><dt className="text-gray-500">Placement</dt><dd className="mt-1 text-gray-900">{currentStrategy.platform} · {currentStrategy.campaign_type}</dd></div>
                            </dl>
                            <Link href={route('campaigns.show', { campaign: campaign.uuid })} className="mt-5 inline-block text-sm text-brand-dark underline">Review campaign details and creative direction</Link>
                            {setupOnly && ['deployed', 'verified'].includes(currentStrategy.deployment_status) && <Link href={route('dashboard')} className="mt-4 block text-sm font-semibold text-brand-dark underline">View your handover checklist</Link>}
                        </div>
                    </section>
                    {collateral.creativeReview && <section role="status" className="mb-6 rounded-xl border border-gray-200 bg-white p-5">
                        <h2 className="font-semibold text-gray-900">{({ pending: 'Preparing your creative set', reviewing: 'Reviewing the images together', revising: 'Refining selected creative', passed: 'Visual review complete', needs_review: 'Creative needs your review' })[collateral.creativeReview.status] || 'Creative review'}</h2>
                        <p className="mt-2 text-sm text-gray-600">{collateral.creativeReview.message || 'Checking the selling idea, visual variety, legibility and placement. Each concept can receive one automatic correction.'}</p>
                        {collateral.creativeReview.results?.filter(result => !result.passed).map(result => <p key={result.slot} className="mt-2 text-sm text-amber-800">Concept {result.slot + 1}: {result.feedback}</p>)}
                    </section>}
                    {/* Runtime collateral generation failure banner */}
                    {collateralError && (
                        <div className="mb-4 bg-red-50 border border-red-200 rounded-lg p-4">
                            <div className="flex items-start gap-3">
                                <svg className="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                </svg>
                                <div>
                                    <p className="text-sm font-semibold text-red-800 mb-1">Collateral generation failed</p>
                                    <p className="text-sm text-red-700">{collateralError}</p>
                                    <p className="text-xs text-red-600 mt-1">Use the Generate buttons below to retry individual assets.</p>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Collateral generation error banner */}
                    {Object.keys(collateralErrors).length > 0 && (
                        <div className="mb-4 bg-red-50 border border-red-200 rounded-lg p-4">
                            <div className="flex items-start gap-3">
                                <svg className="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                </svg>
                                <div>
                                    <p className="text-sm font-semibold text-red-800 mb-1">Some collateral failed to generate:</p>
                                    <ul className="space-y-1">
                                        {Object.entries(collateralErrors).map(([type, message]) => (
                                            <li key={type} className="text-sm text-red-700">
                                                <span className="font-medium capitalize">{type}:</span> {message}
                                            </li>
                                        ))}
                                    </ul>
                                    <p className="text-xs text-red-500 mt-2">Use the generate buttons below to retry.</p>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4 sm:p-6">
                        {/* Tab Navigation */}
                        <div className="border-b border-gray-200">
                            <nav className="-mb-px flex gap-2 sm:gap-6 overflow-x-auto" aria-label="Tabs">
                                {allStrategies.map((strategyItem) => {
                                    const totalCollateral = strategyItem.ad_copies_count + strategyItem.image_collaterals_count + strategyItem.video_collaterals_count;
                                    return (
                                        <Link
                                            key={strategyItem.id}
                                            href={route('campaigns.collateral.show', { campaign: campaign.uuid, strategy: strategyItem.uuid })}
                                            onClick={() => handleTabChange(strategyItem.platform)}
                                            className={`
                                                ${activeTab === strategyItem.platform
                                                    ? 'border-blue-500 text-blue-600'
                                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                                }
                                                whitespace-nowrap py-2 px-1 sm:py-4 border-b-2 font-medium text-xs sm:text-sm transition-colors duration-200 flex items-center
                                            `}
                                        >
                                            {strategyItem.platform}
                                            {totalCollateral > 0 && (
                                                <span className={`ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${activeTab === strategyItem.platform ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'}`}>
                                                    {totalCollateral}
                                                </span>
                                            )}
                                        </Link>
                                    );
                                })}
                            </nav>
                        </div>

                        {/* Deployment selection summary */}
                        {(() => {
                            const concepts = groupConcepts(collateral.imageCollaterals);
                            const selImages = concepts.filter(concept => concept.deployed).length;
                            const totalImages = concepts.length;
                            const selVideos = collateral.videoCollaterals?.filter(v => v.should_deploy).length ?? 0;
                            const totalVideos = collateral.videoCollaterals?.length ?? 0;
                            const selAdCopy = collateral.adCopy?.should_deploy ? 1 : 0;
                            const totalSelected = selImages + selVideos + selAdCopy;
                            const hasAny = totalImages > 0 || totalVideos > 0 || collateral.adCopy;
                            if (!hasAny) return null;
                            return (
                                <div className={`mt-4 rounded-lg border-2 px-4 py-3 flex flex-wrap items-center justify-between gap-3 ${totalSelected > 0 ? 'bg-green-50 border-green-300' : 'bg-amber-50 border-amber-300'}`}>
                                    <div className="flex items-center gap-2">
                                        <span className={`text-lg font-bold ${totalSelected > 0 ? 'text-green-700' : 'text-amber-700'}`}>
                                            {totalSelected > 0 ? `${totalSelected} item${totalSelected !== 1 ? 's' : ''} selected` : 'No items selected'}
                                        </span>
                                        <span className="text-sm text-gray-500">
                                            {[
                                                selAdCopy ? `${selAdCopy} ad copy` : null,
                                                totalImages ? `${selImages}/${totalImages} image concepts` : null,
                                                totalVideos ? `${selVideos}/${totalVideos} videos` : null,
                                            ].filter(Boolean).join(' · ')}
                                        </span>
                                    </div>
                                    <span className="text-sm text-gray-600 font-medium">
                                        👆 Click any asset below to select or deselect it
                                    </span>
                                </div>
                            );
                        })()}

                        {/* Tab Content */}
                        <div className="mt-6">
                            {allStrategies.map((strategyItem) => (
                                activeTab === strategyItem.platform && (
                                    <div key={strategyItem.id}>
                                        <h3 className="text-lg font-semibold text-gray-900 mb-4">{strategyItem.platform} Ad Copy</h3>
                                        <p>Generate dynamic ad copy for {strategyItem.platform} based on the strategy.</p>
                                        
                                        <button
                                            onClick={() => handleGenerateAdCopy(strategyItem.uuid, strategyItem.platform)}
                                            disabled={generatingAdCopy}
                                            className="mt-4 px-4 py-2 bg-white text-gray-800 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center gap-2"
                                        >
                                            {generatingAdCopy && (
                                                <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            )}
                                            {generatingAdCopy ? 'Generating Ad Copy...' : 'Generate Ad Copy'}
                                        </button>

                                        {/* Display generated ad copy here */}
                                        {collateral.adCopy && collateral.adCopy.strategy_id === strategyItem.id && collateral.adCopy.platform === strategyItem.platform && (
                                            <>
                                                <p className="mt-4 flex items-center gap-2 text-sm font-medium bg-blue-50 border border-blue-200 rounded-lg px-3 py-2 text-blue-800">
                                                    <span>☑️</span>
                                                    <span>Check the box to include this ad copy in deployment. Unchecked ad copy will not go live.</span>
                                                </p>
                                                <div
                                                    className={`mt-3 p-4 rounded-lg border-2 ${collateral.adCopy.should_deploy ? 'border-green-500 bg-green-50' : 'border-gray-200 bg-gray-50'} cursor-pointer relative focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2`}
                                                    {...approvalToggle({
                                                        checked: Boolean(collateral.adCopy.should_deploy),
                                                        onToggle: () => handleToggleCollateral('ad_copy', collateral.adCopy.id),
                                                        label: 'Include this ad copy in deployment',
                                                    })}
                                                >
                                                    {/* Checkbox */}
                                                    <div className={`absolute top-3 left-3 w-6 h-6 rounded flex items-center justify-center shadow-md border-2 ${collateral.adCopy.should_deploy ? 'bg-green-500 border-green-500' : 'bg-white border-gray-300'}`}>
                                                        {collateral.adCopy.should_deploy && (
                                                            <svg className="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" /></svg>
                                                        )}
                                                    </div>
                                                    <div className="flex justify-between items-start mb-3 mt-5">
                                                        <h4 className="text-md font-semibold text-gray-800">Generated Ad Copy:</h4>
                                                        <div className="flex gap-2">
                                                            <button
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    setShowPreview(!showPreview);
                                                                }}
                                                                className="px-3 py-1 text-xs font-medium text-purple-600 bg-purple-50 rounded-md hover:bg-purple-100"
                                                            >
                                                                {showPreview ? '📝 Show List' : '👁️ Preview Ad'}
                                                            </button>
                                                            {isSubscribed ? (
                                                                <button
                                                                    onClick={(e) => {
                                                                        e.stopPropagation();
                                                                        const copyText = `Headlines:\n${collateral.adCopy.headlines.join('\n')}\n\nDescriptions:\n${collateral.adCopy.descriptions.join('\n')}`;
                                                                        navigator.clipboard.writeText(copyText);
                                                                        toast.success('Ad copy copied to clipboard!');
                                                                    }}
                                                                    className="px-3 py-1 text-xs font-medium text-brand-dark bg-brand-tint-10 rounded-md hover:bg-brand-tint-20"
                                                                >
                                                                    📋 Copy All
                                                                </button>
                                                            ) : (
                                                                <UnlockAction
                                                                    setupOnly={setupOnly}
                                                                    label="Export"
                                                                    className="px-3 py-1 text-xs font-medium text-white bg-brand-dark rounded-md hover:bg-brand-darker"
                                                                />
                                                            )}
                                                        </div>
                                                    </div>
                                                    
                                                    {!showPreview ? (
                                                        <>
                                                            <div className="mb-4">
                                                                <h5 className="font-medium text-gray-700">Headlines:</h5>
                                                                <ul className="list-disc list-inside text-gray-600">
                                                                    {collateral.adCopy.headlines.map((headline, index) => (
                                                                        <li key={index}>{headline}</li>
                                                                    ))}
                                                                </ul>
                                                            </div>
                                                            <div>
                                                                <h5 className="font-medium text-gray-700">Descriptions:</h5>
                                                                <ul className="list-disc list-inside text-gray-600">
                                                                    {collateral.adCopy.descriptions.map((description, index) => (
                                                                        <li key={index}>{description}</li>
                                                                    ))}
                                                                </ul>
                                                            </div>
                                                        </>
                                                    ) : (
                                                        <div onClick={(e) => e.stopPropagation()}>
                                                            <AdPreviewPanel
                                                                platform={strategyItem.platform.toLowerCase()}
                                                                campaignType={strategyItem.campaign_type}
                                                                adCopy={collateral.adCopy}
                                                                brandName={reviewSummary.business_name}
                                                                websiteUrl={reviewSummary.destination || campaign.landing_page_url || ''}
                                                                images={collateral.imageCollaterals}
                                                            />
                                                        </div>
                                                    )}
                                                </div>
                                            </>
                                        )}

                                        <hr className="my-8" />

                                        <h3 className="text-lg font-semibold text-gray-900 mb-4">{currentStrategy.campaign_type === 'search' ? 'Supporting Search images' : 'Image concepts'}</h3>
                                        <p>Generate a unique image based on the imagery strategy for {strategyItem.platform}, or upload your own.</p>
                                        {strategyItem.campaign_type === 'search' && /google/i.test(strategyItem.platform) && (
                                            <p className="mt-3 rounded-lg bg-blue-50 p-3 text-sm text-blue-900">
                                                Search images are saved to your Google Ads asset library. Add them to your ads in Google Ads once your account is eligible for image assets. Your text ads can run without them.
                                            </p>
                                        )}

                                        {/* Quota indicator */}
                                        {creativeUsage && !creativeUsage.is_unlimited && (
                                            <div className="mt-2 flex items-center gap-2 text-sm">
                                                <span className={`font-medium ${creativeUsage.image_generations.remaining <= 0 ? 'text-red-600' : creativeUsage.image_generations.remaining <= 5 ? 'text-yellow-600' : 'text-green-600'}`}>
                                                    {creativeUsage.image_generations.remaining} image generation{creativeUsage.image_generations.remaining !== 1 ? 's' : ''} remaining
                                                </span>
                                                {creativeUsage.image_generations.remaining <= 0 && (
                                                    <a href={route('creative-usage')} className="text-brand-dark hover:underline text-xs font-medium">Buy Boost →</a>
                                                )}
                                            </div>
                                        )}

                                        <div className="flex flex-wrap gap-3 mt-4">
                                            <button
                                                onClick={() => handleGenerateImage(strategyItem.uuid)}
                                                disabled={generatingImage || (creativeUsage && !creativeUsage.is_unlimited && creativeUsage.image_generations.remaining <= 0)}
                                                className="px-4 py-2 bg-white text-gray-800 border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center gap-2"
                                            >
                                                {generatingImage && (
                                                    <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                )}
                                                {generatingImage ? 'Generating...' : '✨ Generate Image'}
                                            </button>

                                            <label className={`px-4 py-2 border-2 border-dashed border-gray-300 text-gray-600 rounded-lg hover:border-brand-dark hover:text-brand-darker cursor-pointer transition flex items-center gap-2 ${uploadingImages ? 'opacity-50 pointer-events-none' : ''}`}>
                                                {uploadingImages ? (
                                                    <>
                                                        <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                        </svg>
                                                        Uploading...
                                                    </>
                                                ) : (
                                                    <>📁 Upload Images</>
                                                )}
                                                <input
                                                    type="file"
                                                    multiple
                                                    accept="image/jpeg,image/png,image/webp"
                                                    className="hidden"
                                                    onChange={(e) => handleImageUpload(strategyItem.uuid, e.target.files)}
                                                    disabled={uploadingImages}
                                                />
                                            </label>
                                            <span className="text-xs text-gray-500 self-center">
                                                JPG, PNG, or WebP · max 10MB each · {(() => {
                                                    const uploaded = collateral.imageCollaterals?.filter(i => i.source === 'uploaded').length || 0;
                                                    return `${uploaded}/10 uploaded`;
                                                })()}
                                            </span>

                                            {/* Harvested Assets Button */}
                                            {isSubscribed && (
                                                <button
                                                    onClick={() => {
                                                        setShowHarvestedPanel(!showHarvestedPanel);
                                                        if (!showHarvestedPanel && harvestedAssets.length === 0) loadHarvestedAssets();
                                                    }}
                                                    className="px-4 py-2 bg-green-50 text-green-700 border border-green-200 rounded-lg hover:bg-green-100 transition flex items-center gap-2 text-sm font-medium"
                                                >
                                                    🌐 Website Assets {harvestedAssetCount > 0 && <span className="bg-green-200 text-green-800 px-1.5 py-0.5 rounded-full text-xs">{harvestedAssetCount}</span>}
                                                </button>
                                            )}
                                        </div>

                                        {/* Harvested Assets Panel */}
                                        {showHarvestedPanel && isSubscribed && (
                                            <div className="mt-4 border border-green-200 rounded-lg p-4 bg-green-50/50">
                                                <div className="flex items-center justify-between mb-3">
                                                    <div>
                                                        <h4 className="text-sm font-semibold text-gray-900">Smart Asset Harvesting</h4>
                                                        <p className="text-xs text-gray-500 mt-0.5">Images scraped from your website, classified and resized for ads</p>
                                                    </div>
                                                    <button
                                                        onClick={handleHarvest}
                                                        disabled={harvestingInProgress}
                                                        className="text-xs px-3 py-1.5 bg-brand-dark text-white rounded-md hover:bg-brand-darker disabled:opacity-50 font-medium transition"
                                                    >
                                                        {harvestingInProgress ? 'Harvesting...' : harvestedAssetCount > 0 ? '🔄 Re-harvest' : '🌐 Harvest from Website'}
                                                    </button>
                                                </div>

                                                {loadingHarvested ? (
                                                    <div className="text-center py-6 text-gray-500 text-sm">Loading assets...</div>
                                                ) : harvestedAssets.length === 0 ? (
                                                    <div className="text-center py-6 text-gray-500 text-sm">
                                                        {harvestedAssetCount > 0
                                                            ? 'Loading your harvested assets...'
                                                            : 'No assets harvested yet. Click "Harvest from Website" to scan your site for usable images.'}
                                                    </div>
                                                ) : (
                                                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                                                        {harvestedAssets.map((asset) => (
                                                            <div key={asset.id} className="bg-white rounded-lg border border-gray-200 overflow-hidden group relative">
                                                                <img src={asset.cloudfront_url} alt={asset.description || 'Harvested asset'} className="w-full h-32 object-cover" />
                                                                <div className={`absolute top-1 left-1 px-1.5 py-0.5 rounded text-xs font-medium shadow ${
                                                                    asset.classification === 'product' ? 'bg-orange-100 text-orange-700' :
                                                                    asset.classification === 'lifestyle' ? 'bg-blue-100 text-blue-700' :
                                                                    'bg-gray-100 text-gray-600'
                                                                }`}>
                                                                    {asset.classification}
                                                                </div>
                                                                {asset.status === 'processed' && (
                                                                    <div className="absolute top-1 right-1 px-1.5 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700 shadow">
                                                                        ✓ Ready
                                                                    </div>
                                                                )}
                                                                <div className="p-2">
                                                                    <p className="text-xs text-gray-500 truncate">{asset.width}×{asset.height}</p>
                                                                    <div className="flex flex-wrap gap-1 mt-1.5">
                                                                        <button
                                                                            onClick={() => handleUseHarvestedAsset(asset.id, 'original')}
                                                                            className="text-xs px-2 py-0.5 bg-brand-tint-10 text-brand-darker rounded hover:bg-brand-tint-20 font-medium"
                                                                        >
                                                                            Use Original
                                                                        </button>
                                                                        <button
                                                                            onClick={() => handleUseHarvestedAsset(asset.id, 'original', true)}
                                                                            title="Guide AI-generated creatives with this image instead of running it as an ad"
                                                                            className="text-xs px-2 py-0.5 bg-purple-50 text-purple-700 rounded hover:bg-purple-100 font-medium"
                                                                        >
                                                                            ✦ AI Seed
                                                                        </button>
                                                                        {asset.bg_removed_url ? (
                                                                            <button
                                                                                onClick={() => handleUseHarvestedAsset(asset.id, 'bg_removed')}
                                                                                className="text-xs px-2 py-0.5 bg-purple-50 text-purple-700 rounded hover:bg-purple-100 font-medium"
                                                                            >
                                                                                No BG
                                                                            </button>
                                                                        ) : asset.classification === 'product' && (
                                                                            <button
                                                                                onClick={() => handleUseHarvestedAsset(asset.id, 'bg_removed')}
                                                                                className="text-xs px-2 py-0.5 bg-purple-50/50 text-purple-500 rounded hover:bg-purple-100 font-medium border border-dashed border-purple-200"
                                                                            >
                                                                                ✂ Remove BG
                                                                            </button>
                                                                        )}
                                                                        {asset.variants?.landscape ? (
                                                                            <button
                                                                                onClick={() => handleUseHarvestedAsset(asset.id, 'landscape')}
                                                                                className="text-xs px-2 py-0.5 bg-gray-100 text-gray-600 rounded hover:bg-gray-200 font-medium"
                                                                            >
                                                                                16:9
                                                                            </button>
                                                                        ) : (
                                                                            <button
                                                                                onClick={() => handleUseHarvestedAsset(asset.id, 'landscape')}
                                                                                className="text-xs px-2 py-0.5 bg-gray-50 text-gray-500 rounded hover:bg-gray-100 font-medium border border-dashed border-gray-200"
                                                                            >
                                                                                ✦ 16:9
                                                                            </button>
                                                                        )}
                                                                        {asset.variants?.square ? (
                                                                            <button
                                                                                onClick={() => handleUseHarvestedAsset(asset.id, 'square')}
                                                                                className="text-xs px-2 py-0.5 bg-gray-100 text-gray-600 rounded hover:bg-gray-200 font-medium"
                                                                            >
                                                                                1:1
                                                                            </button>
                                                                        ) : (
                                                                            <button
                                                                                onClick={() => handleUseHarvestedAsset(asset.id, 'square')}
                                                                                className="text-xs px-2 py-0.5 bg-gray-50 text-gray-500 rounded hover:bg-gray-100 font-medium border border-dashed border-gray-200"
                                                                            >
                                                                                ✦ 1:1
                                                                            </button>
                                                                        )}
                                                                        {asset.variants?.vertical ? (
                                                                            <button
                                                                                onClick={() => handleUseHarvestedAsset(asset.id, 'vertical')}
                                                                                className="text-xs px-2 py-0.5 bg-gray-100 text-gray-600 rounded hover:bg-gray-200 font-medium"
                                                                            >
                                                                                9:16
                                                                            </button>
                                                                        ) : (
                                                                            <button
                                                                                onClick={() => handleUseHarvestedAsset(asset.id, 'vertical')}
                                                                                className="text-xs px-2 py-0.5 bg-gray-50 text-gray-500 rounded hover:bg-gray-100 font-medium border border-dashed border-gray-200"
                                                                            >
                                                                                ✦ 9:16
                                                                            </button>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        )}

                                        {/* Display generated + uploaded images */}
                                        {collateral.imageCollaterals && collateral.imageCollaterals.length > 0 && (
                                            <>
                                                <p className="mt-4 flex items-center gap-2 text-sm font-medium bg-blue-50 border border-blue-200 rounded-lg px-3 py-2 text-blue-800">
                                                    <span>☑️</span>
                                                    <span>{currentStrategy.campaign_type === 'search' ? 'Select images to save to your Google Ads asset library. Attach eligible images to your Search ads in Google Ads.' : 'Select the image concepts you want included in your ads.'}</span>
                                                </p>
                                                {/* items-start, or the row stretches every card to the
                                                    tallest in it. A 1200x628 next to a 1024x1024 then grows
                                                    a white band under the short one — the picture is whole,
                                                    the card around it is not. */}
                                                <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 items-start">
                                                {groupConcepts(collateral.imageCollaterals).map((concept) => {
                                                    // One card is one photograph. Its other ad sizes are the
                                                    // same picture and ride along with it.
                                                    const image = concept.cover;

                                                    return (
                                                    /*
                                                       The artwork carries nothing on top of it.

                                                       The approval checkbox sat at top-left and the
                                                       size pill at top-right, and the headline is
                                                       composited across the upper band of the
                                                       picture — so both corners covered the words.
                                                       On one creative the checkbox hid the B of
                                                       "Build an Online Store with AI", which reads
                                                       as a cropped headline rather than as a
                                                       control in the way.

                                                       The controls live under the picture now. The
                                                       whole card is still the approve target, so
                                                       clicking the image works as it always did.
                                                    */
                                                    <div
                                                        key={concept.key}
                                                        className={`border-2 ${concept.deployed ? 'border-green-500' : 'border-gray-200'} rounded-lg overflow-hidden shadow-md group cursor-pointer focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2`}
                                                        {...approvalToggle({
                                                            checked: concept.deployed,
                                                            onToggle: () => handleToggleConcept(concept),
                                                            label: 'Include this image in deployment',
                                                        })}
                                                    >
                                                    <div className="relative">
                                                        {/* block: an inline <img> sits on the text baseline and
                                                            leaves a few pixels of background beneath it, which is
                                                            the thin white strip under every card. */}
                                                        <img src={image.cloudfront_url} alt={`Collateral for ${strategyItem.platform}`} className="block w-full h-auto" />
                                                        {/* Format + source badges */}
                                                        <div className="absolute top-2 right-2 flex flex-col items-end gap-1">
                                                            <button
                                                                onClick={(e) => { e.stopPropagation(); handleToggleConcept(concept, 'is_seed'); }}
                                                                title={image.is_seed
                                                                    ? 'This image guides the AI as visual reference. Click to stop using it as a seed.'
                                                                    : 'Use this image as visual reference for AI-generated creatives.'}
                                                                className={`px-2 py-0.5 rounded-full text-xs font-medium shadow ${image.is_seed
                                                                    ? 'bg-purple-600 text-white'
                                                                    : 'bg-white/90 text-gray-600 opacity-0 group-hover:opacity-100 transition-opacity'}`}
                                                            >
                                                                {image.is_seed ? '✦ AI Seed' : '✦ Use as AI seed'}
                                                            </button>
                                                            {image.source !== 'uploaded' && (image.refinement_depth ?? 0) > 0 && creativeUsage && (
                                                                <span className="px-2 py-0.5 rounded-full text-xs font-medium shadow bg-amber-100 text-amber-700">
                                                                    {image.refinement_depth}/{creativeUsage.max_refinements_per_item} edits
                                                                </span>
                                                            )}
                                                        </div>
                                                        {!isSubscribed && (
                                                            <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-3">
                                                                <p className="text-white text-xs font-medium">{setupOnly ? 'Preview — unlocks when your US$999 is paid' : 'Preview - Upgrade to download'}</p>
                                                            </div>
                                                        )}
                                                        <div className="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                                            {isSubscribed ? (
                                                                <div className="flex gap-2 flex-wrap justify-center px-2">
                                                                    {image.source !== 'uploaded' && (() => {
                                                                        /*
                                                                           "Max Edits" was told to people who had never
                                                                           edited anything.

                                                                           The label flipped whenever depth >=
                                                                           max_refinements_per_item, and that allowance is
                                                                           zero on the free plan — so 0 >= 0 was true on a
                                                                           freshly generated image and every free account
                                                                           was informed it had exhausted a limit it never
                                                                           had. Running out of edits and never having any
                                                                           are different facts and deserve different words:
                                                                           one is a limit reached, the other is an upsell.
                                                                        */
                                                                        const allowance = creativeUsage?.max_refinements_per_item ?? null;
                                                                        const used = image.refinement_depth ?? 0;
                                                                        const notOnPlan = allowance === 0;
                                                                        const exhausted = allowance !== null && allowance > 0 && used >= allowance;

                                                                        if (notOnPlan) {
                                                                            return (
                                                                                <UnlockAction
                                                                                    setupOnly={setupOnly}
                                                                                    label="Edit"
                                                                                    className="px-3 py-1.5 text-xs font-medium text-white bg-brand-dark rounded-md hover:bg-brand-darker"
                                                                                />
                                                                            );
                                                                        }

                                                                        return (
                                                                            <button
                                                                                onClick={(e) => { e.stopPropagation(); setEditingImage(image); }}
                                                                                disabled={exhausted}
                                                                                className="px-3 py-1.5 text-xs font-medium text-white bg-brand-dark rounded-md hover:bg-brand-darker disabled:opacity-50 disabled:cursor-not-allowed"
                                                                                title={exhausted ? `This image has had all ${allowance} of its edits` : 'Edit this image'}
                                                                            >
                                                                                {exhausted ? `No edits left (${used}/${allowance})` : 'Edit'}
                                                                            </button>
                                                                        );
                                                                    })()}
                                                                    <a
                                                                        href={image.cloudfront_url}
                                                                        download
                                                                        onClick={(e) => e.stopPropagation()}
                                                                        className="px-3 py-1.5 text-xs font-medium text-white bg-brand-dark rounded-md hover:bg-brand-darker"
                                                                    >
                                                                        Download
                                                                    </a>
                                                                    <button
                                                                        onClick={(e) => { e.stopPropagation(); handleDeleteCollateral('image', image.id); }}
                                                                        className="px-3 py-1.5 text-xs font-medium text-white bg-red-600 rounded-md hover:bg-red-700"
                                                                    >
                                                                        Delete
                                                                    </button>
                                                                </div>
                                                            ) : (
                                                                <UnlockAction
                                                                    setupOnly={setupOnly}
                                                                    label="Download"
                                                                    className="px-4 py-2 text-sm font-medium text-white bg-brand-dark rounded-md hover:bg-brand-darker"
                                                                />
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Under the artwork, where nothing can obscure it. */}
                                                    <div className="flex items-center justify-between gap-2 border-t border-gray-100 bg-white px-3 py-2">
                                                        <span className="flex items-center gap-2 text-sm text-gray-700">
                                                            <span
                                                                className={`flex h-5 w-5 items-center justify-center rounded border-2 ${concept.deployed ? 'border-green-500 bg-green-500' : 'border-gray-300 bg-white'}`}
                                                                aria-hidden="true"
                                                            >
                                                                {concept.deployed && (
                                                                    <svg className="h-3.5 w-3.5 text-white" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" /></svg>
                                                                )}
                                                            </span>
                                                            {concept.deployed ? (currentStrategy.campaign_type === 'search' ? 'Save to asset library' : 'Selected for ads') : 'Excluded'}
                                                        </span>

                                                        {concept.formats.length > 1 && (
                                                            /*
                                                               The pill opens them.

                                                               It named three sizes that existed and could
                                                               not be looked at — the card shows the square
                                                               only. Clicking the card is already how
                                                               approval works, so the label that makes the
                                                               promise is the thing that keeps it.
                                                            */
                                                            <button
                                                                onClick={(e) => { e.stopPropagation(); setViewingConcept(concept); }}
                                                                className="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 transition hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-brand-primary"
                                                                title={`View all sizes: ${concept.formats.map(f => FORMAT_LABELS[f] || f).join(' · ')}`}
                                                            >
                                                                {concept.formats.length} sizes
                                                            </button>
                                                        )}
                                                    </div>
                                                    </div>
                                                );
                                                })}

                                                {/*
                                                    A tile where the next picture will appear.

                                                    The set arrives one concept at a time over a
                                                    couple of minutes, and a grid that simply stops
                                                    growing looks finished. Standing a placeholder
                                                    in the next slot says the opposite: more is
                                                    coming, here, shortly — which is the difference
                                                    between waiting and wondering whether it broke.
                                                */}
                                                {(isPolling || generatingImage) && (
                                                    <div
                                                        className="border-2 border-dashed border-gray-300 rounded-lg flex flex-col items-center justify-center gap-3 aspect-square bg-gray-50"
                                                        aria-live="polite"
                                                    >
                                                        <svg className="w-8 h-8 text-brand-dark animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                        </svg>
                                                        <p className="text-sm font-medium text-gray-600">Preparing remaining creative</p>
                                                        <p className="text-xs text-gray-500">It appears here as soon as it is ready</p>
                                                    </div>
                                                )}
                                                </div>
                                            </>
                                        )}

                                        <hr className="my-8" />

                                        {supportsVideo && <>
                                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Video ads</h3>
                                        <p>Generate a unique video based on the video strategy for {strategyItem.platform}, or upload your own.</p>

                                        {/* Quota indicator */}
                                        {creativeUsage && !creativeUsage.is_unlimited && (
                                            <div className="mt-2 flex items-center gap-2 text-sm">
                                                <span className={`font-medium ${creativeUsage.video_generations.remaining <= 0 ? 'text-red-600' : creativeUsage.video_generations.remaining <= 3 ? 'text-yellow-600' : 'text-green-600'}`}>
                                                    {creativeUsage.video_generations.remaining} video generation{creativeUsage.video_generations.remaining !== 1 ? 's' : ''} remaining
                                                </span>
                                                {creativeUsage.video_generations.remaining <= 0 && (
                                                    <a href={route('creative-usage')} className="text-brand-dark hover:underline text-xs font-medium">Buy Boost →</a>
                                                )}
                                            </div>
                                        )}

                                        <div className="flex flex-wrap gap-3 mt-4">
                                            <button
                                                onClick={() => handleGenerateVideo(strategyItem.uuid, strategyItem.platform)}
                                                disabled={generatingVideo || (creativeUsage && !creativeUsage.is_unlimited && creativeUsage.video_generations.remaining <= 0)}
                                                className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center gap-2"
                                            >
                                                {generatingVideo && (
                                                    <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                )}
                                                {generatingVideo ? 'Generating...' : '✨ Generate Video'}
                                            </button>

                                            <label className={`px-4 py-2 border-2 border-dashed border-gray-300 text-gray-600 rounded-lg hover:border-purple-400 hover:text-purple-600 cursor-pointer transition flex items-center gap-2 ${uploadingVideo ? 'opacity-50 pointer-events-none' : ''}`}>
                                                {uploadingVideo ? (
                                                    <>
                                                        <svg className="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                        </svg>
                                                        Uploading...
                                                    </>
                                                ) : (
                                                    <>📁 Upload Video</>
                                                )}
                                                <input
                                                    type="file"
                                                    accept="video/mp4,video/quicktime,video/webm"
                                                    className="hidden"
                                                    onChange={(e) => handleVideoUpload(strategyItem.uuid, e.target.files?.[0])}
                                                    disabled={uploadingVideo}
                                                />
                                            </label>
                                            <span className="text-xs text-gray-500 self-center">
                                                MP4, MOV, or WebM · max 100MB · {(() => {
                                                    const uploaded = collateral.videoCollaterals?.filter(v => v.source === 'uploaded').length || 0;
                                                    return `${uploaded}/3 uploaded`;
                                                })()}
                                            </span>
                                        </div>

                                        {/* Display generated + uploaded videos */}
                                        {collateral.videoCollaterals && collateral.videoCollaterals.length > 0 && (
                                            <>
                                                <p className="mt-4 flex items-center gap-2 text-sm font-medium bg-blue-50 border border-blue-200 rounded-lg px-3 py-2 text-blue-800">
                                                    <span>☑️</span>
                                                    <span>Check the box on each video to include it in deployment. Unchecked videos will not go live.</span>
                                                </p>
                                                <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                                {collateral.videoCollaterals.map((video) => (
                                                    <div
                                                        key={video.id}
                                                        className="relative group"
                                                    >
                                                        <div
                                                            className={`border-2 ${video.status === 'completed' && video.should_deploy ? 'border-green-500' : 'border-gray-200'} rounded-lg overflow-hidden shadow-md ${video.status === 'completed' ? 'cursor-pointer focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2' : ''}`}
                                                            {...approvalToggle({
                                                                checked: Boolean(video.should_deploy),
                                                                onToggle: () => handleToggleCollateral('video', video.id),
                                                                label: 'Include this video in deployment',
                                                                enabled: video.status === 'completed',
                                                            })}
                                                        >
                                                            {/* Deploy checkbox — only completed videos can be deployed */}
                                                            {video.status === 'completed' && (
                                                                <div className={`absolute top-2 left-2 z-10 w-6 h-6 rounded flex items-center justify-center shadow-md border-2 ${video.should_deploy ? 'bg-green-500 border-green-500' : 'bg-white border-gray-300'}`}>
                                                                    {video.should_deploy && (
                                                                        <svg className="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20"><path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" /></svg>
                                                                    )}
                                                                </div>
                                                            )}
                                                            {video.status === 'completed' ? (
                                                                <video controls src={video.cloudfront_url} className="w-full h-auto"></video>
                                                            ) : video.status === 'failed' ? (
                                                                <div className="p-6 text-center bg-red-50">
                                                                    <svg className="mx-auto mb-2 w-6 h-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" /></svg>
                                                                    <p className="font-semibold text-red-700">Generation failed</p>
                                                                    <p className="text-sm text-red-500 mt-1">Delete this and generate again.</p>
                                                                </div>
                                                            ) : (
                                                                <div className="p-6 text-center bg-gray-100">
                                                                    <div className="mx-auto mb-2 w-6 h-6 border-2 border-gray-400 border-t-transparent rounded-full animate-spin"></div>
                                                                    <p className="font-semibold text-gray-700">Generating video…</p>
                                                                    <p className="text-sm text-gray-500">This can take a few minutes.</p>
                                                                </div>
                                                            )}
                                                        </div>

                                                        {/* Source badge */}
                                                        <div className={`absolute top-2 right-2 px-2 py-0.5 rounded-full text-xs font-medium shadow ${
                                                            video.source === 'uploaded' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'
                                                        }`}>
                                                            {video.source === 'uploaded' ? '📁 Uploaded' : '✨ AI'}
                                                        </div>
                                                        
                                                        {/* Extend Video Button - Only show for completed Veo videos (AI-generated) */}
                                                        {video.source !== 'uploaded' && video.status === 'completed' && video.gemini_video_uri && (video.refinement_depth ?? 0) < (creativeUsage?.max_extensions_per_video ?? 3) && (
                                                            <button
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    setExtendingVideo(video);
                                                                }}
                                                                className="absolute bottom-2 right-2 bg-purple-600 hover:bg-purple-700 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg flex items-center space-x-1 opacity-0 group-hover:opacity-100 transition-opacity"
                                                                title={`Extend video by 7 seconds (${(creativeUsage?.max_extensions_per_video ?? 3) - (video.refinement_depth ?? 0)} extensions remaining)`}
                                                            >
                                                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                                                </svg>
                                                                <span>Extend ({(video.refinement_depth ?? 0)}/{creativeUsage?.max_extensions_per_video ?? 3})</span>
                                                            </button>
                                                        )}

                                                        {/* Delete button — available for all videos */}
                                                        <button
                                                            onClick={(e) => { e.stopPropagation(); handleDeleteCollateral('video', video.id); }}
                                                            className="absolute bottom-2 left-2 bg-red-600 hover:bg-red-700 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg flex items-center space-x-1 opacity-0 group-hover:opacity-100 transition-opacity"
                                                        >
                                                            <span>Delete</span>
                                                        </button>

                                                        {/* Extension Count Badge */}
                                                        {(video.extension_count || 0) > 0 && (
                                                            <div className="absolute top-9 right-2 bg-blue-600 text-white text-xs px-2 py-1 rounded-full shadow-lg">
                                                                Extended {video.extension_count}x
                                                            </div>
                                                        )}
                                                    </div>
                                                ))}
                                                </div>
                                            </>
                                        )}
                                        </>}
                                    </div>
                                )
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            <CreativeSizesModal
                concept={viewingConcept}
                show={Boolean(viewingConcept)}
                onClose={() => setViewingConcept(null)}
            />

            {editingImage && (
                <RefineImageModal
                    image={editingImage}
                    onClose={() => setEditingImage(null)}
                    onRefinementStart={handleRefinementStart}
                />
            )}

            {extendingVideo && (
                <ExtendVideoModal
                    video={extendingVideo}
                    onClose={() => setExtendingVideo(null)}
                    onExtensionStart={() => {
                        setGeneratingVideo(true);
                        setIsPolling(true);
                    }}
                />
            )}

            <Modal show={showDeployModal} onClose={() => setShowDeployModal(false)}>
                <h3 className="text-lg font-bold">Deploying to {currentStrategy.platform}</h3>
                <ul className="mt-4 space-y-2">
                    <li className="flex items-center">
                        <svg className="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path></svg>
                        Deploying ad copy...
                    </li>
                    <li className="flex items-center">
                        <svg className="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path></svg>
                        Deploying images...
                    </li>
                    <li className="flex items-center">
                        <svg className="w-5 h-5 text-green-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7"></path></svg>
                        Deploying video...
                    </li>
                </ul>
            </Modal>
        </AuthenticatedLayout>
    );
}
