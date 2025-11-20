import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import SideNav from './SideNav';
import { useState } from 'react';
import { Line, Bar, Doughnut } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler
} from 'chart.js';

// Register Chart.js components
ChartJS.register(
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Title,
    Tooltip,
    Legend,
    Filler
);

const MetricCard = ({ title, value, subtitle, icon, trend }) => (
    <div className="bg-white rounded-lg shadow p-6">
        <div className="flex items-center justify-between">
            <div>
                <p className="text-sm font-medium text-gray-600">{title}</p>
                <p className="text-3xl font-bold text-gray-900 mt-2">{value}</p>
                {subtitle && <p className="text-sm text-gray-500 mt-1">{subtitle}</p>}
            </div>
            {icon && (
                <div className="text-indigo-600">
                    {icon}
                </div>
            )}
        </div>
        {trend && (
            <div className={`mt-4 flex items-center text-sm ${trend > 0 ? 'text-green-600' : 'text-red-600'}`}>
                <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                    {trend > 0 ? (
                        <path fillRule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clipRule="evenodd" />
                    ) : (
                        <path fillRule="evenodd" d="M14.707 10.293a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L9 12.586V5a1 1 0 012 0v7.586l2.293-2.293a1 1 0 011.414 0z" clipRule="evenodd" />
                    )}
                </svg>
                {Math.abs(trend)}% vs last period
            </div>
        )}
    </div>
);

const ErrorTable = ({ errors }) => (
    <div className="bg-white rounded-lg shadow overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
            <h3 className="text-lg font-semibold text-gray-900">Common Errors</h3>
        </div>
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                    <tr>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Error Type
                        </th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Count
                        </th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Platforms
                        </th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Sample Message
                        </th>
                    </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                    {errors && errors.length > 0 ? (
                        errors.map((error, index) => (
                            <tr key={index} className="hover:bg-gray-50">
                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    {error.type}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                        {error.count}
                                    </span>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {error.platforms && error.platforms.join(', ')}
                                </td>
                                <td className="px-6 py-4 text-sm text-gray-500 max-w-md truncate">
                                    {error.sample_message}
                                </td>
                            </tr>
                        ))
                    ) : (
                        <tr>
                            <td colSpan="4" className="px-6 py-4 text-center text-sm text-gray-500">
                                No errors found
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    </div>
);

const PlatformStatsTable = ({ platforms }) => (
    <div className="bg-white rounded-lg shadow overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
            <h3 className="text-lg font-semibold text-gray-900">Platform Performance</h3>
        </div>
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-gray-200">
                <thead className="bg-gray-50">
                    <tr>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Platform
                        </th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Total
                        </th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Successful
                        </th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Failed
                        </th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Success Rate
                        </th>
                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Avg Time (s)
                        </th>
                    </tr>
                </thead>
                <tbody className="bg-white divide-y divide-gray-200">
                    {platforms && platforms.length > 0 ? (
                        platforms.map((platform, index) => (
                            <tr key={index} className="hover:bg-gray-50">
                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    {platform.platform}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {platform.total}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-green-600">
                                    {platform.successful}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-red-600">
                                    {platform.failed}
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm">
                                    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                        platform.success_rate >= 90 ? 'bg-green-100 text-green-800' :
                                        platform.success_rate >= 70 ? 'bg-yellow-100 text-yellow-800' :
                                        'bg-red-100 text-red-800'
                                    }`}>
                                        {platform.success_rate}%
                                    </span>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    {platform.avg_execution_time}s
                                </td>
                            </tr>
                        ))
                    ) : (
                        <tr>
                            <td colSpan="6" className="px-6 py-4 text-center text-sm text-gray-500">
                                No platform data available
                            </td>
                        </tr>
                    )}
                </tbody>
            </table>
        </div>
    </div>
);

export default function ExecutionMetrics({ auth }) {
    const { metrics, filters } = usePage().props;
    const [dateRange, setDateRange] = useState({
        start: filters.start_date,
        end: filters.end_date
    });

    // Time series chart data
    const timeSeriesData = {
        labels: metrics.time_series?.map(d => d.date) || [],
        datasets: [
            {
                label: 'Success Rate (%)',
                data: metrics.time_series?.map(d => d.success_rate) || [],
                borderColor: 'rgb(34, 197, 94)',
                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y'
            },
            {
                label: 'Avg Execution Time (s)',
                data: metrics.time_series?.map(d => d.avg_execution_time) || [],
                borderColor: 'rgb(99, 102, 241)',
                backgroundColor: 'rgba(99, 102, 241, 0.1)',
                tension: 0.4,
                fill: true,
                yAxisID: 'y1'
            }
        ]
    };

    const timeSeriesOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false,
        },
        plugins: {
            legend: {
                position: 'top',
            },
            title: {
                display: true,
                text: 'Execution Trends Over Time'
            }
        },
        scales: {
            y: {
                type: 'linear',
                display: true,
                position: 'left',
                title: {
                    display: true,
                    text: 'Success Rate (%)'
                }
            },
            y1: {
                type: 'linear',
                display: true,
                position: 'right',
                title: {
                    display: true,
                    text: 'Avg Time (s)'
                },
                grid: {
                    drawOnChartArea: false,
                }
            }
        }
    };

    // Platform comparison chart
    const platformData = {
        labels: metrics.platform_stats?.map(p => p.platform) || [],
        datasets: [
            {
                label: 'Successful',
                data: metrics.platform_stats?.map(p => p.successful) || [],
                backgroundColor: 'rgba(34, 197, 94, 0.8)',
            },
            {
                label: 'Failed',
                data: metrics.platform_stats?.map(p => p.failed) || [],
                backgroundColor: 'rgba(239, 68, 68, 0.8)',
            }
        ]
    };

    const platformOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            },
            title: {
                display: true,
                text: 'Platform Success vs Failure'
            }
        },
        scales: {
            x: {
                stacked: true,
            },
            y: {
                stacked: true,
            }
        }
    };

    // Feature adoption donut chart
    const adoptionData = {
        labels: ['Agent Executions', 'Legacy Executions'],
        datasets: [{
            data: [
                metrics.feature_adoption?.agent_executions || 0,
                metrics.feature_adoption?.legacy_executions || 0
            ],
            backgroundColor: [
                'rgba(99, 102, 241, 0.8)',
                'rgba(156, 163, 175, 0.8)'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    };

    const adoptionOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
            },
            title: {
                display: true,
                text: `Feature Adoption - ${metrics.feature_adoption?.adoption_rate || 0}% Using Agents`
            }
        }
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Admin - Execution Metrics</h2>}
        >
            <Head title="Admin - Execution Metrics" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 py-12">
                    <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                        {/* Overview Metrics */}
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                            <MetricCard
                                title="Total Executions"
                                value={metrics.overview?.total_executions || 0}
                                subtitle={`${metrics.overview?.successful_executions || 0} successful, ${metrics.overview?.failed_executions || 0} failed`}
                                icon={
                                    <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                }
                            />
                            <MetricCard
                                title="Success Rate"
                                value={`${metrics.overview?.success_rate || 0}%`}
                                subtitle="Overall execution success"
                                icon={
                                    <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                    </svg>
                                }
                            />
                            <MetricCard
                                title="Avg Execution Time"
                                value={`${metrics.overview?.avg_execution_time || 0}s`}
                                subtitle="Average time per deployment"
                                icon={
                                    <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                }
                            />
                            <MetricCard
                                title="Error Recovery"
                                value={metrics.overview?.error_recovery_attempts || 0}
                                subtitle="AI recovery attempts made"
                                icon={
                                    <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                }
                            />
                            <MetricCard
                                title="Budget Accuracy"
                                value={`${metrics.budget?.avg_accuracy || 0}%`}
                                subtitle={`${metrics.budget?.total_campaigns || 0} campaigns analyzed`}
                                icon={
                                    <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                }
                            />
                            <MetricCard
                                title="AI Implementation Rate"
                                value={`${metrics.ai_quality?.avg_implementation_rate || 0}%`}
                                subtitle={`${metrics.ai_quality?.total_analyzed || 0} strategies analyzed`}
                                icon={
                                    <svg className="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                                    </svg>
                                }
                            />
                        </div>

                        {/* Charts Row */}
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                            <div className="bg-white rounded-lg shadow p-6">
                                <div style={{ height: '300px' }}>
                                    <Line data={timeSeriesData} options={timeSeriesOptions} />
                                </div>
                            </div>
                            <div className="bg-white rounded-lg shadow p-6">
                                <div style={{ height: '300px' }}>
                                    <Bar data={platformData} options={platformOptions} />
                                </div>
                            </div>
                        </div>

                        {/* Feature Adoption Chart */}
                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                            <div className="bg-white rounded-lg shadow p-6">
                                <div style={{ height: '300px' }}>
                                    <Doughnut data={adoptionData} options={adoptionOptions} />
                                </div>
                            </div>
                            <div className="lg:col-span-2">
                                <PlatformStatsTable platforms={metrics.platform_stats} />
                            </div>
                        </div>

                        {/* Errors Table */}
                        <div className="mb-8">
                            <ErrorTable errors={metrics.common_errors} />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
