import React, { useState, useEffect, useRef } from 'react';
import { Head, Link, usePage, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import RefineImageModal from '@/Components/RefineImageModal';
import ExtendVideoModal from '@/Components/ExtendVideoModal';
import SubscriptionRequiredModal from '@/Components/SubscriptionRequiredModal';
import DeploymentDisabledModal from '@/Components/DeploymentDisabledModal';
import AdSpendSetupModal from '@/Components/AdSpendSetupModal';
import ConfirmationModal from '@/Components/ConfirmationModal';
import Modal from '@/Components/Modal';
import AdPreviewPanel from '@/Components/AdPreview';
import { useToast } from '@/Components/Toast';

export default function Collateral({ campaign, currentStrategy, allStrategies, adCopy, imageCollaterals, videoCollaterals, hasActiveSubscription, deploymentEnabled, managedBillingEnabled, adSpendCredit }) {
    const { auth } = usePage().props;
    const isSubscribed = hasActiveSubscription || auth.user?.subscription_status === 'active';
    const toast = useToast();
    const [activeTab, setActiveTab] = useState(currentStrategy.platform);
    const [generatingAdCopy, setGeneratingAdCopy] = useState(false);
    const [generatingImage, setGeneratingImage] = useState(false);
    const [generatingVideo, setGeneratingVideo] = useState(false);
    const [editingImage, setEditingImage] = useState(null);
    const [extendingVideo, setExtendingVideo] = useState(null);
    const [collateral, setCollateral] = useState({ adCopy, imageCollaterals, videoCollaterals });
    const [isPolling, setIsPolling] = useState(false);
    const [showDeployModal, setShowDeployModal] = useState(false);
    const [showSubscriptionModal, setShowSubscriptionModal] = useState(false);
    const [showDeploymentDisabledModal, setShowDeploymentDisabledModal] = useState(false);
    const [showAdSpendSetupModal, setShowAdSpendSetupModal] = useState(false);
    const [showPreview, setShowPreview] = useState(false);
    const [confirmModal, setConfirmModal] = useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
    const [uploadingImages, setUploadingImages] = useState(false);
    const [uploadingVideo, setUploadingVideo] = useState(false);
    const [imageUploadErrors, setImageUploadErrors] = useState([]);

    // Refs for values accessed inside the polling interval to avoid stale closures
    // and prevent the effect from restarting (which resets the safety timeout).
    const collateralRef = useRef(collateral);
    const generatingAdCopyRef = useRef(generatingAdCopy);
    const generatingImageRef = useRef(generatingImage);
    const generatingVideoRef = useRef(generatingVideo);

    useEffect(() => { collateralRef.current = collateral; }, [collateral]);
    useEffect(() => { generatingAdCopyRef.current = generatingAdCopy; }, [generatingAdCopy]);
    useEffect(() => { generatingImageRef.current = generatingImage; }, [generatingImage]);
    useEffect(() => { generatingVideoRef.current = generatingVideo; }, [generatingVideo]);

    // Polling effect
    useEffect(() => {
        if (!isPolling) return;

        const pollInterval = setInterval(async () => {
            try {
                const response = await fetch(route('api.collateral.show', { strategy: currentStrategy.id }));
                if (response.ok) {
                    const data = await response.json();
                    const current = collateralRef.current;
                    // Update all collateral data
                    const hasNewAdCopy = data.adCopy && (!current.adCopy || data.adCopy.updated_at !== current.adCopy?.updated_at);
                    const hasNewImages = JSON.stringify(data.imageCollaterals) !== JSON.stringify(current.imageCollaterals);
                    const hasNewVideos = JSON.stringify(data.videoCollaterals) !== JSON.stringify(current.videoCollaterals);
                    
                    if (hasNewAdCopy || hasNewImages || hasNewVideos) {
                        setCollateral(data);
                        
                        // Stop polling for ad copy if it was generated
                        if (hasNewAdCopy && generatingAdCopyRef.current) {
                            setGeneratingAdCopy(false);
                        }
                        
                        // Stop image generation flag if new images arrived
                        if (hasNewImages && generatingImageRef.current) {
                            setGeneratingImage(false);
                        }
                        
                        // Stop video generation flag if new videos arrived
                        if (hasNewVideos && generatingVideoRef.current) {
                            setGeneratingVideo(false);
                        }
                    }
                }
            } catch (error) {
                console.error('Failed to poll collateral:', error);
            }
        }, 3000); // Poll every 3 seconds

        // Stop polling after 5 minutes (safety net — not reset by data changes)
        const timeout = setTimeout(() => {
            setIsPolling(false);
            setGeneratingAdCopy(false);
            setGeneratingImage(false);
            setGeneratingVideo(false);
        }, 300000);

        return () => {
            clearInterval(pollInterval);
            clearTimeout(timeout);
        };
    }, [isPolling, currentStrategy.id]);

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
                        toast.error('Failed to generate ad copy: ' + (errors.platform || 'An unknown error occurred.'));
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
                setIsPolling(true);
                router.post(route('campaigns.collateral.image.store', { campaign: campaign.id, strategy: strategyId }), {}, {
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

    const handleGenerateVideo = (strategyId, platform) => {
        setConfirmModal({
            show: true,
            title: 'Generate Video',
            message: 'Are you sure you want to generate a video for this strategy? This can take several minutes.',
            onConfirm: () => {
                setConfirmModal({ ...confirmModal, show: false });
                setGeneratingVideo(true);
                setIsPolling(true);
                router.post(route('campaigns.collateral.video.store', { campaign: campaign.id, strategy: strategyId }), { platform }, {
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

    const handleToggleCollateral = (type, id) => {
        router.post(route('deployment.toggle-collateral'), {
            type,
            id,
        }, {
            preserveScroll: true,
            only: ['adCopy', 'imageCollaterals', 'videoCollaterals'],
            onError: (errors) => {
                console.error('Failed to toggle collateral status:', errors);
            },
        });
    };

    const handleImageUpload = (strategyId, files) => {
        if (!files || files.length === 0) return;
        setUploadingImages(true);
        setImageUploadErrors([]);

        const formData = new FormData();
        Array.from(files).forEach((file) => {
            formData.append('images[]', file);
        });

        router.post(route('campaigns.collateral.image.upload', { campaign: campaign.id, strategy: strategyId }), formData, {
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

    const handleVideoUpload = (strategyId, file) => {
        if (!file) return;
        setUploadingVideo(true);

        const formData = new FormData();
        formData.append('video', file);

        router.post(route('campaigns.collateral.video.upload', { campaign: campaign.id, strategy: strategyId }), formData, {
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

        // Check if ad spend billing is set up (only when managed billing is enabled)
        if (managedBillingEnabled && (!adSpendCredit || adSpendCredit.status === 'pending')) {
            setShowAdSpendSetupModal(true);
            return;
        }

        // Check if account is paused due to payment failure (only when managed billing is enabled)
        if (managedBillingEnabled && adSpendCredit?.status === 'paused') {
            toast.warning('Your ad spend billing is paused due to a payment issue. Please update your payment method in Billing → Ad Spend before deploying.');
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

    const handleAdSpendSetupSuccess = (result) => {
        setShowAdSpendSetupModal(false);
        // After successful setup, proceed to deploy
        setConfirmModal({
            show: true,
            title: 'Deploy Collateral',
            message: `Payment successful! Your ad spend credit of $${result.credit_amount?.toFixed(2) || '0.00'} has been set up. Ready to deploy?`,
            onConfirm: () => confirmDeploy(),
            confirmText: 'Deploy Now',
            confirmButtonClass: 'bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800',
            isDestructive: false
        });
    };

    const confirmDeploy = () => {
        router.post(route('deployment.deploy'), {
            campaign_id: campaign.id,
        }, {
            preserveScroll: true,
            onSuccess: (page) => {
                toast.success('Campaign deployment has been initiated! Your ads will be deployed to the selected platforms shortly.');
            },
            onError: (errors) => {
                console.error('Deployment errors:', errors);
                if (errors.message) {
                    toast.error(errors.message);
                } else {
                    toast.error('Deployment failed. Please check the console for details.');
                }
            },
        });
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

            <AdSpendSetupModal
                show={showAdSpendSetupModal}
                onClose={() => setShowAdSpendSetupModal(false)}
                onSuccess={handleAdSpendSetupSuccess}
                campaign={campaign}
                campaignName={campaign.name}
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
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-4 sm:p-6">
                        {/* Tab Navigation */}
                        <div className="border-b border-gray-200">
                            <nav className="-mb-px flex gap-2 sm:gap-6 overflow-x-auto" aria-label="Tabs">
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
                                            <>
                                                <div 
                                                    className={`mt-6 p-4 bg-gray-50 rounded-lg border-2 ${collateral.adCopy.should_deploy ? 'border-green-500' : 'border-gray-200'} cursor-pointer relative`}
                                                    onClick={() => handleToggleCollateral('ad_copy', collateral.adCopy.id)}
                                                >
                                                    <div className="flex justify-between items-start mb-3">
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
                                                                    className="px-3 py-1 text-xs font-medium text-flame-orange-600 bg-flame-orange-50 rounded-md hover:bg-flame-orange-100"
                                                                >
                                                                    📋 Copy All
                                                                </button>
                                                            ) : (
                                                                <a
                                                                    href={route('subscription.pricing')}
                                                                    onClick={(e) => e.stopPropagation()}
                                                                    className="px-3 py-1 text-xs font-medium text-white bg-flame-orange-600 rounded-md hover:bg-flame-orange-700"
                                                                >
                                                                    🔒 Upgrade to Export
                                                                </a>
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
                                                                headlines={collateral.adCopy.headlines}
                                                                descriptions={collateral.adCopy.descriptions}
                                                                businessName={campaign.name}
                                                                displayUrl={campaign.target_url || 'example.com'}
                                                                imageUrl={collateral.imageCollaterals?.[0]?.cloudfront_url}
                                                            />
                                                        </div>
                                                    )}
                                                </div>
                                            </>
                                        )}

                                        <hr className="my-8" />

                                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Image Collateral</h3>
                                        <p>Generate a unique image based on the imagery strategy for {strategyItem.platform}, or upload your own.</p>

                                        <div className="flex flex-wrap gap-3 mt-4">
                                            <button
                                                onClick={() => handleGenerateImage(strategyItem.id)}
                                                disabled={generatingImage}
                                                className="px-4 py-2 bg-flame-orange-600 text-white rounded-lg hover:bg-flame-orange-700 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center gap-2"
                                            >
                                                {generatingImage && (
                                                    <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                    </svg>
                                                )}
                                                {generatingImage ? 'Generating...' : '✨ Generate Image'}
                                            </button>

                                            <label className={`px-4 py-2 border-2 border-dashed border-gray-300 text-gray-600 rounded-lg hover:border-blue-400 hover:text-blue-600 cursor-pointer transition flex items-center gap-2 ${uploadingImages ? 'opacity-50 pointer-events-none' : ''}`}>
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
                                                    onChange={(e) => handleImageUpload(strategyItem.id, e.target.files)}
                                                    disabled={uploadingImages}
                                                />
                                            </label>
                                            <span className="text-xs text-gray-400 self-center">
                                                JPG, PNG, or WebP · max 10MB each · {(() => {
                                                    const uploaded = collateral.imageCollaterals?.filter(i => i.source === 'uploaded').length || 0;
                                                    return `${uploaded}/10 uploaded`;
                                                })()}
                                            </span>
                                        </div>

                                        {/* Display generated + uploaded images */}
                                        {collateral.imageCollaterals && collateral.imageCollaterals.length > 0 && (
                                            <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                                {collateral.imageCollaterals.map((image) => (
                                                    <div 
                                                        key={image.id} 
                                                        className={`border-2 ${image.should_deploy ? 'border-green-500' : 'border-transparent'} rounded-lg overflow-hidden shadow-md group relative cursor-pointer`}
                                                        onClick={() => handleToggleCollateral('image', image.id)}
                                                    >
                                                        <img src={image.cloudfront_url} alt={`Collateral for ${strategyItem.platform}`} className="w-full h-auto object-cover" />
                                                        {/* Source badge */}
                                                        <div className={`absolute top-2 left-2 px-2 py-0.5 rounded-full text-xs font-medium shadow ${
                                                            image.source === 'uploaded' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'
                                                        }`}>
                                                            {image.source === 'uploaded' ? '📁 Uploaded' : '✨ AI'}
                                                        </div>
                                                        {!isSubscribed && (
                                                            <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-3">
                                                                <p className="text-white text-xs font-medium">Preview - Upgrade to download</p>
                                                            </div>
                                                        )}
                                                        <div className="absolute inset-0 bg-black bg-opacity-50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                                            {isSubscribed ? (
                                                                <div className="flex gap-2">
                                                                    {image.source !== 'uploaded' && (
                                                                        <button onClick={(e) => { e.stopPropagation(); setEditingImage(image); }} className="px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-md hover:bg-flame-orange-700">
                                                                            Edit Image
                                                                        </button>
                                                                    )}
                                                                    <a 
                                                                        href={image.cloudfront_url} 
                                                                        download 
                                                                        onClick={(e) => e.stopPropagation()}
                                                                        className="px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-md hover:bg-green-700"
                                                                    >
                                                                        Download
                                                                    </a>
                                                                    {image.source === 'uploaded' && (
                                                                        <button 
                                                                            onClick={(e) => { e.stopPropagation(); handleDeleteCollateral('image', image.id); }}
                                                                            className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-md hover:bg-red-700"
                                                                        >
                                                                            Delete
                                                                        </button>
                                                                    )}
                                                                </div>
                                                            ) : (
                                                                <a 
                                                                    href={route('subscription.pricing')}
                                                                    onClick={(e) => e.stopPropagation()}
                                                                    className="px-4 py-2 text-sm font-medium text-white bg-flame-orange-600 rounded-md hover:bg-flame-orange-700"
                                                                >
                                                                    🔒 Upgrade to Download
                                                                </a>
                                                            )}
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        )}

                                        <hr className="my-8" />

                                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Video Collateral</h3>
                                        <p>Generate a unique video based on the video strategy for {strategyItem.platform}, or upload your own.</p>

                                        <div className="flex flex-wrap gap-3 mt-4">
                                            <button
                                                onClick={() => handleGenerateVideo(strategyItem.id, strategyItem.platform)}
                                                disabled={generatingVideo}
                                                className="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 disabled:opacity-50 disabled:cursor-not-allowed transition flex items-center gap-2"
                                            >
                                                {generatingVideo && (
                                                    <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
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
                                                    onChange={(e) => handleVideoUpload(strategyItem.id, e.target.files?.[0])}
                                                    disabled={uploadingVideo}
                                                />
                                            </label>
                                            <span className="text-xs text-gray-400 self-center">
                                                MP4, MOV, or WebM · max 100MB · {(() => {
                                                    const uploaded = collateral.videoCollaterals?.filter(v => v.source === 'uploaded').length || 0;
                                                    return `${uploaded}/3 uploaded`;
                                                })()}
                                            </span>
                                        </div>

                                        {/* Display generated + uploaded videos */}
                                        {collateral.videoCollaterals && collateral.videoCollaterals.length > 0 && (
                                            <div className="mt-6 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                                                {collateral.videoCollaterals.map((video) => (
                                                    <div 
                                                        key={video.id} 
                                                        className="relative group"
                                                    >
                                                        <div 
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

                                                        {/* Source badge */}
                                                        <div className={`absolute top-2 left-2 px-2 py-0.5 rounded-full text-xs font-medium shadow ${
                                                            video.source === 'uploaded' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'
                                                        }`}>
                                                            {video.source === 'uploaded' ? '📁 Uploaded' : '✨ AI'}
                                                        </div>
                                                        
                                                        {/* Extend Video Button - Only show for completed Veo videos (AI-generated) */}
                                                        {video.source !== 'uploaded' && video.status === 'completed' && video.gemini_video_uri && (video.extension_count || 0) < 20 && (
                                                            <button
                                                                onClick={(e) => {
                                                                    e.stopPropagation();
                                                                    setExtendingVideo(video);
                                                                }}
                                                                className="absolute bottom-2 right-2 bg-purple-600 hover:bg-purple-700 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg flex items-center space-x-1 opacity-0 group-hover:opacity-100 transition-opacity"
                                                                title={`Extend video by 7 seconds (${20 - (video.extension_count || 0)} extensions remaining)`}
                                                            >
                                                                <svg className="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                                                </svg>
                                                                <span>Extend</span>
                                                            </button>
                                                        )}

                                                        {/* Delete button for uploaded videos */}
                                                        {video.source === 'uploaded' && (
                                                            <button
                                                                onClick={(e) => { e.stopPropagation(); handleDeleteCollateral('video', video.id); }}
                                                                className="absolute bottom-2 right-2 bg-red-600 hover:bg-red-700 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg flex items-center space-x-1 opacity-0 group-hover:opacity-100 transition-opacity"
                                                            >
                                                                <span>Delete</span>
                                                            </button>
                                                        )}

                                                        {/* Extension Count Badge */}
                                                        {(video.extension_count || 0) > 0 && (
                                                            <div className="absolute top-2 right-2 bg-blue-600 text-white text-xs px-2 py-1 rounded-full shadow-lg">
                                                                Extended {video.extension_count}x
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

            {extendingVideo && (
                <ExtendVideoModal
                    video={extendingVideo}
                    onClose={() => setExtendingVideo(null)}
                    onExtensionStart={() => {
                        setIsPolling(true);
                        // Show success message
                        setTimeout(() => {
                            setIsPolling(false);
                        }, 180000); // Stop polling after 3 minutes
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