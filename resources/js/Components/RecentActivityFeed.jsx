
import React from 'react';
import Card from './Card';

const RecentActivityFeed = ({ activities }) => {
    // Mock data structure: [{ id: 1, description: '...', timestamp: '...' }, ...]
    const mockActivities = [
        { id: 1, description: 'Recommendation to pause "Fall Sale" campaign was approved.', timestamp: '2 hours ago' },
        { id: 2, description: 'New ad copy generated for "Winter Promo" campaign.', timestamp: '1 day ago' },
        { id: 3, description: 'Campaign "Summer Kickoff" was successfully published.', timestamp: '3 days ago' },
        { id: 4, description: 'Invoice #inv_123 was paid.', timestamp: '5 days ago' },
    ];

    const feed = activities || mockActivities;

    return (
        <Card>
            <h3 className="text-lg font-semibold mb-4">Recent Activity</h3>
            <ul className="divide-y divide-gray-200">
                {feed.map(activity => (
                    <li key={activity.id} className="py-3">
                        <p className="text-sm text-gray-800">{activity.description}</p>
                        <p className="text-xs text-gray-500">{activity.timestamp}</p>
                    </li>
                ))}
            </ul>
        </Card>
    );
};

export default RecentActivityFeed;
