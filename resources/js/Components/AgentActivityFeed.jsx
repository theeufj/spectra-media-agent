import React, { useState, useEffect } from 'react';
import axios from 'axios';

const AGENT_TYPE_CONFIG = {
    optimization: { icon: '⚡', label: 'Optimization Agent', color: 'text-yellow-600 bg-yellow-50' },
    budget: { icon: '💰', label: 'Budget Agent', color: 'text-green-600 bg-green-50' },
    creative: { icon: '🎨', label: 'Creative Agent', color: 'text-purple-600 bg-purple-50' },
    maintenance: { icon: '🔧', label: 'Maintenance Agent', color: 'text-blue-600 bg-blue-50' },
    monitoring: { icon: '📊', label: 'Monitoring Agent', color: 'text-flame-orange-600 bg-flame-orange-50' },
    keyword: { icon: '🔑', label: 'Keyword Agent', color: 'text-orange-600 bg-orange-50' },
    strategy: { icon: '🧠', label: 'Strategy Agent', color: 'text-pink-600 bg-pink-50' },
    deployment: { icon: '🚀', label: 'Deployment Agent', color: 'text-cyan-600 bg-cyan-50' },
};

function timeAgo(dateString) {
    const now = new Date();
    const date = new Date(dateString);
    const seconds = Math.floor((now - date) / 1000);

    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
    return date.toLocaleDateString();
}

function ActivityItem({ activity }) {
    const config = AGENT_TYPE_CONFIG[activity.agent_type] || {
        icon: '🤖', label: activity.agent_type, color: 'text-gray-600 bg-gray-50'
    };

    const statusColor = {
        completed: 'bg-green-400',
        failed: 'bg-red-400',
        in_progress: 'bg-yellow-400',
    }[activity.status] || 'bg-gray-400';

    return (
        <div className="flex items-start space-x-3 py-3 border-b border-gray-100 last:border-0">
            <div className={`flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-sm ${config.color}`}>
                {config.icon}
            </div>
            <div className="flex-1 min-w-0">
                <div className="flex items-center space-x-2">
                    <span className="text-xs font-medium text-gray-500">{config.label}</span>
                    <span className={`inline-block w-1.5 h-1.5 rounded-full ${statusColor}`}></span>
                </div>
                <p className="text-sm text-gray-900 mt-0.5">{activity.description}</p>
                {activity.campaign && (
                    <p className="text-xs text-gray-400 mt-0.5">{activity.campaign.name}</p>
                )}
            </div>
            <span className="flex-shrink-0 text-xs text-gray-400">{timeAgo(activity.created_at)}</span>
        </div>
    );
}

export default function AgentActivityFeed({ initialActivities = [], campaignId = null }) {
    const [activities, setActivities] = useState(initialActivities);
    const [loading, setLoading] = useState(initialActivities.length === 0);

    useEffect(() => {
        if (initialActivities.length > 0) {
            setActivities(initialActivities);
            setLoading(false);
            return;
        }

        const params = {};
        if (campaignId) params.campaign_id = campaignId;

        axios.get(route('api.agent-activities.index'), { params })
            .then(res => {
                setActivities(res.data.data || []);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, [campaignId]);

    // Poll for new activities every 30 seconds
    useEffect(() => {
        const interval = setInterval(() => {
            const params = { limit: 20 };
            if (campaignId) params.campaign_id = campaignId;

            axios.get(route('api.agent-activities.index'), { params })
                .then(res => setActivities(res.data.data || []))
                .catch(() => {});
        }, 30000);

        return () => clearInterval(interval);
    }, [campaignId]);

    if (loading) {
        return (
            <div className="bg-white rounded-lg shadow-md p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Agent Activity</h3>
                <div className="animate-pulse space-y-3">
                    {[1, 2, 3].map(i => (
                        <div key={i} className="flex items-center space-x-3">
                            <div className="w-8 h-8 bg-gray-200 rounded-full" />
                            <div className="flex-1 space-y-1">
                                <div className="h-3 bg-gray-200 rounded w-1/3" />
                                <div className="h-3 bg-gray-200 rounded w-2/3" />
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-lg shadow-md p-6">
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-semibold text-gray-900">Agent Activity</h3>
                {activities.length > 0 && (
                    <span className="flex items-center text-xs text-green-600">
                        <span className="w-2 h-2 bg-green-400 rounded-full mr-1 animate-pulse"></span>
                        Live
                    </span>
                )}
            </div>

            {activities.length === 0 ? (
                <div className="text-center py-6 text-gray-500">
                    <span className="text-3xl block mb-2">🤖</span>
                    <p className="text-sm">No agent activity yet.</p>
                    <p className="text-xs text-gray-400 mt-1">
                        AI agents will appear here as they optimize your campaigns.
                    </p>
                </div>
            ) : (
                <div className="divide-y divide-gray-100 max-h-80 overflow-y-auto">
                    {activities.map(activity => (
                        <ActivityItem key={activity.id} activity={activity} />
                    ))}
                </div>
            )}
        </div>
    );
}
