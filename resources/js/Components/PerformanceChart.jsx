
import React from 'react';
import Card from './Card';

// In a real application, you would use a charting library like Chart.js or Recharts.
// This is a simplified placeholder.
const PerformanceChart = ({ data }) => {
    // Mock data structure: [{ date: '2025-01-01', roas: 2.5, spend: 500 }, ...]
    const chartData = data || [
        { date: '2025-10-01', roas: 2.8, spend: 550 },
        { date: '2025-10-02', roas: 3.1, spend: 600 },
        { date: '2025-10-03', roas: 2.9, spend: 580 },
        { date: '2025-10-04', roas: 3.5, spend: 700 },
    ];

    return (
        <Card>
            <h3 className="text-lg font-semibold mb-4">Performance Over Time</h3>
            <div className="bg-gray-200 p-4 rounded-lg">
                <p className="text-center text-gray-600">
                    [Chart Placeholder - Integrate a library like Chart.js here]
                </p>
                <div className="flex justify-around mt-4">
                    {chartData.map(item => (
                        <div key={item.date} className="text-center">
                            <p className="font-bold">{item.roas.toFixed(1)}x</p>
                            <p className="text-sm text-gray-500">${item.spend}</p>
                            <p className="text-xs text-gray-400">{item.date}</p>
                        </div>
                    ))}
                </div>
            </div>
        </Card>
    );
};

export default PerformanceChart;
