import React, { useState, useEffect } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import RefineImageModal from '@/Components/RefineImageModal';

export default function Collateral({ campaign, currentStrategy, allStrategies, adCopy, imageCollaterals }) {
    const { auth } = usePage().props;
    const [activeTab, setActiveTab] = useState(currentStrategy.platform);
    const [generatingAdCopy, setGeneratingAdCopy] = useState(false);
    const [generatingImage, setGeneratingImage] = useState(false);
    const [editingImage, setEditingImage] = useState(null);
    const [collateral, setCollateral] = useState({ adCopy, imageCollaterals });
    const [isPolling, setIsPolling] = useState(false);

    // Polling effect
    useEffect(() => {
        if (!isPolling) return;

        const pollInterval = setInterval(async () => {
            try {
                const response = await fetch(route('api.collateral.show', { strategy: currentStrategy.id }));
                if (response.ok) {
                    const data = await response.json();
                    // Only update if there's new data to avoid unnecessary re-renders
                    if (JSON.stringify(data.imageCollaterals) !== JSON.stringify(collateral.imageCollaterals)) {
                        setCollateral(data);
                    }
                }
            } catch (error) {
                console.error('Error polling for collateral:', error);
            }
        }, 5000); // Poll every 5 seconds

        // Stop polling after a certain time to avoid infinite loops
        const timeout = setTimeout(() => {
            setIsPolling(false);
            clearInterval(pollInterval);
        }, 300000); // Stop after 5 minutes

        return () => {
            clearInterval(pollInterval);
            clearTimeout(timeout);
        };
    }, [isPolling, currentStrategy.id, collateral.imageCollaterals]);

    // Function to handle tab changes
    const handleTabChange = (platform) => {
        setActiveTab(platform);
    };

    const handleGenerateAdCopy = (strategyId, platform) => {
        if (window.confirm(`Are you sure you want to generate ad copy for ${platform}? This will overwrite any existing ad copy for this platform.`)) {
            setGeneratingAdCopy(true);
            router.post(route('campaigns.collateral.ad-copy.store', { campaign: campaign.id, strategy: strategyId }), { platform: platform }, {
                onSuccess: () => setGeneratingAdCopy(false),
                onError: (errors) => {
                    setGeneratingAdCopy(false);
                    alert('Failed to generate ad copy: ' + (errors.platform || 'An unknown error occurred.'));
                },
                preserveScroll: true,
            });
        }
    };

    const handleGenerateImage = (strategyId) => {
        if (window.confirm(`Are you sure you want to generate an image for this strategy? This will dispatch a background job.`)) {
            setGeneratingImage(true);
            router.post(route('campaigns.collateral.image.store', { campaign: campaign.id, strategy: strategyId }), {}, {
                onSuccess: () => {
                    setGeneratingImage(false);
                    setIsPolling(true); // Start polling
                },
                onError: (errors) => {
                    setGeneratingImage(false);
                    alert('Failed to start image generation: ' + (errors.message || 'An unknown error occurred.'));
                },
                preserveScroll: true,
            });
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Collateral for {campaign.name} - {currentStrategy.name}</h2>}
        >
            <Head title="Collateral" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        {/* Tab Navigation */}
                        <div className="border-b border-gray-200">
                            <nav className="-mb-px flex space-x-8" aria-label="Tabs">
                                {allStrategies.map((strategyItem) => (
                                    <Link
                                        key={strategyItem.id}
                                        href={route('campaigns.collateral.show', { campaign: campaign.id, strategy: strategyItem.id })}
                                        onClick={() => handleTabChange(strategyItem.platform)}
                                        className={`
                                            ${activeTab === strategyItem.platform
                                                ? 'border-blue-500 text-blue-600'
                                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                            }
                                            whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200
                                        `}
                                    >
                                        {strategyItem.platform}
                                    </Link>
                                ))}
                            </nav>
                        </div>

                        {/* Tab Content */}
                        <div className="mt-6">
                            {allStrategies.map((strategyItem) => (
                                activeTab === strategyItem.platform && (
                                    <div key={strategyItem.id}>
                                        <h3 className="text-lg font-semibold text-gray-900 mb-4">{strategyItem.platform} Ad Copy</h3>
                                        <p>Generate dynamic ad copy for {strategyItem.platform} based on the strategy.</p>
                                        
                                        <button
                                            onClick={() => handleGenerateAdCopy(strategyItem.id, strategyItem.platform)}
                                            disabled={generatingAdCopy}
                                            className="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                                        >
                                            {generatingAdCopy ? 'Generating...' : 'Generate Ad Copy'}
                                        </button>

                                        {/* Display generated ad copy here */}
                                        {adCopy && adCopy.strategy_id === strategyItem.id && adCopy.platform === strategyItem.platform && (
                                            <div className="mt-6 p-4 bg-gray-50 rounded-lg border border-gray-200">
                                                <h4 className="text-md font-semibold text-gray-800 mb-3">Generated Ad Copy:</h4>
                                                <div className="mb-4">
                                                    <h5 className="font-medium text-gray-700">Headlines:</h5>
                                                    <ul className="list-disc list-inside text-gray-600">
                                                        {adCopy.headlines.map((headline, index) => (
                                                            <li key={index}>{headline}</li>
                                                        ))}
                                                    </ul>
                                                </div>
                                                <div>
                                                    <h5 className="font-medium text-gray-700">Descriptions:</h5>
                                                    <ul className="list-disc list-inside text-gray-600">
                                                        {adCopy.descriptions.map((description, index) => (
                                                            <li key={index}>{description}</li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            </div>
                                        )}

                                        <hr className="my-8" />

                                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Image Collateral</h3>
                                        <p>Generate a unique image based on the imagery strategy for {strategyItem.platform}.</p>

                                        <button
                                            onClick={() => handleGenerateImage(strategyItem.id)}
                                            disabled={generatingImage}
                                            className="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                                        >
                                            {generatingImage ? 'Queuing Job...' : 'Generate Image'}
                                        </button>

                                        {/* Display generated images here */}
                                        {collateral.imageCollaterals && collateral.imageCollaterals.length > 0 && (
                                            <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                                {collateral.imageCollaterals.map((image) => (
                                                    <div key={image.id} className="border rounded-lg overflow-hidden shadow-md group relative">
                                                        <img src={image.cloudfront_url} alt={`Generated collateral for ${strategyItem.platform}`} className="w-full h-auto object-cover" />
                                                        <div className="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                                            <button onClick={() => setEditingImage(image)} className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                                                                Edit Image
                                                            </button>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            {editingImage && (
                <RefineImageModal
                    image={editingImage}
                    onClose={() => setEditingImage(null)}
                />
            )}
        </AuthenticatedLayout>
    );
}