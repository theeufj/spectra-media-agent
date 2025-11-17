import React from 'react';
import { Link } from '@inertiajs/react';

const NoCampaigns = () => (
    <div className="text-center bg-white p-12 rounded-lg shadow-md">
        <h3 className="text-2xl font-semibold text-gray-800">No Campaigns Yet</h3>
        <p className="mt-2 text-gray-600">It looks like you haven't created any campaigns. Let's get your first one started!</p>
        <Link
            href={route('campaigns.create')}
            className="mt-6 inline-block bg-indigo-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-indigo-700 transition-colors"
        >
            Create Your First Campaign
        </Link>
    </div>
);

export default NoCampaigns;
