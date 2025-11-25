import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import axios from 'axios';

// We'll create these components in the next steps
import CampaignSelector from '@/Components/CampaignSelector';
import PerformanceStats from '@/Components/PerformanceStats';
import PerformanceChart from '@/Components/PerformanceChart';
import NoCampaigns from '@/Components/NoCampaigns';
import WaitingForData from '@/Components/WaitingForData';
import DateRangePicker from '@/Components/DateRangePicker';

export default function Dashboard({ auth }) {
    const { campaigns, defaultCampaign, usageStats } = usePage().props;
    const [selectedCampaign, setSelectedCampaign] = useState(defaultCampaign);
    const [performanceData, setPerformanceData] = useState(null);
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
        }
    }, [selectedCampaign, dateRange]);

    const renderContent = () => {
        if (campaigns.length === 0) {
            return <NoCampaigns />;
        }

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
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Performance Dashboard
                    </h2>
                    <div className="flex items-center space-x-4">
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

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
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
                                        <div className="bg-indigo-600 h-2.5 rounded-full" style={{ width: `${Math.min((usageStats.free_generations_used / 5) * 100, 100)}%` }}></div>
                                    </div>
                                </div>
                                {/* CRO Audits Meter */}
                                <div>
                                    <div className="flex justify-between mb-1">
                                        <span className="text-sm font-medium text-gray-700">CRO Audits</span>
                                        <span className="text-sm font-medium text-gray-700">{usageStats.cro_audits_used} / 3</span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2.5">
                                        <div className="bg-indigo-600 h-2.5 rounded-full" style={{ width: `${Math.min((usageStats.cro_audits_used / 3) * 100, 100)}%` }}></div>
                                    </div>
                                </div>
                            </div>
                            <div className="mt-4 text-center">
                                <a href={route('subscription.pricing')} className="text-indigo-600 hover:text-indigo-900 font-medium text-sm">
                                    Upgrade to Unlimited &rarr;
                                </a>
                            </div>
                        </div>
                    )}

                    {renderContent()}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
