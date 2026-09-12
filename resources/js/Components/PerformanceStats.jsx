import React from 'react';
import { money, count, percent } from '@/utils/format';
import { useCurrency } from '@/hooks/useCurrency';

const StatCard = ({ title, value, change }) => (
    <div className="bg-white p-5 rounded-lg shadow-sm border border-gray-100">
        <h3 className="text-xs font-medium text-gray-500 uppercase tracking-wider">{title}</h3>
        <p className="mt-1 text-2xl font-semibold text-gray-900">{value}</p>
        {change && (
            <p className={`mt-1 text-sm ${change.type === 'increase' ? 'text-green-600' : 'text-red-600'}`}>
                {change.value} {change.type === 'increase' ? 'increase' : 'decrease'}
            </p>
        )}
    </div>
);

const PerformanceStats = ({ stats }) => {
    // Nine of seventeen customers are on AUD: "$1,234" was their spend
    // rendered as US dollars on their own dashboard.
    const currency = useCurrency();

    // Handle null/undefined stats gracefully
    if (!stats) {
        return (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <StatCard title="Total Spend" value={money(0, currency, { maximumFractionDigits: 0 })} />
                <StatCard title="Total Clicks" value="0" />
                <StatCard title="Average CTR" value={percent(0)} />
                <StatCard title="Average CPA" value={money(0, currency)} />
            </div>
        );
    }

    return (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <StatCard title="Total Spend" value={money(stats.total_spend ?? 0, currency, { maximumFractionDigits: 0 })} />
            <StatCard title="Total Clicks" value={count(stats.total_clicks ?? 0)} />
            <StatCard title="Average CTR" value={percent(stats.average_ctr ?? 0, { digits: 2 })} />
            <StatCard title="Average CPA" value={money(stats.average_cpa ?? 0, currency)} />
        </div>
    );
};

export default PerformanceStats;
