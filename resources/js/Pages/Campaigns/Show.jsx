import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link, router } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import PrimaryButton from '@/Components/PrimaryButton';
import CollateralGenerationModal from '@/Components/CollateralGenerationModal';
import ConfirmationModal from '@/Components/ConfirmationModal';
import CampaignCopilot from '@/Components/CampaignCopilot';

// Collateral Summary Card Component
const CollateralSummaryCard = ({ campaign }) => {
    const summary = campaign.collateral_summary || { ad_copies: 0, images: 0, videos: 0, total: 0 };
    const hasCollateral = summary.total > 0;
    const hasSignedOffStrategies = campaign.strategies?.some(s => s.signed_off_at);
    
    if (!hasSignedOffStrategies) return null;

    return (
        <div className="mb-8 bg-white rounded-lg shadow-md overflow-hidden">
            <div className="bg-gradient-to-r from-flame-orange-600 to-flame-orange-700 px-6 py-4">
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
                                    href={route('campaigns.collateral.show', { campaign: campaign.id, strategy: strategy.id })}
                                    className="inline-flex items-center px-4 py-2 bg-flame-orange-600 text-white rounded-lg hover:bg-flame-orange-700 transition text-sm"
                                >
                                    <span className="mr-2">{strategy.platform}</span>
                                    <span className="bg-flame-orange-500 px-2 py-0.5 rounded text-xs">
                                        {(strategy.ad_copies_count || 0) + (strategy.image_collaterals_count || 0) + (strategy.video_collaterals_count || 0)} items
                                    </span>
                                </Link>
                            ))}
                        </div>
                    </>
                ) : (
                    <div className="text-center py-6">
                        <div className="animate-pulse flex flex-col items-center">
                            <svg className="w-12 h-12 text-flame-orange-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <p className="text-gray-600 font-medium">Generating your collateral...</p>
                            <p className="text-sm text-gray-500 mt-1">This usually takes 1-2 minutes</p>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
};

// A reusable component for a single strategy card
const StrategyCard = ({ strategy, campaignId, onSignOff }) => {
    const [isEditing, setIsEditing] = useState(false);
    const { data, setData, put, processing } = useForm({
        ad_copy_strategy: strategy.ad_copy_strategy,
        imagery_strategy: strategy.imagery_strategy,
        video_strategy: strategy.video_strategy,
    });

    const handleUpdate = (e) => {
        e.preventDefault();
        put(route('strategies.update', strategy.id), {
            onSuccess: () => setIsEditing(false),
        });
    };

    const handleSignOff = () => {
        onSignOff(strategy);
    };

    const isSignedOff = !!strategy.signed_off_at;

    return (
        <div className={`p-6 rounded-lg shadow-md ${isSignedOff ? 'bg-gray-200' : 'bg-mint-cream'}`}>
            <div className="flex justify-between items-center mb-4">
                <h3 className="text-2xl font-bold text-delft-blue">{strategy.platform}</h3>
                {!isSignedOff && !isEditing && (
                    <button onClick={() => setIsEditing(true)} className="text-sm text-air-superiority-blue hover:underline">
                        Edit
                    </button>
                )}
            </div>

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
                    <div>
                        <label className="font-bold text-jet">Video Strategy</label>
                        <textarea value={data.video_strategy} onChange={e => setData('video_strategy', e.target.value)} className="w-full mt-1 border-gray-300 rounded-md shadow-sm" />
                    </div>
                    <div className="flex justify-end space-x-2">
                        <button type="button" onClick={() => setIsEditing(false)} className="text-sm text-gray-600">Cancel</button>
                        <PrimaryButton disabled={processing}>Save Changes</PrimaryButton>
                    </div>
                </form>
            ) : (
                <div className="space-y-4">
                    <div>
                        <h4 className="font-bold text-jet">Ad Copy Strategy</h4>
                        <p className="text-gray-700 whitespace-pre-wrap">{strategy.ad_copy_strategy}</p>
                    </div>
                    <div>
                        <h4 className="font-bold text-jet">Imagery Strategy</h4>
                        <p className="text-gray-700 whitespace-pre-wrap">{strategy.imagery_strategy}</p>
                    </div>
                    <div>
                        <h4 className="font-bold text-jet">Video Strategy</h4>
                        <p className="text-gray-700 whitespace-pre-wrap">{strategy.video_strategy}</p>
                    </div>
                </div>
            )}

            {isSignedOff ? (
                <div className="mt-6 space-y-3">
                    {/* Collateral counts */}
                    {(strategy.ad_copies_count > 0 || strategy.image_collaterals_count > 0 || strategy.video_collaterals_count > 0) && (
                        <div className="flex gap-2 justify-center text-sm">
                            {strategy.ad_copies_count > 0 && (
                                <span className="px-2 py-1 bg-blue-100 text-blue-700 rounded">{strategy.ad_copies_count} Ad Copies</span>
                            )}
                            {strategy.image_collaterals_count > 0 && (
                                <span className="px-2 py-1 bg-green-100 text-green-700 rounded">{strategy.image_collaterals_count} Images</span>
                            )}
                            {strategy.video_collaterals_count > 0 && (
                                <span className="px-2 py-1 bg-purple-100 text-purple-700 rounded">{strategy.video_collaterals_count} Videos</span>
                            )}
                        </div>
                    )}
                    <div className="p-2 text-center bg-green-100 text-green-800 rounded-lg">
                        Strategy Signed Off on {new Date(strategy.signed_off_at).toLocaleString()}
                        <Link
                            href={route('campaigns.collateral.show', { campaign: strategy.campaign_id, strategy: strategy.id })}
                            className="ml-4 px-3 py-1 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                        >
                            View Collateral
                        </Link>
                    </div>
                </div>
            ) : (
                <div className="mt-6">
                    <PrimaryButton onClick={handleSignOff} disabled={processing} className="w-full justify-center bg-naples-yellow text-jet hover:bg-yellow-400">
                        Sign Off Strategy
                    </PrimaryButton>
                </div>
            )}
        </div>
    );
};

// Strategy Generation Loading Experience
const GENERATION_STEPS = [
    { label: 'Analyzing your knowledge base', icon: '📚', duration: 15 },
    { label: 'Reviewing brand guidelines', icon: '🎨', duration: 25 },
    { label: 'Researching target audience', icon: '🎯', duration: 40 },
    { label: 'Evaluating platform opportunities', icon: '📊', duration: 60 },
    { label: 'Crafting ad copy strategies', icon: '✍️', duration: 80 },
    { label: 'Designing imagery & video approaches', icon: '🖼️', duration: 100 },
    { label: 'Optimizing budget allocation', icon: '💰', duration: 115 },
    { label: 'Finalizing your strategies', icon: '✨', duration: 130 },
];

const StrategyGenerationLoader = ({ elapsedSeconds, campaignName }) => {
    const currentStepIndex = GENERATION_STEPS.findIndex(s => elapsedSeconds < s.duration);
    const activeStep = currentStepIndex === -1 ? GENERATION_STEPS.length - 1 : currentStepIndex;
    
    const prevThreshold = activeStep > 0 ? GENERATION_STEPS[activeStep - 1].duration : 0;
    const nextThreshold = GENERATION_STEPS[activeStep].duration;
    const stepProgress = Math.min((elapsedSeconds - prevThreshold) / (nextThreshold - prevThreshold), 1);
    const overallProgress = Math.min(((activeStep + stepProgress) / GENERATION_STEPS.length) * 100, 95);

    const formatTime = (s) => {
        const m = Math.floor(s / 60);
        const sec = s % 60;
        return m > 0 ? `${m}m ${sec}s` : `${sec}s`;
    };

    return (
        <div className="mb-8">
            <div className="bg-white rounded-2xl shadow-lg overflow-hidden border border-gray-100">
                {/* Header */}
                <div className="bg-gradient-to-r from-flame-orange-600 via-flame-orange-700 to-purple-700 px-8 py-6">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-xl font-bold text-white">Building your strategy</h3>
                            <p className="text-flame-orange-200 text-sm mt-1">for {campaignName}</p>
                        </div>
                        <div className="text-right">
                            <div className="text-2xl font-mono font-bold text-white">{formatTime(elapsedSeconds)}</div>
                            <p className="text-flame-orange-200 text-xs">elapsed</p>
                        </div>
                    </div>
                    <div className="mt-4 h-2 bg-flame-orange-900/30 rounded-full overflow-hidden">
                        <div 
                            className="h-full bg-gradient-to-r from-flame-orange-300 to-white rounded-full transition-all duration-1000 ease-out"
                            style={{ width: `${overallProgress}%` }}
                        />
                    </div>
                </div>

                {/* Steps */}
                <div className="px-8 py-6">
                    <div className="space-y-3">
                        {GENERATION_STEPS.map((step, idx) => {
                            const isComplete = idx < activeStep;
                            const isActive = idx === activeStep;

                            return (
                                <div 
                                    key={idx} 
                                    className={`flex items-center gap-3 py-2 px-3 rounded-lg transition-all duration-500 ${
                                        isActive ? 'bg-flame-orange-50 border border-flame-orange-200' : 
                                        isComplete ? 'opacity-60' : 'opacity-40'
                                    }`}
                                >
                                    <div className="flex-shrink-0 w-8 h-8 flex items-center justify-center">
                                        {isComplete ? (
                                            <svg className="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                            </svg>
                                        ) : isActive ? (
                                            <div className="w-6 h-6 border-2 border-flame-orange-500 border-t-transparent rounded-full animate-spin" />
                                        ) : (
                                            <div className="w-5 h-5 rounded-full border-2 border-gray-300" />
                                        )}
                                    </div>
                                    <span className="text-lg">{step.icon}</span>
                                    <span className={`text-sm font-medium ${
                                        isActive ? 'text-flame-orange-700' : 
                                        isComplete ? 'text-gray-500' : 'text-gray-400'
                                    }`}>
                                        {step.label}
                                    </span>
                                </div>
                            );
                        })}
                    </div>
                </div>

                {/* Footer */}
                <div className="px-8 py-4 bg-gray-50 border-t flex items-center justify-between">
                    <p className="text-xs text-gray-500">
                        Our AI is analyzing your brand, audience, and market data to create tailored strategies.
                    </p>
                    <div className="flex items-center gap-1.5">
                        <span className="inline-block w-2 h-2 bg-green-400 rounded-full animate-pulse" />
                        <span className="text-xs text-gray-500 font-medium">Live</span>
                    </div>
                </div>
            </div>
        </div>
    );
};


export default function Show({ auth, campaign }) {
    const [campaigns, setCampaign] = useState(campaign);
    const [isPolling, setIsPolling] = useState(
        campaign.is_generating_strategies || 
        (campaign.strategies.length === 0 && campaign.strategy_generation_started_at)
    );
    const [showGenerationModal, setShowGenerationModal] = useState(false);
    const [pollingError, setPollingError] = useState(false);
    const [confirmModal, setConfirmModal] = useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
    const [elapsedSeconds, setElapsedSeconds] = useState(0);
    const [copilotOpen, setCopilotOpen] = useState(false);
    const { post, processing } = useForm();

    useEffect(() => {
        if (!isPolling) {
            console.log('Polling is disabled');
            return;
        }

        console.log('Starting polling for campaign:', campaigns.id);

        // Poll immediately on mount
        const pollForStrategies = async () => {
            console.log('Polling for strategies...');
            try {
                const apiResponse = await fetch(`/api/campaigns/${campaigns.id}`, {
                    credentials: 'include',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                console.log('Poll response status:', apiResponse.status);
                
                if (apiResponse.ok) {
                    const data = await apiResponse.json();
                    console.log('Poll data received, strategies count:', data.strategies?.length || 0);
                    console.log('Is generating:', data.is_generating_strategies);
                    console.log('Generation error:', data.strategy_generation_error);
                    
                    // Update campaign data
                    setCampaign(data);
                    
                    // Check if generation failed
                    if (data.strategy_generation_error) {
                        console.log('Strategy generation failed, stopping polling');
                        setIsPolling(false);
                        setPollingError(true);
                        return true;
                    }
                    
                    // Stop polling if strategies are available and generation is complete
                    if (data.strategies && data.strategies.length > 0 && !data.is_generating_strategies) {
                        console.log('Strategies loaded and generation complete, stopping polling');
                        setIsPolling(false);
                        return true; // Signal to stop polling
                    }
                    
                    // Continue polling if generation is still in progress
                    if (data.is_generating_strategies) {
                        console.log('Generation still in progress, continuing to poll');
                        return false;
                    }
                } else {
                    console.error('Poll response not OK:', apiResponse.statusText);
                }
            } catch (error) {
                console.error('Error polling for strategies:', error);
            }
            return false;
        };

        // Poll immediately
        pollForStrategies();

        // Then poll every 10 seconds
        const pollInterval = setInterval(pollForStrategies, 10000);

        // Stop polling after 5 minutes if no strategies are generated
        const timeout = setTimeout(() => {
            console.log('Polling timeout reached');
            setIsPolling(false);
            setPollingError(true);
        }, 300000); // 5 minutes

        return () => {
            console.log('Cleaning up polling interval');
            clearInterval(pollInterval);
            clearTimeout(timeout);
        };
    }, [isPolling, campaigns.id]);

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
                post(route('campaigns.strategies.sign-off', { campaign: campaigns.id, strategy: strategy.id }), {
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
                post(route('campaigns.sign-off-all', { campaign: campaigns.id }), {
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

    const handleRegenerate = () => {
        setConfirmModal({
            show: true,
            title: 'Regenerate Strategies',
            message: 'This will delete all current strategies and generate new ones using AI. Are you sure?',
            onConfirm: () => {
                setConfirmModal({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
                router.post(route('campaigns.regenerate-strategies', { campaign: campaigns.id }), {}, {
                    preserveScroll: true,
                    onSuccess: () => {
                        setIsPolling(true);
                        setPollingError(false);
                        setElapsedSeconds(0);
                    }
                });
            },
            isDestructive: true,
            confirmText: 'Regenerate'
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-jet leading-tight">Review Strategy for: {campaigns.name}</h2>}
        >
            <Head title={`Strategy for ${campaigns.name}`} />

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

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
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
                                    <p className="font-semibold">
                                        {campaigns.strategy_generation_error 
                                            ? 'Strategy generation failed' 
                                            : 'Strategy generation is taking longer than expected'}
                                    </p>
                                    <p className="text-sm mt-1">
                                        {campaigns.strategy_generation_error 
                                            ? campaigns.strategy_generation_error
                                            : 'Please refresh the page in a moment or contact support if this persists.'}
                                    </p>
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
                                    onClick={handleRegenerate}
                                    disabled={processing || isPolling}
                                    className="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 disabled:opacity-50 transition"
                                >
                                    <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Regenerate Strategies
                                </button>
                            )}
                            <PrimaryButton onClick={handleSignOffAll} disabled={processing || allStrategiesSignedOff} className="bg-green-600 hover:bg-green-700">
                                {allStrategiesSignedOff ? 'All Strategies Signed Off' : 'Sign Off All Strategies'}
                            </PrimaryButton>
                        </div>
                    )}

                    {campaigns.strategies && campaigns.strategies.length > 0 ? (
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                            {campaigns.strategies.map(strategy => (
                                <StrategyCard 
                                    key={strategy.id} 
                                    strategy={strategy} 
                                    campaignId={campaigns.id}
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

            {/* Campaign Copilot */}
            <CampaignCopilot campaignId={campaigns.id} isOpen={copilotOpen} onClose={() => setCopilotOpen(false)} />

            {/* Copilot FAB */}
            {!copilotOpen && (
                <button
                    onClick={() => setCopilotOpen(true)}
                    className="fixed bottom-6 right-6 z-40 bg-flame-orange-600 text-white p-4 rounded-full shadow-lg hover:bg-flame-orange-700 hover:scale-105 transition-all group"
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
