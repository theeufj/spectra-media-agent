
import React from 'react';
import { Line } from 'react-chartjs-2';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
} from 'chart.js';
import Card from './Card';

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend
);

const PerformanceChart = ({ data }) => {
    if (!data || Object.keys(data).length === 0) {
        return (
            <Card>
                <h3 className="text-lg font-semibold mb-4">Performance Over Time</h3>
                <p className="text-gray-500 text-center py-8">
                    Daily performance data will appear here once your campaign starts running.
                </p>
            </Card>
        );
    }

    const chartData = {
        labels: Object.keys(data),
        datasets: [
            {
                label: 'Impressions',
                data: Object.values(data).map(d => d.impressions),
                borderColor: 'rgb(75, 192, 192)',
                tension: 0.1
            },
            {
                label: 'Clicks',
                data: Object.values(data).map(d => d.clicks),
                borderColor: 'rgb(255, 99, 132)',
                tension: 0.1
            }
        ]
    };

    return (
        <Card>
            <h3 className="text-lg font-semibold mb-4">Performance Over Time</h3>
            <Line data={chartData} />
        </Card>
    );
};

export default PerformanceChart;
