
import React, { useState } from 'react';
import { useForm, Link } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';

// A reusable component for a single strategy card
const StrategyCard = ({ strategy, campaignId }) => {
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

export default StrategyCard;
