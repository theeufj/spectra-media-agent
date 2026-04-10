import React from 'react';

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
    // Handle null/undefined stats gracefully
    if (!stats) {
        return (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <StatCard title="Total Spend" value="$0" />
                <StatCard title="Total Clicks" value="0" />
                <StatCard title="Average CTR" value="0%" />
                <StatCard title="Average CPA" value="$0" />
            </div>
        );
    }
    
    return (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <StatCard title="Total Spend" value={`$${(stats.total_spend ?? 0).toLocaleString()}`} />
            <StatCard title="Total Clicks" value={(stats.total_clicks ?? 0).toLocaleString()} />
            <StatCard title="Average CTR" value={`${(stats.average_ctr ?? 0).toFixed(2)}%`} />
            <StatCard title="Average CPA" value={`$${(stats.average_cpa ?? 0).toFixed(2)}`} />
        </div>
    );
};

export default PerformanceStats;
