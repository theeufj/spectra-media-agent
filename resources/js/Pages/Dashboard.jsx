import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import PerformanceSummary from '@/Components/PerformanceSummary';
import CampaignOverviewChart from '@/Components/CampaignOverviewChart';
import ActionableInsights from '@/Components/ActionableInsights';
import RecentActivityFeed from '@/Components/RecentActivityFeed';
import DateRangePicker from '@/Components/DateRangePicker';

export default function Dashboard({ auth, dashboardData }) {
    // In a real application, dashboardData would be passed from the controller.
    // We'll use a mock structure for now if it's not provided.
    const mockData = {
        portfolio_performance: {
            total_spend: 12500,
            total_revenue: 37500,
            overall_roas: 3.0,
            total_conversions: 250,
        },
        actionable_insights: {
            pending_recommendations: 2,
            unresolved_conflicts: 1,
        },
        // ... other data points
    };

    const data = dashboardData || mockData;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex justify-between items-center">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Dashboard
                    </h2>
                    <DateRangePicker />
                </div>
            }
        >
            <Head title="Dashboard" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <PerformanceSummary data={data.portfolio_performance} />

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <div className="lg:col-span-2">
                            <CampaignOverviewChart />
                        </div>
                        <div>
                            <ActionableInsights data={data.actionable_insights} />
                        </div>
                    </div>

                    <RecentActivityFeed />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
