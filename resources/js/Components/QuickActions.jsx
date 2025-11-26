import React from 'react';
import { Link, usePage } from '@inertiajs/react';

/**
 * QuickActions - Dashboard widget for quick access to common actions
 */
export default function QuickActions() {
    const { auth } = usePage().props;
    const user = auth?.user;
    
    const actions = [
        {
            id: 'create-campaign',
            title: 'Create Campaign',
            description: 'Start a new ad campaign',
            icon: (
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                </svg>
            ),
            href: '/campaigns/wizard',
            color: 'bg-indigo-500 hover:bg-indigo-600',
            primary: true
        },
        {
            id: 'view-campaigns',
            title: 'View Campaigns',
            description: 'Manage existing campaigns',
            icon: (
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
            ),
            href: '/campaigns',
            color: 'bg-gray-100 hover:bg-gray-200 text-gray-700',
        },
        {
            id: 'knowledge-base',
            title: 'Knowledge Base',
            description: 'Add website content',
            icon: (
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />
                </svg>
            ),
            href: '/knowledge-base',
            color: 'bg-gray-100 hover:bg-gray-200 text-gray-700',
        },
        {
            id: 'brand-guidelines',
            title: 'Brand Guidelines',
            description: 'Review brand identity',
            icon: (
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
                </svg>
            ),
            href: '/brand-guidelines',
            color: 'bg-gray-100 hover:bg-gray-200 text-gray-700',
        },
    ];
    
    return (
        <div className="bg-white rounded-lg shadow-md p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">Quick Actions</h3>
            <div className="space-y-3">
                {actions.map((action) => (
                    <Link
                        key={action.id}
                        href={action.href}
                        className={`
                            flex items-center p-3 rounded-lg transition-all duration-200
                            ${action.primary 
                                ? 'bg-indigo-600 text-white hover:bg-indigo-700 shadow-md hover:shadow-lg' 
                                : 'bg-gray-50 text-gray-700 hover:bg-gray-100'
                            }
                        `}
                    >
                        <span className={`
                            flex items-center justify-center w-10 h-10 rounded-lg mr-3
                            ${action.primary ? 'bg-indigo-500' : 'bg-white shadow-sm'}
                        `}>
                            {action.icon}
                        </span>
                        <div>
                            <p className="font-medium">{action.title}</p>
                            <p className={`text-sm ${action.primary ? 'text-indigo-200' : 'text-gray-500'}`}>
                                {action.description}
                            </p>
                        </div>
                    </Link>
                ))}
            </div>
        </div>
    );
}

/**
 * PendingTasks - Shows tasks that need user attention
 */
export function PendingTasks({ tasks = [] }) {
    if (tasks.length === 0) {
        return (
            <div className="bg-white rounded-lg shadow-md p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Pending Tasks</h3>
                <div className="text-center py-4 text-gray-500">
                    <svg className="w-12 h-12 mx-auto mb-2 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p className="text-sm">All caught up! No pending tasks.</p>
                </div>
            </div>
        );
    }
    
    const getTaskIcon = (type) => {
        const icons = {
            'sign-off': '✍️',
            'review-collateral': '🎨',
            'deploy': '🚀',
            'billing': '💳',
            default: '📋'
        };
        return icons[type] || icons.default;
    };
    
    const getPriorityColor = (priority) => {
        const colors = {
            high: 'border-l-red-500 bg-red-50',
            medium: 'border-l-yellow-500 bg-yellow-50',
            low: 'border-l-blue-500 bg-blue-50',
        };
        return colors[priority] || colors.medium;
    };
    
    return (
        <div className="bg-white rounded-lg shadow-md p-6">
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-semibold text-gray-900">Pending Tasks</h3>
                <span className="px-2 py-1 text-xs font-medium bg-indigo-100 text-indigo-700 rounded-full">
                    {tasks.length}
                </span>
            </div>
            <div className="space-y-3">
                {tasks.slice(0, 5).map((task) => (
                    <Link
                        key={task.id}
                        href={task.href}
                        className={`
                            block p-3 rounded-lg border-l-4 transition-all duration-200
                            hover:shadow-md ${getPriorityColor(task.priority)}
                        `}
                    >
                        <div className="flex items-start space-x-3">
                            <span className="text-lg">{getTaskIcon(task.type)}</span>
                            <div className="flex-1 min-w-0">
                                <p className="font-medium text-gray-900 text-sm">{task.title}</p>
                                <p className="text-xs text-gray-500 mt-0.5">{task.description}</p>
                                <p className="text-xs text-gray-400 mt-1">{task.campaign_name}</p>
                            </div>
                        </div>
                    </Link>
                ))}
            </div>
            {tasks.length > 5 && (
                <Link
                    href="/tasks"
                    className="block mt-4 text-center text-sm text-indigo-600 hover:text-indigo-800 font-medium"
                >
                    View all {tasks.length} tasks →
                </Link>
            )}
        </div>
    );
}

/**
 * CampaignHealthAlerts - Shows health warnings from campaigns
 */
export function CampaignHealthAlerts({ alerts = [] }) {
    if (alerts.length === 0) return null;
    
    const getSeverityStyles = (severity) => {
        const styles = {
            critical: 'bg-red-50 border-red-200 text-red-800',
            warning: 'bg-yellow-50 border-yellow-200 text-yellow-800',
            info: 'bg-blue-50 border-blue-200 text-blue-800',
        };
        return styles[severity] || styles.info;
    };
    
    const getSeverityIcon = (severity) => {
        const icons = {
            critical: '🔴',
            warning: '⚠️',
            info: 'ℹ️',
        };
        return icons[severity] || icons.info;
    };
    
    return (
        <div className="bg-white rounded-lg shadow-md p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4">Campaign Health</h3>
            <div className="space-y-3">
                {alerts.slice(0, 4).map((alert, index) => (
                    <div
                        key={index}
                        className={`p-3 rounded-lg border ${getSeverityStyles(alert.severity)}`}
                    >
                        <div className="flex items-start space-x-2">
                            <span>{getSeverityIcon(alert.severity)}</span>
                            <div className="flex-1">
                                <p className="font-medium text-sm">{alert.title}</p>
                                <p className="text-xs mt-0.5 opacity-80">{alert.message}</p>
                                {alert.campaign_name && (
                                    <p className="text-xs mt-1 opacity-60">{alert.campaign_name}</p>
                                )}
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
