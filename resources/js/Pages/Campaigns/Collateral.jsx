import React, { useState, useEffect } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import RefineImageModal from '@/Components/RefineImageModal';
import SubscriptionRequiredModal from '@/Components/SubscriptionRequiredModal';
import DeploymentDisabledModal from '@/Components/DeploymentDisabledModal';
import ConfirmationModal from '@/Components/ConfirmationModal';
import Modal from '@/Components/Modal';

export default function Collateral({ campaign, currentStrategy, allStrategies, adCopy, imageCollaterals, videoCollaterals, hasActiveSubscription, deploymentEnabled }) {
    const { auth } = usePage().props;
    const [activeTab, setActiveTab] = useState(currentStrategy.platform);
    const [generatingAdCopy, setGeneratingAdCopy] = useState(false);
    const [generatingImage, setGeneratingImage] = useState(false);
    const [generatingVideo, setGeneratingVideo] = useState(false);
    const [editingImage, setEditingImage] = useState(null);
    const [collateral, setCollateral] = useState({ adCopy, imageCollaterals, videoCollaterals });
    const [isPolling, setIsPolling] = useState(false);
    const [showDeployModal, setShowDeployModal] = useState(false);
    const [showSubscriptionModal, setShowSubscriptionModal] = useState(false);
    const [showDeploymentDisabledModal, setShowDeploymentDisabledModal] = useState(false);
    const [confirmModal, setConfirmModal] = useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });


    // Polling effect
    useEffect(() => {
        if (!isPolling) return;

        const pollInterval = setInterval(async () => {
            try {
                const response = await fetch(route('api.collateral.show', { strategy: currentStrategy.id }));
                if (response.ok) {
                    const data = await response.json();
                    // Update all collateral data
                    const hasNewAdCopy = data.adCopy && (!collateral.adCopy || data.adCopy.updated_at !== collateral.adCopy?.updated_at);
                    const hasNewImages = JSON.stringify(data.imageCollaterals) !== JSON.stringify(collateral.imageCollaterals);
                    const hasNewVideos = JSON.stringify(data.videoCollaterals) !== JSON.stringify(collateral.videoCollaterals);
                    
                    if (hasNewAdCopy || hasNewImages || hasNewVideos) {
                        setCollateral(data);
                        
                        // Stop polling for ad copy if it was generated
                        if (hasNewAdCopy && generatingAdCopy) {
                            setGeneratingAdCopy(false);
                        }
                        
                        // Stop image generation flag if new images arrived
                        if (hasNewImages && generatingImage) {
                            setGeneratingImage(false);
                        }
                        
                        // Stop video generation flag if new videos arrived
                        if (hasNewVideos && generatingVideo) {
                            setGeneratingVideo(false);
                        }
                    }
                }
            } catch (error) {
                console.error('Failed to generate ad copy:', error);
            }
        }, 3000); // Poll every 3 seconds

        // Stop polling after a certain time to avoid infinite loops
        const timeout = setTimeout(() => {
            setIsPolling(false);
            setGeneratingAdCopy(false);
            setGeneratingImage(false);
            setGeneratingVideo(false);
            clearInterval(pollInterval);
        }, 300000); // Stop after 5 minutes

        return () => {
            clearInterval(pollInterval);
            clearTimeout(timeout);
        };
    }, [isPolling, currentStrategy.id, collateral, generatingAdCopy, generatingImage, generatingVideo]);

    // Function to handle tab changes
    const handleTabChange = (platform) => {
        setActiveTab(platform);
    };

    const handleGenerateAdCopy = (strategyId, platform) => {
        setConfirmModal({
            show: true,
            title: 'Generate Ad Copy',
            message: `Are you sure you want to generate ad copy for ${platform}? This will overwrite any existing ad copy for this platform.`,
            onConfirm: () => {
                setConfirmModal({ ...confirmModal, show: false });
                setGeneratingAdCopy(true);
                setIsPolling(true);
                router.post(route('campaigns.ad-copy.store', { campaign: campaign.id, strategy: strategyId }), { platform: platform }, {
                    onError: (errors) => {
                        setGeneratingAdCopy(false);
                        setIsPolling(false);
                        alert('Failed to generate ad copy: ' + (errors.platform || 'An unknown error occurred.'));
                    },
                    preserveScroll: true,
                });
            },
            isDestructive: false
        });
    };

    const handleGenerateImage = (strategyId) => {
        setConfirmModal({
            show: true,
            title: 'Generate Image',
            message: 'Are you sure you want to generate an image for this strategy? This will dispatch a background job.',
            onConfirm: () => {
                setConfirmModal({ ...confirmModal, show: false });
                setGeneratingImage(true);
                router.post(route('campaigns.collateral.image.store', { campaign: campaign.id, strategy: strategyId }), {}, {
                    onSuccess: () => {
                        setGeneratingImage(false);
                        setIsPolling(true);
                    },
                    onError: (errors) => {
                        setGeneratingImage(false);
                        alert('Failed to start image generation: ' + (errors.message || 'An unknown error occurred.'));
                    },
                    preserveScroll: true,
                });
            },
            isDestructive: false
        });
    };

    const handleGenerateVideo = (strategyId, platform) => {
        setConfirmModal({
            show: true,
            title: 'Generate Video',
            message: 'Are you sure you want to generate a video for this strategy? This can take several minutes.',
            onConfirm: () => {
                setConfirmModal({ ...confirmModal, show: false });
                setGeneratingVideo(true);
                router.post(route('campaigns.collateral.video.store', { campaign: campaign.id, strategy: strategyId }), { platform }, {
                    onSuccess: () => {
                        setGeneratingVideo(false);
                        setIsPolling(true);
                    },
                    onError: (errors) => {
                        setGeneratingVideo(false);
                        alert('Failed to start video generation: ' + (errors.message || 'An unknown error occurred.'));
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

    const handleToggleCollateral = async (type, id) => {
        try {
            const response = await fetch(route('api.deployment.toggle-collateral'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
                body: JSON.stringify({ type, id }),
            });

            if (response.ok) {
                // Refresh the collateral data to show the updated selection
                router.reload({ only: ['adCopy', 'imageCollaterals', 'videoCollaterals'] });
            } else {
                console.error('Failed to toggle collateral status');
            }
        } catch (error) {
            console.error('Error toggling collateral status:', error);
        }
    };

    const handleDeploy = async () => {
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

        setConfirmModal({
            show: true,
            title: 'Deploy Collateral',
            message: 'Are you sure you want to deploy the selected collateral?',
            onConfirm: () => confirmDeploy(),
            confirmText: 'Deploy',
            confirmButtonClass: 'bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800',
            isDestructive: false
        });
    };

    const confirmDeploy = async () => {
            try {
                const response = await fetch(route('api.deployment.deploy'), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    },
                });

                const data = await response.json();

                if (response.ok) {
                    alert(data.message);
                } else {
                    if (response.status === 403 && data.redirect) {
                        if (window.confirm(data.message)) {
                            window.location.href = data.redirect;
                        }
                    } else {
                        alert('Deployment failed: ' + error.message);
            }
        }
            } catch (error) {
                console.error('Error during deployment:', error);
                alert('An unexpected error occurred during deployment.');
            }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">Collateral for {campaign.name} - {currentStrategy.name}</h2>
                    <button
                        onClick={handleDeploy}
                        className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        Deploy
                    </button>
                </div>
            }
        >
            <Head title="Collateral" />

            <SubscriptionRequiredModal 
                show={showSubscriptionModal} 
                onClose={() => setShowSubscriptionModal(false)} 
            />

            <DeploymentDisabledModal 
                show={showDeploymentDisabledModal} 
                onClose={() => setShowDeploymentDisabledModal(false)} 
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
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                        {/* Tab Navigation */}
                        <div className="border-b border-gray-200">
                            <nav className="-mb-px flex space-x-8" aria-label="Tabs">
                                {allStrategies.map((strategyItem) => {
                                    const totalCollateral = strategyItem.ad_copies_count + strategyItem.image_collaterals_count + strategyItem.video_collaterals_count;
                                    return (
                                        <Link
                                            key={strategyItem.id}
                                            href={route('campaigns.collateral.show', { campaign: campaign.id, strategy: strategyItem.id })}
                                            onClick={() => handleTabChange(strategyItem.platform)}
                                            className={`
                                                ${activeTab === strategyItem.platform
                                                    ? 'border-blue-500 text-blue-600'
                                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                                }
                                                whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors duration-200 flex items-center
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
                                            className="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center gap-2"
                                        >
                                            {generatingAdCopy && (
                                                <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            )}
                                            {generatingAdCopy ? 'Generating Ad Copy...' : 'Generate Ad Copy'}
                                        </button>

                                        {/* Display generated ad copy here */}
                                        {collateral.adCopy && collateral.adCopy.strategy_id === strategyItem.id && collateral.adCopy.platform === strategyItem.platform && (
                                            <div 
                                                className={`mt-6 p-4 bg-gray-50 rounded-lg border-2 ${collateral.adCopy.should_deploy ? 'border-green-500' : 'border-gray-200'} cursor-pointer`}
                                                onClick={() => handleToggleCollateral('ad_copy', collateral.adCopy.id)}
                                            >
                                                <h4 className="text-md font-semibold text-gray-800 mb-3">Generated Ad Copy:</h4>
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
                                            </div>
                                        )}

                                        <hr className="my-8" />

                                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Image Collateral</h3>
                                        <p>Generate a unique image based on the imagery strategy for {strategyItem.platform}.</p>

                                        <button
                                            onClick={() => handleGenerateImage(strategyItem.id)}
                                            disabled={generatingImage}
                                            className="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center gap-2"
                                        >
                                            {generatingImage && (
                                                <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            )}
                                            {generatingImage ? 'Generating Image...' : 'Generate Image'}
                                        </button>

                                        {/* Display generated images here */}
                                        {collateral.imageCollaterals && collateral.imageCollaterals.length > 0 && (
                                            <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                                {collateral.imageCollaterals.map((image) => (
                                                    <div 
                                                        key={image.id} 
                                                        className={`border-2 ${image.should_deploy ? 'border-green-500' : 'border-transparent'} rounded-lg overflow-hidden shadow-md group relative cursor-pointer`}
                                                        onClick={() => handleToggleCollateral('image', image.id)}
                                                    >
                                                        <img src={image.cloudfront_url} alt={`Generated collateral for ${strategyItem.platform}`} className="w-full h-auto object-cover" />
                                                        <div className="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                                            <button onClick={(e) => { e.stopPropagation(); setEditingImage(image); }} className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                                                                Edit Image
                                                            </button>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}

                                        <hr className="my-8" />

                                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Video Collateral</h3>
                                        <p>Generate a unique video based on the video strategy for {strategyItem.platform}.</p>

                                        <button
                                            onClick={() => handleGenerateVideo(strategyItem.id, strategyItem.platform)}
                                            disabled={generatingVideo}
                                            className="mt-4 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center gap-2"
                                        >
                                            {generatingVideo && (
                                                <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                            )}
                                            {generatingVideo ? 'Generating Video...' : 'Generate Video'}
                                        </button>

                                        {/* Display generated videos here */}
                                        {collateral.videoCollaterals && collateral.videoCollaterals.length > 0 && (
                                            <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                                {collateral.videoCollaterals.map((video) => (
                                                    <div 
                                                        key={video.id} 
                                                        className={`border-2 ${video.should_deploy ? 'border-green-500' : 'border-transparent'} rounded-lg overflow-hidden shadow-md cursor-pointer`}
                                                        onClick={() => handleToggleCollateral('video', video.id)}
                                                    >
                                                        {video.status === 'completed' ? (
                                                            <video controls src={video.cloudfront_url} className="w-full h-auto"></video>
                                                        ) : (
                                                            <div className="p-4 text-center bg-gray-100">
                                                                <p className="font-semibold text-gray-700">Status: {video.status}</p>
                                                                <p className="text-sm text-gray-500">Video is processing...</p>
                                                            </div>
                                                        )}
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
                    onRefinementStart={handleRefinementStart}
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