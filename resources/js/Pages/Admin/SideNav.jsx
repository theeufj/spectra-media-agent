import { Link, usePage } from '@inertiajs/react';

const NavLink = ({ href, active, children }) => (
    <Link
        href={href}
        className={`flex items-center px-4 py-2 text-sm font-medium rounded-md transition-colors duration-150 ${
            active
                ? 'bg-indigo-500 text-white'
                : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'
        }`}
    >
        {children}
    </Link>
);

const SectionHeader = ({ children }) => (
    <div className="px-4 py-2 text-xs font-semibold text-gray-400 uppercase tracking-wider mt-4 first:mt-0">
        {children}
    </div>
);

export default function SideNav() {
    const { url } = usePage();

    return (
        <div className="w-64 min-h-screen bg-white shadow-lg">
            <div className="px-6 py-4">
                <h2 className="text-lg font-semibold text-gray-800">Admin Menu</h2>
            </div>
            <nav className="mt-4 px-4">
                {/* Users & Customers */}
                <SectionHeader>Users & Customers</SectionHeader>
                <div className="space-y-2">
                    <NavLink href={route('admin.users.index')} active={url.startsWith('/admin/users')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M15 21a6 6 0 00-9-5.197m0 0A5.975 5.975 0 005 10a5.975 5.975 0 00-2-3.354m12 0a5.975 5.975 0 00-2-3.354M4 21a6 6 0 009 5.197"></path></svg>
                        <span>Users</span>
                    </NavLink>
                    <NavLink href={route('admin.customers.index')} active={url.startsWith('/admin/customers')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span>Customers</span>
                    </NavLink>
                </div>

                {/* Analytics & Monitoring */}
                <SectionHeader>Analytics & Monitoring</SectionHeader>
                <div className="space-y-2">
                    <NavLink href={route('admin.revenue.index')} active={url.startsWith('/admin/revenue')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span>Revenue</span>
                    </NavLink>
                    <NavLink href={route('admin.execution.metrics')} active={url.startsWith('/admin/execution')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                        <span>Execution Metrics</span>
                    </NavLink>
                    <NavLink href={route('admin.activity.index')} active={url.startsWith('/admin/activity')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span>Activity Logs</span>
                    </NavLink>
                </div>

                {/* System */}
                <SectionHeader>System</SectionHeader>
                <div className="space-y-2">
                    <NavLink href={route('admin.health.index')} active={url.startsWith('/admin/system-health')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                        <span>System Health</span>
                    </NavLink>
                    <NavLink href={route('admin.platforms.index')} active={url.startsWith('/admin/platforms')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        <span>Platforms</span>
                    </NavLink>
                    <NavLink href={route('admin.settings.index')} active={url.startsWith('/admin/settings')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <span>Settings</span>
                    </NavLink>
                </div>

                {/* Communication */}
                <SectionHeader>Communication</SectionHeader>
                <div className="space-y-2">
                    <NavLink href={route('admin.notifications.index')} active={url.startsWith('/admin/notifications')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                        <span>Notifications</span>
                    </NavLink>
                </div>
            </nav>
        </div>
    );
}
