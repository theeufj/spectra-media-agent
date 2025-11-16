
import React from 'react';
import Card from './Card';

const PerformanceSummary = ({ data }) => {
    // Mock data structure: { total_spend: 12000, total_revenue: 36000, overall_roas: 3.0 }
    const summaryData = data || {
        total_spend: 12500,
        total_revenue: 37500,
        overall_roas: 3.0,
        total_conversions: 250,
    };

    return (
        <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
            <Card>
                <h4 className="text-sm font-medium text-gray-500">Total Spend</h4>
                <p className="text-3xl font-bold">${summaryData.total_spend.toLocaleString()}</p>
            </Card>
            <Card>
                <h4 className="text-sm font-medium text-gray-500">Total Revenue</h4>
                <p className="text-3xl font-bold">${summaryData.total_revenue.toLocaleString()}</p>
            </Card>
            <Card>
                <h4 className="text-sm font-medium text-gray-500">Overall ROAS</h4>
                <p className="text-3xl font-bold">{summaryData.overall_roas.toFixed(2)}x</p>
            </Card>
            <Card>
                <h4 className="text-sm font-medium text-gray-500">Total Conversions</h4>
                <p className="text-3xl font-bold">{summaryData.total_conversions.toLocaleString()}</p>
            </Card>
        </div>
    );
};

export default PerformanceSummary;
