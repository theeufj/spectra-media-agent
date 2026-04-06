
import React from 'react';
import { Bar } from 'react-chartjs-2';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js';
import Card from './Card';

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

const CampaignOverviewChart = ({ campaigns }) => {
    if (!campaigns || campaigns.length === 0) {
        return (
            <Card>
                <h3 className="text-lg font-semibold mb-4">Campaign Overview</h3>
                <p className="text-gray-500 text-center py-8">
                    Campaign comparison data will appear here once your campaigns start running.
                </p>
            </Card>
        );
    }

    const labels = campaigns.map((c) => c.name?.length > 20 ? c.name.substring(0, 20) + '…' : c.name);

    const chartData = {
        labels,
        datasets: [
            {
                label: 'Spend ($)',
                data: campaigns.map((c) => c.spend ?? 0),
                backgroundColor: 'rgba(255, 107, 53, 0.7)',
            },
            {
                label: 'Clicks',
                data: campaigns.map((c) => c.clicks ?? 0),
                backgroundColor: 'rgba(75, 192, 192, 0.7)',
            },
            {
                label: 'Conversions',
                data: campaigns.map((c) => c.conversions ?? 0),
                backgroundColor: 'rgba(153, 102, 255, 0.7)',
            },
        ],
    };

    const options = {
        responsive: true,
        plugins: {
            legend: { position: 'top' },
        },
        scales: {
            y: { beginAtZero: true },
        },
    };

    return (
        <Card>
            <h3 className="text-lg font-semibold mb-4">Campaign Overview</h3>
            <Bar data={chartData} options={options} />
        </Card>
    );
};

export default CampaignOverviewChart;
