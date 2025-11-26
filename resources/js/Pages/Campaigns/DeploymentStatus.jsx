import React, { useState, useEffect } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

/**
 * DeploymentStatus - Shows real-time deployment progress and status
 */
export default function DeploymentStatus({ campaign, deployments: initialDeployments }) {
    const { auth } = usePage().props;
    const [deployments, setDeployments] = useState(initialDeployments || []);
    const [overallProgress, setOverallProgress] = useState(0);
    const [isPolling, setIsPolling] = useState(true);
    
    // Calculate overall progress
    useEffect(() => {
        if (deployments.length === 0) return;
        
        const completedCount = deployments.filter(d => d.status === 'deployed').length;
        const progress = Math.round((completedCount / deployments.length) * 100);
        setOverallProgress(progress);
        
        // Stop polling if all deployed or all have errors
        const allComplete = deployments.every(d => 
            d.status === 'deployed' || d.status === 'failed'
        );
        if (allComplete) {
            setIsPolling(false);
        }
    }, [deployments]);
    
    // Poll for updates if deployment is in progress
    useEffect(() => {
        if (!isPolling) return;
        
        const pollInterval = setInterval(async () => {
            try {
                const response = await fetch(`/api/campaigns/${campaign.id}/deployment-status`);
                if (response.ok) {
                    const data = await response.json();
                    setDeployments(data.deployments || []);
                    setOverallProgress(data.overall_progress || 0);
                    
                    if (data.is_complete) {
                        setIsPolling(false);
                    }
                }
            } catch (error) {
                console.error('Failed to poll deployment status:', error);
            }
        }, 3000);
        
        return () => clearInterval(pollInterval);
    }, [isPolling, campaign.id]);
    
    // Listen for real-time updates if Echo is available
    useEffect(() => {
        if (typeof window !== 'undefined' && window.Echo) {
            const channel = window.Echo.private(`campaigns.${campaign.id}`);
            
            channel.listen('.deployment.progress', (e) => {
                setDeployments(prev => prev.map(d => 
                    d.id === e.strategy_id ? { ...d, ...e.deployment } : d
                ));
            });
            
            channel.listen('.deployment.completed', (e) => {
                setDeployments(prev => prev.map(d => 
                    d.id === e.strategy_id ? { ...d, status: 'deployed', deployed_at: new Date() } : d
                ));
            });
            
            channel.listen('.deployment.failed', (e) => {
                setDeployments(prev => prev.map(d => 
                    d.id === e.strategy_id ? { ...d, status: 'failed', error_message: e.error } : d
                ));
            });
            
            return () => {
                channel.stopListening('.deployment.progress');
                channel.stopListening('.deployment.completed');
                channel.stopListening('.deployment.failed');
            };
        }
    }, [campaign.id]);
    
    const getOverallStatus = () => {
        const hasFailure = deployments.some(d => d.status === 'failed');
        const allComplete = deployments.every(d => d.status === 'deployed');
        const isProcessing = deployments.some(d => d.status === 'deploying');
        
        if (allComplete) return 'completed';
        if (hasFailure) return 'failed';
        if (isProcessing) return 'processing';
        return 'pending';
    };
    
    const getStatusColor = (status) => {
        const colors = {
            pending: 'bg-yellow-100 text-yellow-800',
            deploying: 'bg-blue-100 text-blue-800',
            processing: 'bg-blue-100 text-blue-800',
            deployed: 'bg-green-100 text-green-800',
            completed: 'bg-green-100 text-green-800',
            failed: 'bg-red-100 text-red-800',
        };
        return colors[status] || 'bg-gray-100 text-gray-800';
    };
    
    const getStatusIcon = (status) => {
        const icons = {
            pending: '⏳',
            deploying: '🔄',
            processing: '🔄',
            deployed: '✅',
            completed: '✅',
            failed: '❌',
        };
        return icons[status] || '📋';
    };
    
    const overallStatus = getOverallStatus();
    
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <div>
                        <Link 
                            href={`/campaigns/${campaign.id}/strategies`}
                            className="text-sm text-indigo-600 hover:text-indigo-800 mb-1 block"
                        >
                            ← Back to Campaign
                        </Link>
                        <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                            Deployment Status: {campaign.name}
                        </h2>
                    </div>
                    <span className={`px-3 py-1 rounded-full text-sm font-medium ${getStatusColor(overallStatus)}`}>
                        {getStatusIcon(overallStatus)} {overallStatus?.charAt(0).toUpperCase() + overallStatus?.slice(1)}
                    </span>
                </div>
            }
        >
            <Head title={`Deployment - ${campaign.name}`} />
            
            <div className="py-12">
                <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Overall Progress */}
                    <div className="bg-white rounded-lg shadow-md p-6 mb-6">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-semibold text-gray-900">Overall Progress</h3>
                            <span className="text-2xl font-bold text-indigo-600">{overallProgress}%</span>
                        </div>
                        <div className="w-full bg-gray-200 rounded-full h-3">
                            <div 
                                className="bg-gradient-to-r from-indigo-500 to-purple-500 h-3 rounded-full transition-all duration-500"
                                style={{ width: `${overallProgress}%` }}
                            />
                        </div>
                    </div>
                    
                    {/* Platform Status Cards */}
                    <div className="grid grid-cols-1 gap-4 mb-6">
                        {deployments.map((deployment) => (
                            <div 
                                key={deployment.id}
                                className={`bg-white rounded-lg shadow-md p-6 border-l-4 ${
                                    deployment.status === 'deployed' ? 'border-green-500' :
                                    deployment.status === 'failed' ? 'border-red-500' :
                                    deployment.status === 'deploying' ? 'border-blue-500' :
                                    'border-gray-300'
                                }`}
                            >
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center space-x-3">
                                        <span className="text-2xl">
                                            {deployment.platform?.toLowerCase().includes('google') ? '🔍' : 
                                             deployment.platform?.toLowerCase().includes('facebook') ? '👥' : '📢'}
                                        </span>
                                        <div>
                                            <h4 className="font-semibold text-gray-900">{deployment.platform}</h4>
                                            <p className="text-sm text-gray-500">
                                                {deployment.ad_copies_count || 0} ad copies • 
                                                {deployment.images_count || 0} images • 
                                                {deployment.videos_count || 0} videos
                                            </p>
                                        </div>
                                    </div>
                                    <span className={`px-3 py-1 rounded-full text-sm font-medium ${getStatusColor(deployment.status)}`}>
                                        {getStatusIcon(deployment.status)} {deployment.status}
                                    </span>
                                </div>
                                
                                {deployment.error_message && (
                                    <div className="mt-3 p-3 bg-red-50 rounded-lg">
                                        <p className="text-sm text-red-700">{deployment.error_message}</p>
                                    </div>
                                )}
                                
                                {deployment.deployed_at && (
                                    <p className="mt-3 text-xs text-gray-500">
                                        Deployed at: {new Date(deployment.deployed_at).toLocaleString()}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                    
                    {/* Error Details */}
                    {overallStatus === 'failed' && (
                        <div className="mt-6 bg-red-50 border border-red-200 rounded-lg p-6">
                            <h3 className="text-lg font-semibold text-red-800 mb-2">Deployment Issues</h3>
                            <p className="text-red-700 mb-4">Some platforms encountered errors during deployment.</p>
                            <div className="mt-4">
                                <Link
                                    href={`/campaigns/${campaign.id}/strategies`}
                                    className="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700"
                                >
                                    Review Campaign →
                                </Link>
                            </div>
                        </div>
                    )}
                    
                    {/* Success Actions */}
                    {overallStatus === 'completed' && (
                        <div className="mt-6 bg-green-50 border border-green-200 rounded-lg p-6">
                            <h3 className="text-lg font-semibold text-green-800 mb-2">🎉 Deployment Complete!</h3>
                            <p className="text-green-700 mb-4">
                                Your campaign has been successfully deployed. It may take a few hours for ads to start serving.
                            </p>
                            <div className="flex gap-4">
                                <Link
                                    href="/dashboard"
                                    className="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                                >
                                    View Dashboard →
                                </Link>
                                <Link
                                    href="/campaigns/create"
                                    className="inline-flex items-center px-4 py-2 bg-white text-green-700 border border-green-300 rounded-lg hover:bg-green-50"
                                >
                                    Create Another Campaign
                                </Link>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

/**
 * DeploymentProgress - Visual progress indicator
 */
function DeploymentProgress({ job }) {
    const stages = [
        { id: 'init', name: 'Initializing', description: 'Preparing deployment' },
        { id: 'google', name: 'Google Ads', description: 'Creating Google campaigns' },
        { id: 'facebook', name: 'Facebook Ads', description: 'Creating Facebook campaigns' },
        { id: 'verify', name: 'Verification', description: 'Verifying deployment' },
        { id: 'complete', name: 'Complete', description: 'All done!' },
    ];
    
    const getCurrentStage = () => {
        if (!job || job.status === 'pending') return 0;
        if (job.status === 'failed') return -1;
        if (job.status === 'completed') return stages.length;
        
        // Determine current stage based on events
        if (job.facebook_status === 'processing') return 2;
        if (job.google_status === 'processing') return 1;
        if (job.google_status === 'completed' && job.facebook_status === 'completed') return 3;
        return 1;
    };
    
    const currentStage = getCurrentStage();
    
    return (
        <div className="space-y-4">
            <div className="flex items-center justify-between">
                {stages.map((stage, index) => (
                    <React.Fragment key={stage.id}>
                        <div className="flex flex-col items-center">
                            <div 
                                className={`
                                    w-10 h-10 rounded-full flex items-center justify-center text-sm font-medium
                                    ${index < currentStage 
                                        ? 'bg-green-500 text-white' 
                                        : index === currentStage && job?.status === 'processing'
                                            ? 'bg-blue-500 text-white animate-pulse'
                                            : job?.status === 'failed' && index === currentStage
                                                ? 'bg-red-500 text-white'
                                                : 'bg-gray-200 text-gray-500'
                                    }
                                `}
                            >
                                {index < currentStage ? '✓' : index + 1}
                            </div>
                            <span className="mt-2 text-xs text-gray-600 text-center max-w-[80px]">
                                {stage.name}
                            </span>
                        </div>
                        {index < stages.length - 1 && (
                            <div 
                                className={`flex-1 h-1 mx-2 ${
                                    index < currentStage ? 'bg-green-500' : 'bg-gray-200'
                                }`}
                            />
                        )}
                    </React.Fragment>
                ))}
            </div>
            
            {job?.status === 'processing' && (
                <div className="text-center">
                    <div className="inline-flex items-center px-4 py-2 bg-blue-50 text-blue-700 rounded-full">
                        <svg className="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                        </svg>
                        Deploying your campaign...
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * PlatformStatusCard - Shows status for a specific platform
 */
function PlatformStatusCard({ platform, status, entities, icon }) {
    const getStatusStyles = (status) => {
        const styles = {
            pending: 'border-gray-200 bg-gray-50',
            processing: 'border-blue-200 bg-blue-50',
            completed: 'border-green-200 bg-green-50',
            failed: 'border-red-200 bg-red-50',
        };
        return styles[status] || styles.pending;
    };
    
    return (
        <div className={`rounded-lg border-2 p-4 ${getStatusStyles(status)}`}>
            <div className="flex items-center justify-between mb-3">
                <div className="flex items-center space-x-2">
                    <span className="text-2xl">{icon}</span>
                    <h3 className="font-semibold text-gray-900">{platform}</h3>
                </div>
                <StatusBadge status={status} />
            </div>
            
            {entities.length > 0 && (
                <div className="space-y-2">
                    {entities.map((entity, index) => (
                        <div key={index} className="flex items-center text-sm">
                            <span className={`w-2 h-2 rounded-full mr-2 ${
                                entity.status === 'created' ? 'bg-green-500' : 'bg-gray-300'
                            }`} />
                            <span className="text-gray-700">{entity.type}:</span>
                            <span className="ml-1 text-gray-900 font-medium">{entity.name || entity.id}</span>
                        </div>
                    ))}
                </div>
            )}
            
            {status === 'processing' && entities.length === 0 && (
                <div className="flex items-center text-sm text-blue-600">
                    <svg className="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                    </svg>
                    Creating campaigns...
                </div>
            )}
            
            {status === 'pending' && (
                <p className="text-sm text-gray-500">Waiting to start...</p>
            )}
        </div>
    );
}

/**
 * StatusBadge - Small status indicator
 */
function StatusBadge({ status }) {
    const styles = {
        pending: 'bg-gray-100 text-gray-600',
        processing: 'bg-blue-100 text-blue-700',
        completed: 'bg-green-100 text-green-700',
        failed: 'bg-red-100 text-red-700',
    };
    
    return (
        <span className={`px-2 py-1 text-xs font-medium rounded ${styles[status] || styles.pending}`}>
            {status?.charAt(0).toUpperCase() + status?.slice(1)}
        </span>
    );
}

/**
 * DeploymentTimeline - Shows chronological events
 */
function DeploymentTimeline({ events }) {
    if (events.length === 0) {
        return (
            <div className="text-center py-8 text-gray-500">
                <p>No events yet. Deployment will start shortly...</p>
            </div>
        );
    }
    
    const getEventIcon = (type) => {
        const icons = {
            'started': '🚀',
            'google.campaign.created': '📊',
            'google.adgroup.created': '📁',
            'google.ad.created': '📝',
            'facebook.campaign.created': '📊',
            'facebook.adset.created': '📁',
            'facebook.ad.created': '📝',
            'completed': '✅',
            'failed': '❌',
            'warning': '⚠️',
        };
        return icons[type] || '📌';
    };
    
    return (
        <div className="flow-root">
            <ul className="-mb-8">
                {events.map((event, index) => (
                    <li key={index}>
                        <div className="relative pb-8">
                            {index !== events.length - 1 && (
                                <span 
                                    className="absolute top-4 left-4 -ml-px h-full w-0.5 bg-gray-200"
                                    aria-hidden="true"
                                />
                            )}
                            <div className="relative flex space-x-3">
                                <div>
                                    <span className="h-8 w-8 rounded-full flex items-center justify-center ring-8 ring-white bg-gray-100">
                                        {getEventIcon(event.type)}
                                    </span>
                                </div>
                                <div className="flex-1 min-w-0 pt-1.5">
                                    <p className="text-sm text-gray-900 font-medium">
                                        {event.message}
                                    </p>
                                    <p className="mt-0.5 text-xs text-gray-500">
                                        {new Date(event.timestamp).toLocaleTimeString()}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
