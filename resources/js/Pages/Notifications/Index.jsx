import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function NotificationsIndex({ notifications, dynamicNotifications, unreadCount }) {
    const [localNotifications, setLocalNotifications] = useState([
        ...dynamicNotifications,
        ...(notifications?.data || [])
    ].sort((a, b) => new Date(b.created_at) - new Date(a.created_at)));

    const getNotificationIcon = (type) => {
        const icons = {
            'campaign.strategy_ready': '📋',
            'campaign.collateral_ready': '🎨',
            'deployment.started': '🚀',
            'deployment.completed': '✅',
            'deployment.failed': '❌',
            'health.warning': '⚠️',
            'health.critical': '🔴',
            'billing.warning': '💳',
            'billing.success': '💰',
            'system.info': 'ℹ️',
            'action_required': '⚡',
            'success': '✅',
            default: '📬'
        };
        return icons[type] || icons.default;
    };

    const formatTime = (timestamp) => {
        if (!timestamp) return '';
        
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;
        
        if (diff < 60000) return 'Just now';
        if (diff < 3600000) return `${Math.floor(diff / 60000)} minutes ago`;
        if (diff < 86400000) return `${Math.floor(diff / 3600000)} hours ago`;
        if (diff < 604800000) return `${Math.floor(diff / 86400000)} days ago`;
        
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric',
            year: date.getFullYear() !== now.getFullYear() ? 'numeric' : undefined
        });
    };

    const getNotificationBgColor = (notification) => {
        if (notification.read_at) return 'bg-white';
        
        const colors = {
            'deployment.failed': 'bg-red-50 border-l-4 border-l-red-500',
            'health.critical': 'bg-red-50 border-l-4 border-l-red-500',
            'health.warning': 'bg-yellow-50 border-l-4 border-l-yellow-500',
            'billing.warning': 'bg-yellow-50 border-l-4 border-l-yellow-500',
            'deployment.completed': 'bg-green-50 border-l-4 border-l-green-500',
            'billing.success': 'bg-green-50 border-l-4 border-l-green-500',
        };
        
        return colors[notification.type] || 'bg-indigo-50 border-l-4 border-l-indigo-500';
    };

    const markAsRead = async (notificationId) => {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            
            await fetch(`/api/notifications/${notificationId}/read`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            
            setLocalNotifications(prev => 
                prev.map(n => n.id === notificationId ? { ...n, read_at: new Date().toISOString() } : n)
            );
        } catch (error) {
            console.error('Failed to mark notification as read:', error);
        }
    };

    const markAllAsRead = async () => {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            
            await fetch('/api/notifications/read-all', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            
            setLocalNotifications(prev => 
                prev.map(n => ({ ...n, read_at: new Date().toISOString() }))
            );
        } catch (error) {
            console.error('Failed to mark all as read:', error);
        }
    };

    const deleteNotification = async (notificationId) => {
        // Only delete persistent notifications (not dynamic ones)
        if (notificationId.toString().includes('-')) {
            // Dynamic notification - just remove from local state
            setLocalNotifications(prev => prev.filter(n => n.id !== notificationId));
            return;
        }

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            
            await fetch(`/api/notifications/${notificationId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            
            setLocalNotifications(prev => prev.filter(n => n.id !== notificationId));
        } catch (error) {
            console.error('Failed to delete notification:', error);
        }
    };

    const handleNotificationClick = (notification) => {
        if (!notification.read_at) {
            markAsRead(notification.id);
        }
        
        if (notification.action_url) {
            router.visit(notification.action_url);
        }
    };

    const unreadNotifications = localNotifications.filter(n => !n.read_at);
    const readNotifications = localNotifications.filter(n => n.read_at);

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Notifications
                    </h2>
                    {unreadNotifications.length > 0 && (
                        <button
                            onClick={markAllAsRead}
                            className="text-sm text-indigo-600 hover:text-indigo-800 font-medium"
                        >
                            Mark all as read
                        </button>
                    )}
                </div>
            }
        >
            <Head title="Notifications" />

            <div className="py-6">
                <div className="mx-auto max-w-4xl sm:px-6 lg:px-8">
                    {localNotifications.length === 0 ? (
                        <div className="bg-white rounded-lg shadow-sm p-12 text-center">
                            <svg className="w-20 h-20 mx-auto mb-4 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1} d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                            </svg>
                            <h3 className="text-lg font-medium text-gray-900 mb-2">All caught up!</h3>
                            <p className="text-gray-500">You have no notifications at the moment.</p>
                        </div>
                    ) : (
                        <div className="space-y-6">
                            {/* Unread Notifications */}
                            {unreadNotifications.length > 0 && (
                                <div>
                                    <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3 px-1">
                                        Unread ({unreadNotifications.length})
                                    </h3>
                                    <div className="bg-white rounded-lg shadow-sm overflow-hidden divide-y divide-gray-100">
                                        {unreadNotifications.map((notification) => (
                                            <div
                                                key={notification.id}
                                                className={`p-4 hover:bg-gray-50 transition-colors ${getNotificationBgColor(notification)}`}
                                            >
                                                <div className="flex items-start space-x-4">
                                                    <span className="text-2xl flex-shrink-0">
                                                        {notification.icon || getNotificationIcon(notification.type)}
                                                    </span>
                                                    <div 
                                                        className="flex-1 min-w-0 cursor-pointer"
                                                        onClick={() => handleNotificationClick(notification)}
                                                    >
                                                        <p className="text-sm font-semibold text-gray-900">
                                                            {notification.title || 'Notification'}
                                                        </p>
                                                        <p className="text-sm text-gray-600 mt-1">
                                                            {notification.message || ''}
                                                        </p>
                                                        <div className="flex items-center mt-2 space-x-4">
                                                            <span className="text-xs text-gray-400">
                                                                {formatTime(notification.created_at)}
                                                            </span>
                                                            {notification.action_text && notification.action_url && (
                                                                <span className="text-xs text-indigo-600 font-medium hover:text-indigo-800">
                                                                    {notification.action_text} →
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center space-x-2 flex-shrink-0">
                                                        <button
                                                            onClick={() => markAsRead(notification.id)}
                                                            className="p-1 text-gray-400 hover:text-indigo-600 transition-colors"
                                                            title="Mark as read"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                            </svg>
                                                        </button>
                                                        <button
                                                            onClick={() => deleteNotification(notification.id)}
                                                            className="p-1 text-gray-400 hover:text-red-600 transition-colors"
                                                            title="Delete"
                                                        >
                                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Read Notifications */}
                            {readNotifications.length > 0 && (
                                <div>
                                    <h3 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3 px-1">
                                        Earlier
                                    </h3>
                                    <div className="bg-white rounded-lg shadow-sm overflow-hidden divide-y divide-gray-100">
                                        {readNotifications.map((notification) => (
                                            <div
                                                key={notification.id}
                                                className="p-4 hover:bg-gray-50 transition-colors"
                                            >
                                                <div className="flex items-start space-x-4">
                                                    <span className="text-2xl flex-shrink-0 opacity-60">
                                                        {notification.icon || getNotificationIcon(notification.type)}
                                                    </span>
                                                    <div 
                                                        className="flex-1 min-w-0 cursor-pointer"
                                                        onClick={() => handleNotificationClick(notification)}
                                                    >
                                                        <p className="text-sm font-medium text-gray-700">
                                                            {notification.title || 'Notification'}
                                                        </p>
                                                        <p className="text-sm text-gray-500 mt-1">
                                                            {notification.message || ''}
                                                        </p>
                                                        <div className="flex items-center mt-2 space-x-4">
                                                            <span className="text-xs text-gray-400">
                                                                {formatTime(notification.created_at)}
                                                            </span>
                                                            {notification.action_text && notification.action_url && (
                                                                <span className="text-xs text-indigo-600 font-medium hover:text-indigo-800">
                                                                    {notification.action_text} →
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <button
                                                        onClick={() => deleteNotification(notification.id)}
                                                        className="p-1 text-gray-400 hover:text-red-600 transition-colors flex-shrink-0"
                                                        title="Delete"
                                                    >
                                                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Pagination */}
                            {notifications?.links && notifications.links.length > 3 && (
                                <div className="flex items-center justify-center space-x-2 mt-6">
                                    {notifications.links.map((link, index) => (
                                        <Link
                                            key={index}
                                            href={link.url || '#'}
                                            className={`px-3 py-2 text-sm rounded-md ${
                                                link.active
                                                    ? 'bg-indigo-600 text-white'
                                                    : link.url
                                                    ? 'bg-white text-gray-700 hover:bg-gray-50 border'
                                                    : 'bg-gray-100 text-gray-400 cursor-not-allowed'
                                            }`}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {/* Notification Settings Link */}
                    <div className="mt-8 text-center">
                        <p className="text-sm text-gray-500">
                            Want to customize your notifications?{' '}
                            <Link href={route('profile.edit')} className="text-indigo-600 hover:text-indigo-800 font-medium">
                                Manage preferences
                            </Link>
                        </p>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
