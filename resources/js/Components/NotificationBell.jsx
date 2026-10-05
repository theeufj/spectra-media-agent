import React, { useState, useEffect, useRef, useCallback, useId } from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import { fetchJson } from '@/utils/http';
import { usePolling } from '@/hooks/usePolling';
import { brandTint } from '@/Components/Marketing/Hero';

const SEVERITY_ROW_BG = {
    'deployment.failed': 'bg-red-50',
    'health.critical': 'bg-red-50',
    'health.warning': 'bg-yellow-50',
    'billing.warning': 'bg-yellow-50',
};

/**
 * NotificationBell - Real-time notification indicator with dropdown
 */
export default function NotificationBell() {
    const { auth } = usePage().props;
    const [notifications, setNotifications] = useState([]);
    const [isOpen, setIsOpen] = useState(false);
    const [unreadCount, setUnreadCount] = useState(0);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState(null);
    const dropdownRef = useRef(null);
    const panelId = useId();
    const [permission, setPermission] = useState(typeof window !== 'undefined' && 'Notification' in window ? window.Notification.permission : 'unsupported');
    const [permissionBusy, setPermissionBusy] = useState(false);
    const enableBrowserAlerts = async () => {
        setPermissionBusy(true);
        try { setPermission(await window.Notification.requestPermission()); } catch { setError('Browser alerts could not be enabled. Check browser permissions.'); } finally { setPermissionBusy(false); }
    };
    
    // Fetch notifications
    const fetchNotifications = useCallback(async () => {
        try {
            setError(null);
            setIsLoading(true);
            const data = await fetchJson('/api/notifications');
            setNotifications(data.notifications || []);
            setUnreadCount(data.unread_count || 0);
        } catch (error) {
            console.error('Failed to fetch notifications:', error);
            setError('Unable to load notifications');
        } finally {
            setIsLoading(false);
        }
    }, []);

    const { data: polledNotifications, error: pollingError } = usePolling('/api/notifications', { interval: 30000, enabled: Boolean(auth?.user) });
    useEffect(() => { if (polledNotifications) { setNotifications(polledNotifications.notifications || []); setUnreadCount(polledNotifications.unread_count || 0); setIsLoading(false); setError(null); } }, [polledNotifications]);
    useEffect(() => { if (pollingError) { setIsLoading(false); setError('Unable to load notifications. The last received items remain visible.'); } }, [pollingError]);

    useEffect(() => { if (!isOpen) return; const escape = event => { if (event.key === 'Escape') { setIsOpen(false); dropdownRef.current?.querySelector('button')?.focus(); } }; document.addEventListener('keydown', escape); return () => document.removeEventListener('keydown', escape); }, [isOpen]);

    // Close dropdown when clicking outside
    useEffect(() => {
        const handleClickOutside = (event) => {
            if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
                setIsOpen(false);
            }
        };
        
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const markAsRead = async (notificationId) => {
        try {
            await fetchJson(`/api/notifications/${notificationId}/read`, { method: 'POST' });

            setNotifications(prev => 
                prev.map(n => n.id === notificationId ? { ...n, read_at: new Date().toISOString() } : n)
            );
            setUnreadCount(prev => Math.max(0, prev - 1));
        } catch (error) {
            setError('We could not mark that notification as read. Try again.');
        }
    };
    
    const markAllAsRead = async () => {
        try {
            await fetchJson('/api/notifications/read-all', { method: 'POST' });

            setNotifications(prev => prev.map(n => ({ ...n, read_at: new Date().toISOString() })));
            setUnreadCount(0);
        } catch (error) {
            setError('We could not mark your notifications as read. Try again.');
        }
    };

    const handleNotificationClick = (notification) => {
        if (!notification.read_at) {
            markAsRead(notification.id);
        }
        
        if (notification.action_url) {
            setIsOpen(false);
            router.visit(notification.action_url);
        }
    };
    
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
        if (diff < 3600000) return `${Math.floor(diff / 60000)}m ago`;
        if (diff < 86400000) return `${Math.floor(diff / 3600000)}h ago`;
        if (diff < 604800000) return `${Math.floor(diff / 86400000)}d ago`;
        return date.toLocaleDateString();
    };

    const getNotificationBgColor = (notification) => {
        if (notification.read_at) return '';

        return SEVERITY_ROW_BG[notification.type] || '';
    };

    /*
     * The unread default used to be `bg-brand-primary/10`, which Tailwind cannot
     * compile — brand.* is a bare var() and an opacity modifier needs channels it
     * can parse — so unread rows have never actually been tinted. The brand colour
     * has to arrive as an inline style to reach the variable at all. The severity
     * map above stays as classes: those are real Tailwind palette entries and
     * compile fine.
     */
    const getNotificationBgStyle = (notification) => {
        if (notification.read_at || SEVERITY_ROW_BG[notification.type]) return undefined;

        return { backgroundColor: brandTint(10) };
    };


    return (
        <div className="relative" ref={dropdownRef}>
            {/* Bell Button */}
            <button
                onClick={() => setIsOpen(!isOpen)}
                className="relative p-2 text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 rounded-full transition-colors"
                aria-expanded={isOpen}
                aria-controls={panelId}
                aria-label={`Notifications ${unreadCount > 0 ? `(${unreadCount} unread)` : ''}`}
            >
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path 
                        strokeLinecap="round" 
                        strokeLinejoin="round" 
                        strokeWidth={2} 
                        d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" 
                    />
                </svg>
                
                {/* Unread Badge */}
                {unreadCount > 0 && (
                    <span className="absolute top-0 right-0 flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-red-500 rounded-full transform translate-x-1 -translate-y-1 animate-pulse">
                        {unreadCount > 9 ? '9+' : unreadCount}
                    </span>
                )}
            </button>
            
            {/* Dropdown */}
            {isOpen && (
                <div id={panelId} className="fixed left-4 right-4 top-16 sm:absolute sm:left-auto sm:top-auto sm:right-0 mt-2 w-auto sm:w-96 max-w-[calc(100vw-2rem)] bg-white rounded-lg shadow-xl border border-gray-200 overflow-hidden z-50">
                    {/* Header */}
                    <div className="flex items-center justify-between px-4 py-3 bg-brand-dark text-white">
                        <h3 className="text-sm font-semibold">Notifications</h3>
                        <div className="flex items-center space-x-3">
                            {unreadCount > 0 && (
                                <button
                                    onClick={markAllAsRead}
                                    className="text-xs text-white/80 hover:text-white font-medium transition-colors"
                                >
                                    Mark all read
                                </button>
                            )}
                            <button
                                onClick={fetchNotifications}
                                className="text-white/80 hover:text-white transition-colors"
                                title="Refresh"
                            >
                                <svg className={`w-4 h-4 ${isLoading ? 'animate-spin' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    
                    {/* Notification List */}
                    <div className="max-h-[min(28rem,calc(100dvh-14rem))] overflow-y-auto">
                        {error && notifications.length > 0 && <p role="alert" className="m-3 rounded-lg bg-red-50 p-3 text-sm text-red-700">{error}</p>}
                        {isLoading && !notifications.length ? (
                            <div className="px-4 py-8 text-center">
                                <div className="w-8 h-8 border-2 border-brand-primary border-t-transparent rounded-full animate-spin mx-auto mb-3"></div>
                                <p className="text-sm text-gray-500">Loading notifications...</p>
                            </div>
                        ) : error && !notifications.length ? (
                            <div className="px-4 py-8 text-center text-gray-500">
                                <svg className="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                                <p className="text-sm">{error}</p>
                                <button 
                                    onClick={fetchNotifications}
                                    className="mt-2 text-sm text-brand-dark hover:text-brand-darker"
                                >
                                    Try again
                                </button>
                            </div>
                        ) : notifications.length === 0 ? (
                            <div className="px-4 py-8 text-center text-gray-500">
                                <svg className="w-16 h-16 mx-auto mb-3 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1} d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                                </svg>
                                <p className="text-sm font-medium text-gray-600">All caught up!</p>
                                <p className="text-xs text-gray-500 mt-1">No new notifications</p>
                            </div>
                        ) : (
                            notifications.map((notification) => (
                                <button
                                    type="button"
                                    key={notification.id}
                                    onClick={() => handleNotificationClick(notification)}
                                    className={`
                                        w-full text-left px-4 py-3 border-b border-gray-100 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-primary
                                        hover:bg-gray-50 transition-colors
                                        ${getNotificationBgColor(notification)}
                                    `}
                                    style={getNotificationBgStyle(notification)}
                                >
                                    <div className="flex items-start space-x-3">
                                        <span className="text-xl flex-shrink-0 mt-0.5">
                                            {notification.icon || getNotificationIcon(notification.type)}
                                        </span>
                                        <div className="flex-1 min-w-0">
                                            <p className={`text-sm ${!notification.read_at ? 'font-semibold text-gray-900' : 'text-gray-700'}`}>
                                                {notification.title || 'Notification'}
                                            </p>
                                            <p className="text-xs text-gray-500 mt-0.5 line-clamp-2">
                                                {notification.message || ''}
                                            </p>
                                            <div className="flex items-center justify-between mt-2">
                                                <p className="text-xs text-gray-500">
                                                    {formatTime(notification.created_at)}
                                                </p>
                                                {notification.action_text && notification.action_url && (
                                                    <span className="text-xs text-brand-dark font-medium">
                                                        {notification.action_text} →
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                        {!notification.read_at && (
                                            <span className="w-2 h-2 bg-brand-primary rounded-full flex-shrink-0 mt-2" />
                                        )}
                                    </div>
                                </button>
                            ))
                        )}
                    </div>
                    
                    {permission !== 'unsupported' && <div className="border-t border-gray-200 p-3 text-xs text-gray-600">{permission === 'granted' ? 'Browser alerts enabled.' : permission === 'denied' ? 'Browser alerts are blocked. You can change this in browser settings.' : <><p>Get browser alerts for campaign and billing changes.</p><button type="button" disabled={permissionBusy} onClick={enableBrowserAlerts} className="mt-2 font-semibold text-brand-dark underline">Enable browser alerts</button></>}</div>}
                    {/* Footer */}
                    {notifications.length > 0 && (
                        <div className="px-4 py-3 bg-gray-50 border-t flex items-center justify-between">
                            <Link
                                href="/notifications"
                                onClick={() => setIsOpen(false)}
                                className="text-xs text-brand-dark hover:text-brand-darker font-medium"
                            >
                                View all notifications
                            </Link>
                            <span className="text-xs text-gray-500">
                                {unreadCount > 0 ? `${unreadCount} unread` : 'All read'}
                            </span>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
