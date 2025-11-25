import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import PrimaryButton from '@/Components/PrimaryButton';
import CollateralGenerationModal from '@/Components/CollateralGenerationModal';
import ConfirmationModal from '@/Components/ConfirmationModal';

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
                <div className="mt-6 p-2 text-center bg-green-100 text-green-800 rounded-lg">
                    Strategy Signed Off on {new Date(strategy.signed_off_at).toLocaleString()}
                    <Link
                        href={route('campaigns.collateral.show', { campaign: strategy.campaign_id, strategy: strategy.id })}
                        className="ml-4 px-3 py-1 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                    >
                        View Collateral
                    </Link>
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


export default function Show({ auth, campaign }) {
    const [campaigns, setCampaign] = useState(campaign);
    const [isPolling, setIsPolling] = useState(
        campaign.is_generating_strategies || 
        (campaign.strategies.length === 0 && campaign.strategy_generation_started_at)
    );
    const [showGenerationModal, setShowGenerationModal] = useState(false);
    const [pollingError, setPollingError] = useState(false);
    const [confirmModal, setConfirmModal] = useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
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
                        <div className="mb-8 p-6 bg-delft-blue text-white rounded-lg flex items-center justify-center space-x-3">
                            <div className="animate-spin">
                                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div>
                                <span className="text-lg font-semibold">AI is generating your advertising strategies...</span>
                                {campaigns.strategy_generation_started_at && (
                                    <p className="text-sm text-blue-200 mt-1">
                                        Started {new Date(campaigns.strategy_generation_started_at).toLocaleTimeString()}
                                    </p>
                                )}
                            </div>
                        </div>
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
                    
                    {campaigns.strategies && campaigns.strategies.length > 0 && (
                        <div className="mb-8 flex justify-end">
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
        </AuthenticatedLayout>
    );
}
