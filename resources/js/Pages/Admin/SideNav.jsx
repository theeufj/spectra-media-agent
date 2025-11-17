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

export default function SideNav() {
    const { url } = usePage();

    return (
        <div className="w-64 min-h-screen bg-white shadow-lg">
            <div className="px-6 py-4">
                <h2 className="text-lg font-semibold text-gray-800">Admin Menu</h2>
            </div>
            <nav className="mt-4 px-4">
                <div className="space-y-2">
                    <NavLink href={route('admin.users.index')} active={url.startsWith('/admin/users')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M15 21a6 6 0 00-9-5.197m0 0A5.975 5.975 0 005 10a5.975 5.975 0 00-2-3.354m12 0a5.975 5.975 0 00-2-3.354M4 21a6 6 0 009 5.197"></path></svg>
                        <span>Users</span>
                    </NavLink>
                    <NavLink href={route('admin.customers.index')} active={url.startsWith('/admin/customers')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span>Customers</span>
                    </NavLink>
                    <NavLink href={route('admin.notifications.index')} active={url.startsWith('/admin/notifications')}>
                        <svg className="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                        <span>Notifications</span>
                    </NavLink>
                </div>
            </nav>
        </div>
    );
}
