import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import PrimaryButton from '@/Components/PrimaryButton';

// A reusable component for a single strategy card
const StrategyCard = ({ strategy, campaignId }) => {
    const [isEditing, setIsEditing] = useState(false);
    const { data, setData, post, put, processing } = useForm({
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
        if (window.confirm('Are you sure you want to sign off this strategy? This action cannot be undone.')) {
            post(route('campaigns.strategies.sign-off', { campaign: campaignId, strategy: strategy.id }), {
                onSuccess: () => {
                    // Redirect to the collateral page after signing off
                    window.location.href = route('campaigns.collateral.show', { campaign: campaignId });
                }
            });
        }
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
    const [isPolling, setIsPolling] = useState(campaigns.strategies.length === 0);
    const { post, processing } = useForm();

    useEffect(() => {
        if (!isPolling) return;

        const pollInterval = setInterval(async () => {
            try {
                const apiResponse = await fetch(`/api/campaigns/${campaigns.id}`);
                if (apiResponse.ok) {
                    const data = await apiResponse.json();
                    setCampaign(data);
                    
                    // Stop polling if strategies are now available
                    if (data.strategies && data.strategies.length > 0) {
                        setIsPolling(false);
                    }
                }
            } catch (error) {
                console.error('Error polling for strategies:', error);
            }
        }, 15000); // Poll every 15 seconds

        return () => clearInterval(pollInterval);
    }, [isPolling, campaigns.id]);

    const handleSignOffAll = () => {
        if (window.confirm('Are you sure you want to sign off on all strategies? This will lock them and generate ad copy for all platforms.')) {
            post(route('campaigns.sign-off-all', { campaign: campaigns.id }));
        }
    };

    const allStrategiesSignedOff = campaigns.strategies.every(strategy => !!strategy.signed_off_at);

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-jet leading-tight">Review Strategy for: {campaigns.name}</h2>}
        >
            <Head title={`Strategy for ${campaigns.name}`} />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {isPolling && (
                        <div className="mb-8 p-6 bg-delft-blue text-white rounded-lg flex items-center justify-center space-x-3">
                            <div className="animate-spin">
                                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <span className="text-lg font-semibold">AI is thinking about your strategy... This may take a minute.</span>
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
                                <StrategyCard key={strategy.id} strategy={strategy} campaignId={campaigns.id} />
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
