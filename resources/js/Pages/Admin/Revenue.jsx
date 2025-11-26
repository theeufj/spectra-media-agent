import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import SideNav from './SideNav';

const MetricCard = ({ title, value, subtitle, icon, trend, color = 'indigo' }) => {
    const colors = {
        indigo: 'bg-indigo-500',
        green: 'bg-green-500',
        blue: 'bg-blue-500',
        purple: 'bg-purple-500',
        orange: 'bg-orange-500',
        red: 'bg-red-500',
    };

    return (
        <div className="bg-white rounded-lg shadow p-6">
            <div className="flex items-center">
                <div className={`flex-shrink-0 p-3 rounded-lg ${colors[color]}`}>
                    {icon}
                </div>
                <div className="ml-4 flex-1">
                    <p className="text-sm font-medium text-gray-500">{title}</p>
                    <p className="text-2xl font-bold text-gray-900">{value}</p>
                    {subtitle && <p className="text-xs text-gray-400">{subtitle}</p>}
                </div>
                {trend !== undefined && (
                    <div className={`text-sm font-medium ${trend >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                        {trend >= 0 ? '↑' : '↓'} {Math.abs(trend)}%
                    </div>
                )}
            </div>
        </div>
    );
};

const RevenueChart = ({ data }) => {
    const maxRevenue = Math.max(...data.map(d => d.revenue), 1);
    
    return (
        <div className="bg-white rounded-lg shadow p-6">
            <h3 className="text-lg font-medium text-gray-900 mb-4">Monthly Revenue</h3>
            <div className="flex items-end justify-between h-48 space-x-2">
                {data.map((item, index) => (
                    <div key={index} className="flex flex-col items-center flex-1">
                        <div 
                            className="w-full bg-indigo-500 rounded-t transition-all duration-300 hover:bg-indigo-600"
                            style={{ height: `${(item.revenue / maxRevenue) * 100}%`, minHeight: item.revenue > 0 ? '4px' : '0' }}
                            title={`$${item.revenue.toLocaleString()}`}
                        />
                        <span className="text-xs text-gray-500 mt-2 transform -rotate-45 origin-left whitespace-nowrap">
                            {item.month.split(' ')[0]}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
};

const TransactionRow = ({ transaction, onRefund }) => (
    <tr className="hover:bg-gray-50">
        <td className="px-6 py-4 whitespace-nowrap">
            <div className="text-sm font-medium text-gray-900">{transaction.customer}</div>
            <div className="text-xs text-gray-500">{transaction.email}</div>
        </td>
        <td className="px-6 py-4 whitespace-nowrap">
            <span className={`text-sm font-medium ${transaction.status === 'succeeded' ? 'text-green-600' : 'text-gray-600'}`}>
                ${transaction.amount.toFixed(2)} {transaction.currency}
            </span>
        </td>
        <td className="px-6 py-4 whitespace-nowrap">
            <span className={`inline-flex px-2 py-1 text-xs font-medium rounded-full ${
                transaction.status === 'succeeded' ? 'bg-green-100 text-green-800' :
                transaction.status === 'pending' ? 'bg-yellow-100 text-yellow-800' :
                'bg-red-100 text-red-800'
            }`}>
                {transaction.status}
            </span>
        </td>
        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
            {transaction.description}
        </td>
        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
            {transaction.created}
        </td>
        <td className="px-6 py-4 whitespace-nowrap text-right text-sm">
            {transaction.receipt_url && (
                <a 
                    href={transaction.receipt_url} 
                    target="_blank" 
                    rel="noopener noreferrer"
                    className="text-indigo-600 hover:text-indigo-900 mr-3"
                >
                    Receipt
                </a>
            )}
            {transaction.status === 'succeeded' && (
                <button
                    onClick={() => onRefund(transaction.id)}
                    className="text-red-600 hover:text-red-900"
                >
                    Refund
                </button>
            )}
        </td>
    </tr>
);

export default function Revenue({ metrics, recentTransactions, subscriptionBreakdown, monthlyRevenue }) {
    const handleRefund = (chargeId) => {
        if (confirm('Are you sure you want to issue a full refund for this charge?')) {
            router.post(route('admin.revenue.refund', chargeId), {}, { preserveScroll: true });
        }
    };

    return (
        <AuthenticatedLayout
            header={<h2 className="text-xl font-semibold leading-tight text-gray-800">Revenue Dashboard</h2>}
        >
            <Head title="Revenue Dashboard" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8">
                    <div className="max-w-7xl mx-auto">
                        {/* Error Banner */}
                        {metrics.error && (
                            <div className="mb-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                                <div className="flex">
                                    <svg className="h-5 w-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                                    </svg>
                                    <div className="ml-3">
                                        <p className="text-sm text-yellow-700">{metrics.error}. Showing database data only.</p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Key Metrics */}
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                            <MetricCard
                                title="Monthly Recurring Revenue"
                                value={`$${metrics.mrr?.toLocaleString() || 0}`}
                                subtitle={`ARR: $${metrics.arr?.toLocaleString() || 0}`}
                                color="indigo"
                                icon={
                                    <svg className="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                }
                            />
                            <MetricCard
                                title="This Month"
                                value={`$${metrics.monthlyRevenue?.toLocaleString() || 0}`}
                                subtitle="Revenue collected"
                                color="green"
                                icon={
                                    <svg className="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                    </svg>
                                }
                            />
                            <MetricCard
                                title="Active Subscribers"
                                value={metrics.activeSubscribers || 0}
                                subtitle={`${metrics.churnRate || 0}% churn rate`}
                                color="blue"
                                icon={
                                    <svg className="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                    </svg>
                                }
                            />
                            <MetricCard
                                title="Conversion Rate"
                                value={`${metrics.conversionRate || 0}%`}
                                subtitle={`${metrics.paidUsers || 0} paid / ${metrics.totalUsers || 0} total`}
                                color="purple"
                                icon={
                                    <svg className="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                                    </svg>
                                }
                            />
                        </div>

                        {/* Charts Row */}
                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                            <div className="lg:col-span-2">
                                <RevenueChart data={monthlyRevenue} />
                            </div>
                            
                            {/* Subscription Breakdown */}
                            <div className="bg-white rounded-lg shadow p-6">
                                <h3 className="text-lg font-medium text-gray-900 mb-4">Plan Breakdown</h3>
                                {subscriptionBreakdown.length > 0 ? (
                                    <div className="space-y-4">
                                        {subscriptionBreakdown.map((plan, index) => (
                                            <div key={index} className="flex items-center justify-between">
                                                <div>
                                                    <p className="font-medium text-gray-900">{plan.name}</p>
                                                    <p className="text-sm text-gray-500">${plan.price}/mo × {plan.count}</p>
                                                </div>
                                                <p className="text-lg font-bold text-gray-900">${plan.revenue.toFixed(2)}</p>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-gray-500 text-center py-8">No active subscriptions</p>
                                )}
                                
                                {/* User Distribution */}
                                <div className="mt-6 pt-6 border-t border-gray-200">
                                    <h4 className="text-sm font-medium text-gray-700 mb-3">User Distribution</h4>
                                    <div className="flex items-center">
                                        <div 
                                            className="h-4 bg-indigo-500 rounded-l"
                                            style={{ width: `${metrics.conversionRate || 0}%` }}
                                        />
                                        <div 
                                            className="h-4 bg-gray-200 rounded-r"
                                            style={{ width: `${100 - (metrics.conversionRate || 0)}%` }}
                                        />
                                    </div>
                                    <div className="flex justify-between mt-2 text-xs text-gray-500">
                                        <span>Paid: {metrics.paidUsers || 0}</span>
                                        <span>Free: {metrics.freeUsers || 0}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Recent Transactions */}
                        <div className="bg-white rounded-lg shadow overflow-hidden">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h3 className="text-lg font-medium text-gray-900">Recent Transactions</h3>
                            </div>
                            {recentTransactions.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                                <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200">
                                            {recentTransactions.map((transaction) => (
                                                <TransactionRow 
                                                    key={transaction.id} 
                                                    transaction={transaction} 
                                                    onRefund={handleRefund}
                                                />
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="px-6 py-12 text-center text-gray-500">
                                    No transactions found
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
