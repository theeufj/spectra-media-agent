import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import axios from 'axios';

// Dashboard components
import CampaignSelector from '@/Components/CampaignSelector';
import PerformanceStats from '@/Components/PerformanceStats';
import PerformanceChart from '@/Components/PerformanceChart';
import NoCampaigns from '@/Components/NoCampaigns';
import WaitingForData from '@/Components/WaitingForData';
import DateRangePicker from '@/Components/DateRangePicker';
import SetupProgressNav from '@/Components/SetupProgressNav';
import QuickActions, { PendingTasks, CampaignHealthAlerts } from '@/Components/QuickActions';
import AgentActivityFeed from '@/Components/AgentActivityFeed';

export default function Dashboard({ auth }) {
    const { campaigns, defaultCampaign, usageStats, pendingTasks, healthAlerts, agentActivities, flash } = usePage().props;
    const activeCustomer = auth.user?.active_customer;
    const [selectedCampaign, setSelectedCampaign] = useState(defaultCampaign);
    const [performanceData, setPerformanceData] = useState(null);
    const [showFlash, setShowFlash] = useState(!!flash?.success);
    const [loading, setLoading] = useState(true);
    const [dateRange, setDateRange] = useState({
        start: new Date(new Date().setDate(new Date().getDate() - 30)),
        end: new Date(),
    });

    useEffect(() => {
        if (selectedCampaign) {
            setLoading(true);
            const params = {
                start_date: dateRange.start.toISOString().split('T')[0],
                end_date: dateRange.end.toISOString().split('T')[0],
            };
            axios.get(route('api.campaigns.performance', { campaign: selectedCampaign.id, ...params }))
                .then(response => {
                    setPerformanceData(response.data);
                    setLoading(false);
                })
                .catch(error => {
                    console.error("Error fetching performance data:", error);
                    setLoading(false);
                });
        } else {
            setLoading(false);
        }
    }, [selectedCampaign, dateRange]);

    const renderPerformanceContent = () => {
        if (loading) {
            return <div className="p-6 bg-white rounded-lg shadow-md text-center">Loading performance data...</div>;
        }

        if (selectedCampaign && !performanceData) {
            return <WaitingForData />;
        }
        
        if (performanceData) {
            return (
                <div className="space-y-6">
                    <PerformanceStats stats={performanceData.summary} />
                    <PerformanceChart data={performanceData.daily_data} />
                </div>
            );
        }

        return null;
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Performance Dashboard
                    </h2>
                    <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-4">
                        {campaigns.length > 0 && (
                            <CampaignSelector
                                campaigns={campaigns}
                                selectedCampaign={selectedCampaign}
                                setSelectedCampaign={setSelectedCampaign}
                            />
                        )}
                        <DateRangePicker value={dateRange} onChange={setDateRange} />
                    </div>
                </div>
            }
        >
            <Head title="Performance Dashboard" />

            <div className="py-6 sm:py-12">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Setup Progress for New Users */}
                    <SetupProgressNav />

                    {/* Account ID Reference */}
                    {activeCustomer?.google_ads_customer_id && (
                        <div className="mb-4 flex items-center gap-2 text-sm text-gray-500">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0" />
                            </svg>
                            <span>Account ID: <span className="font-mono font-medium text-gray-700">{activeCustomer.google_ads_customer_id}</span></span>
                        </div>
                    )}

                    {/* Flash Message */}
                    {showFlash && flash?.success && (
                        <div className="mb-6 bg-green-50 border border-green-200 rounded-lg p-4 flex items-center justify-between">
                            <div className="flex items-center">
                                <svg className="w-5 h-5 text-green-500 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                </svg>
                                <p className="text-sm font-medium text-green-800">{flash.success}</p>
                            </div>
                            <button onClick={() => setShowFlash(false)} className="text-green-500 hover:text-green-700">
                                <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                </svg>
                            </button>
                        </div>
                    )}
                    
                    {/* Usage Meters for Free Tier */}
                    {usageStats && usageStats.subscription_status !== 'active' && (
                        <div className="mb-6 bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                            <h3 className="text-lg font-medium text-gray-900 mb-4">Free Tier Usage</h3>
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                {/* Image Generations Meter */}
                                <div>
                                    <div className="flex justify-between mb-1">
                                        <span className="text-sm font-medium text-gray-700">Image Generations</span>
                                        <span className="text-sm font-medium text-gray-700">{usageStats.free_generations_used} / 5</span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2.5">
                                        <div className="bg-flame-orange-600 h-2.5 rounded-full" style={{ width: `${Math.min((usageStats.free_generations_used / 5) * 100, 100)}%` }}></div>
                                    </div>
                                </div>
                                {/* CRO Audits Meter */}
                                <div>
                                    <div className="flex justify-between mb-1">
                                        <span className="text-sm font-medium text-gray-700">CRO Audits</span>
                                        <span className="text-sm font-medium text-gray-700">{usageStats.cro_audits_used} / 3</span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2.5">
                                        <div className="bg-flame-orange-600 h-2.5 rounded-full" style={{ width: `${Math.min((usageStats.cro_audits_used / 3) * 100, 100)}%` }}></div>
                                    </div>
                                </div>
                            </div>
                            <div className="mt-4 text-center">
                                <a href={route('subscription.pricing')} className="text-flame-orange-600 hover:text-flame-orange-900 font-medium text-sm">
                                    Upgrade to Unlimited &rarr;
                                </a>
                            </div>
                        </div>
                    )}

                    {/* Main Dashboard Grid */}
                    {campaigns.length === 0 ? (
                        <NoCampaigns />
                    ) : (
                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            {/* Left Column: Performance Data */}
                            <div className="lg:col-span-2 space-y-6">
                                {renderPerformanceContent()}
                            </div>
                            
                            {/* Right Column: Actions & Alerts */}
                            <div className="space-y-6">
                                <QuickActions />
                                <AgentActivityFeed
                                    initialActivities={agentActivities || []}
                                    campaignId={selectedCampaign?.id}
                                />
                                <PendingTasks tasks={pendingTasks || []} />
                                <CampaignHealthAlerts alerts={healthAlerts || []} />
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
