import React, { useState, useEffect } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { usePolling } from '@/hooks/usePolling';

/**
 * DeploymentStatus - Shows real-time deployment progress and status
 */
export default function DeploymentStatus({ campaign, deployments: initialDeployments }) {
    const { auth } = usePage().props;
    const [deployments, setDeployments] = useState(initialDeployments || []);
    const [overallProgress, setOverallProgress] = useState(0);
    // 'verified' is the state VerifyDeployment promotes 'deployed' to once it
    // confirms the objects exist on the platform — success, terminally so.
    // 'deploy_unverified' is its "couldn't confirm" outcome, and
    // 'skipped_plan' means the platform isn't in the user's plan: terminal.
    const isLive = (d) => d.status === 'deployed' || d.status === 'verified';
    const isTerminal = (d) => isLive(d) || ['failed', 'deploy_unverified', 'skipped_plan'].includes(d.status);
    // Stop once every strategy has reached a terminal state.
    const allComplete = deployments.length > 0 && deployments.every(isTerminal);

    // Cap the watch at 15 minutes: a deploy that long has stalled, and an
    // uncapped 3-second poll ran forever on any strategy that never reached a
    // terminal state.
    const pollStartRef = React.useRef(Date.now());
    const [pollTimedOut, setPollTimedOut] = useState(false);

    const { data: polled } = usePolling(
        `/api/campaigns/${campaign.id}/deployment-status`,
        {
            interval: 3000,
            enabled: !allComplete && !pollTimedOut,
            until: (data) => {
                if (Date.now() - pollStartRef.current > 15 * 60 * 1000) {
                    setPollTimedOut(true);

                    return true;
                }

                return data?.is_complete === true;
            },
            immediate: false,
        }
    );

    useEffect(() => {
        if (!polled) return;
        setDeployments(polled.deployments || []);
        setOverallProgress(polled.overall_progress || 0);
    }, [polled]);

    // Derive progress locally too, so Echo-pushed updates move the bar without
    // waiting for the next poll. Progress means "how far through the process",
    // so failed counts as terminal — counting only successes froze the bar at
    // 50% forever when one of two platforms failed.
    useEffect(() => {
        if (deployments.length === 0) return;
        const terminalCount = deployments.filter(isTerminal).length;
        setOverallProgress(Math.round((terminalCount / deployments.length) * 100));
    }, [deployments]);


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
        // No strategies yet is "pending", not "completed" — [].every() is true,
        // and this used to greet an empty campaign with "Deployment Complete!".
        if (deployments.length === 0) return 'pending';

        const hasFailure = deployments.some(d => d.status === 'failed');
        const stillRunning = deployments.some(d => !isTerminal(d));

        // While anything is still running, report progress — one early platform
        // failure shouldn't label the whole deploy "failed" mid-flight.
        if (stillRunning) return 'processing';
        if (hasFailure) return 'failed';
        if (deployments.some(isLive)) return 'completed';
        // Everything terminal, nothing live, nothing failed: unverified or
        // plan-skipped rows only. "Pending" here read as stuck-forever.
        return 'attention';
    };
    
    const getStatusColor = (status) => {
        const colors = {
            pending: 'bg-yellow-100 text-yellow-800',
            deploying: 'bg-blue-100 text-blue-800',
            processing: 'bg-blue-100 text-blue-800',
            deployed: 'bg-green-100 text-green-800',
            verified: 'bg-green-100 text-green-800',
            deploy_unverified: 'bg-yellow-100 text-yellow-800',
            skipped_plan: 'bg-gray-200 text-gray-700',
            completed: 'bg-green-100 text-green-800',
            failed: 'bg-red-100 text-red-800',
            attention: 'bg-yellow-100 text-yellow-800',
        };
        return colors[status] || 'bg-gray-100 text-gray-800';
    };
    
    const getStatusIcon = (status) => {
        const icons = {
            pending: '⏳',
            deploying: '🔄',
            processing: '🔄',
            deployed: '✅',
            verified: '✅',
            deploy_unverified: '⚠️',
            skipped_plan: '🔒',
            completed: '✅',
            failed: '❌',
            attention: '⚠️',
        };
        return icons[status] || '📋';
    };
    
    const overallStatus = getOverallStatus();
    
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-3">
                    <div>
                        <Link 
                            href={`/campaigns/${campaign.id}/strategies`}
                            className="text-sm text-brand-dark hover:text-brand-darker mb-1 block"
                        >
                            ← Back to Campaign
                        </Link>
                        <h2 className="font-semibold text-lg sm:text-xl text-gray-800 leading-tight">
                            Deployment Status: {campaign.name}
                        </h2>
                    </div>
                    <span className={`px-3 py-1 rounded-full text-xs sm:text-sm font-medium self-start flex-shrink-0 ${getStatusColor(overallStatus)}`}>
                        {getStatusIcon(overallStatus)} {overallStatus?.charAt(0).toUpperCase() + overallStatus?.slice(1)}
                    </span>
                </div>
            }
        >
            <Head title={`Deployment - ${campaign.name}`} />
            
            <div className="py-12">
                <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
                    {pollTimedOut && !allComplete && (
                        <div className="mb-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-800">
                            This is taking longer than expected — we've stopped auto-refreshing.
                            Reload the page to check again, or contact support if it stays stuck.
                        </div>
                    )}

                    {/* Overall Progress */}
                    <div className="bg-white rounded-lg shadow-md p-6 mb-6">
                        <div className="flex items-center justify-between mb-4">
                            <h3 className="text-lg font-semibold text-gray-900">Overall Progress</h3>
                            <span className="text-2xl font-bold text-brand-dark">{overallProgress}%</span>
                        </div>
                        <div className="w-full bg-gray-200 rounded-full h-3">
                            <div 
                                className="bg-gradient-to-r from-brand-primary to-purple-500 h-3 rounded-full transition-all duration-500"
                                style={{ width: `${overallProgress}%` }}
                            />
                        </div>
                    </div>
                    
                    {/* Platform Status Cards */}
                    <div className="grid grid-cols-1 gap-4 mb-6">
                        {deployments.map((deployment) => (
                            <div 
                                key={deployment.id}
                                className={`bg-white rounded-lg shadow-md p-4 sm:p-6 border-l-4 ${
                                    deployment.status === 'deployed' ? 'border-green-500' :
                                    deployment.status === 'failed' ? 'border-red-500' :
                                    deployment.status === 'deploying' ? 'border-blue-500' :
                                    'border-gray-300'
                                }`}
                            >
                                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                    <div className="flex items-center space-x-2 sm:space-x-3">
                                        <span className="text-xl sm:text-2xl">
                                            {deployment.platform?.toLowerCase().includes('google') ? '🔍' : 
                                             deployment.platform?.toLowerCase().includes('facebook') ? '👥' :
                                             deployment.platform?.toLowerCase().includes('microsoft') || deployment.platform?.toLowerCase().includes('bing') ? '🪟' :
                                             deployment.platform?.toLowerCase().includes('linkedin') ? '💼' : '📢'}
                                        </span>
                                        <div>
                                            <h4 className="font-semibold text-sm sm:text-base text-gray-900">{deployment.platform}</h4>
                                            <p className="text-xs sm:text-sm text-gray-500">
                                                {deployment.ad_copies_count || 0} ad copies • 
                                                {deployment.images_count || 0} images • 
                                                {deployment.videos_count || 0} videos
                                            </p>
                                        </div>
                                    </div>
                                    <span className={`px-2 sm:px-3 py-1 rounded-full text-xs sm:text-sm font-medium whitespace-nowrap self-start flex-shrink-0 ${getStatusColor(deployment.status)}`}>
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
                            <p className="text-red-700 mb-4">
                                Some platforms encountered errors during deployment. You can retry now —
                                already-deployed platforms are skipped automatically — or review the campaign first.
                            </p>
                            <div className="mt-4 flex gap-3 flex-wrap">
                                <button
                                    type="button"
                                    onClick={() => router.post(route('deployment.deploy'), { campaign_id: campaign.id })}
                                    className="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700"
                                >
                                    Retry Deployment
                                </button>
                                <Link
                                    href={`/campaigns/${campaign.id}/strategies`}
                                    className="inline-flex items-center px-4 py-2 bg-white text-red-700 border border-red-300 rounded-lg hover:bg-red-50"
                                >
                                    Review Campaign →
                                </Link>
                            </div>
                        </div>
                    )}

                    {/* Needs attention: nothing failed outright, but nothing is confirmed live either */}
                    {overallStatus === 'attention' && (
                        <div className="mt-6 bg-yellow-50 border border-yellow-200 rounded-lg p-6">
                            <h3 className="text-lg font-semibold text-yellow-800 mb-2">⚠️ Needs a closer look</h3>
                            <p className="text-yellow-700">
                                Deployment finished, but we couldn't confirm the ads on the platform yet — or a
                                platform wasn't included in your plan. Each platform's card above explains its state,
                                and our team has been alerted where confirmation is pending.
                            </p>
                        </div>
                    )}
                    
                    {/* Success Actions */}
                    {overallStatus === 'completed' && (
                        <div className="mt-6 bg-green-50 border border-green-200 rounded-lg p-6">
                            <h3 className="text-lg font-semibold text-green-800 mb-2">🎉 Deployment Complete!</h3>
                            <p className="text-green-700 mb-4">
                                Your campaign has been successfully deployed. Ads are scheduled to begin serving
                                from tomorrow — campaigns start the day after deployment.
                            </p>
                            <div className="flex gap-4">
                                <Link
                                    href="/dashboard"
                                    className="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700"
                                >
                                    View Dashboard →
                                </Link>
                                <Link
                                    href="/campaigns/wizard"
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
