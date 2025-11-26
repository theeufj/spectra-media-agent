
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink';
import CustomerSwitcher from '@/Components/CustomerSwitcher';
import NotificationBell from '@/Components/NotificationBell';
import SetupProgressNav, { InlineSetupProgress } from '@/Components/SetupProgressNav';
import ImpersonationBanner from '@/Components/ImpersonationBanner';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function AuthenticatedLayout({ header, children }) {
    const { flash } = usePage().props;
    const user = usePage().props.auth.user;
    const activeCustomer = user.active_customer;

    const [showingNavigationDropdown, setShowingNavigationDropdown] =
        useState(false);
    const [showBanner, setShowBanner] = useState(true);

    return (
        <div className="min-h-screen bg-gray-100">
            <ImpersonationBanner />
            <nav className="bg-white border-b border-gray-100">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 justify-between">
                        <div className="flex">
                            <div className="hidden space-x-1 sm:-my-px sm:ms-2 sm:flex items-center">
                                <NavLink href={route('dashboard')} active={route().current('dashboard')}>
                                    Dashboard
                                </NavLink>
                                
                                {/* Campaigns Dropdown */}
                                <div className="flex items-center">
                                    <Dropdown>
                                        <Dropdown.Trigger>
                                            <button
                                                type="button"
                                                className={`inline-flex items-center px-3 pt-1 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none ${
                                                    route().current('campaigns.*')
                                                        ? 'border-indigo-400 text-gray-900'
                                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                                }`}
                                            >
                                                Campaigns
                                                <svg className="ms-1 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                                                </svg>
                                            </button>
                                        </Dropdown.Trigger>
                                        <Dropdown.Content>
                                            <Dropdown.Link href={route('campaigns.index')}>
                                                All Campaigns
                                            </Dropdown.Link>
                                            <Dropdown.Link href={route('campaigns.wizard')}>
                                                Create New
                                            </Dropdown.Link>
                                        </Dropdown.Content>
                                    </Dropdown>
                                </div>
                                
                                {/* Content Dropdown - Knowledge Base & Brand Guidelines */}
                                <div className="flex items-center">
                                    <Dropdown>
                                        <Dropdown.Trigger>
                                            <button
                                                type="button"
                                                className={`inline-flex items-center px-3 pt-1 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none ${
                                                    route().current('knowledge-base.*') || route().current('brand-guidelines.*')
                                                        ? 'border-indigo-400 text-gray-900'
                                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                                }`}
                                            >
                                                Content
                                                <svg className="ms-1 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                                                </svg>
                                            </button>
                                        </Dropdown.Trigger>
                                        <Dropdown.Content>
                                            <Dropdown.Link href={route('knowledge-base.index')}>
                                                Knowledge Base
                                            </Dropdown.Link>
                                            <Dropdown.Link href={route('brand-guidelines.index')}>
                                                Brand Guidelines
                                            </Dropdown.Link>
                                        </Dropdown.Content>
                                    </Dropdown>
                                </div>

                                {activeCustomer && (
                                    <NavLink 
                                        href={route('customers.gtm.setup', activeCustomer.id)} 
                                        active={route().current('customers.gtm.*')}
                                    >
                                        GTM
                                    </NavLink>
                                )}
                                
                                {/* Billing Dropdown */}
                                <div className="flex items-center">
                                    <Dropdown>
                                        <Dropdown.Trigger>
                                            <button
                                                type="button"
                                                className={`inline-flex items-center px-3 pt-1 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none ${
                                                    route().current('subscription.*') || route().current('billing.*')
                                                        ? 'border-indigo-400 text-gray-900'
                                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                                }`}
                                            >
                                                Billing
                                                <svg className="ms-1 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                                                </svg>
                                            </button>
                                        </Dropdown.Trigger>
                                        <Dropdown.Content>
                                            <Dropdown.Link href={route('subscription.portal')}>
                                                Subscription
                                            </Dropdown.Link>
                                            <Dropdown.Link href={route('billing.ad-spend')}>
                                                Ad Spend Credits
                                            </Dropdown.Link>
                                        </Dropdown.Content>
                                    </Dropdown>
                                </div>
                                
                                {user.isAdmin && (
                                    <NavLink href={route('admin.dashboard')} active={route().current('admin.dashboard')}>
                                        Admin
                                    </NavLink>
                                )}
                            </div>
                        </div>

                        <div className="hidden sm:flex sm:items-center sm:ms-6">
                            {/* Setup Progress Indicator */}
                            <InlineSetupProgress />
                            
                            {/* Create Campaign Button */}
                            <Link
                                href={route('campaigns.create')}
                                className="ml-3 inline-flex items-center px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors"
                            >
                                <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                </svg>
                                New Campaign
                            </Link>
                            
                            {/* Notification Bell */}
                            <div className="ml-3">
                                <NotificationBell />
                            </div>
                            
                            <CustomerSwitcher />
                            <div className="relative ms-3">
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span className="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                className="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {user.name}

                                                <svg
                                                    className="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fillRule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clipRule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </Dropdown.Trigger>

                                    <Dropdown.Content>
                                        <Dropdown.Link
                                            href={route('profile.edit')}
                                        >
                                            Profile
                                        </Dropdown.Link>
                                        {activeCustomer && (
                                            <Dropdown.Link
                                                href={route('customers.edit', activeCustomer.id)}
                                            >
                                                Customer Settings
                                            </Dropdown.Link>
                                        )}
                                        <Dropdown.Link
                                            href={route('subscription.pricing')}
                                        >
                                            Pricing
                                        </Dropdown.Link>
                                        <Dropdown.Link
                                            href={route('logout')}
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>

                        <div className="-me-2 flex items-center sm:hidden">
                            <button
                                onClick={() =>
                                    setShowingNavigationDropdown(
                                        (previousState) => !previousState,
                                    )
                                }
                                className="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    className="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        className={
                                            !showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        className={
                                            showingNavigationDropdown
                                                ? 'inline-flex'
                                                : 'hidden'
                                        }
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        strokeWidth="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div
                    className={
                        (showingNavigationDropdown ? 'block' : 'hidden') +
                        ' sm:hidden'
                    }
                >
                    <div className="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            href={route('dashboard')}
                            active={route().current('dashboard')}
                        >
                            Dashboard
                        </ResponsiveNavLink>
                        
                        {/* Campaigns Section */}
                        <div className="px-4 py-2 text-xs font-semibold text-gray-400 uppercase tracking-wider">
                            Campaigns
                        </div>
                        <ResponsiveNavLink
                            href={route('campaigns.index')}
                            active={route().current('campaigns.index')}
                        >
                            All Campaigns
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('campaigns.wizard')}
                            active={route().current('campaigns.wizard')}
                        >
                            Create New
                        </ResponsiveNavLink>
                        
                        {/* Content Section */}
                        <div className="px-4 py-2 text-xs font-semibold text-gray-400 uppercase tracking-wider mt-2">
                            Content
                        </div>
                        <ResponsiveNavLink
                            href={route('knowledge-base.index')}
                            active={route().current('knowledge-base.*')}
                        >
                            Knowledge Base
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('brand-guidelines.index')}
                            active={route().current('brand-guidelines.*')}
                        >
                            Brand Guidelines
                        </ResponsiveNavLink>
                        
                        {/* Setup Section */}
                        {activeCustomer && (
                            <>
                                <div className="px-4 py-2 text-xs font-semibold text-gray-400 uppercase tracking-wider mt-2">
                                    Setup
                                </div>
                                <ResponsiveNavLink
                                    href={route('customers.gtm.setup', activeCustomer.id)}
                                    active={route().current('customers.gtm.*')}
                                >
                                    GTM Integration
                                </ResponsiveNavLink>
                            </>
                        )}
                        
                        {/* Billing Section */}
                        <div className="px-4 py-2 text-xs font-semibold text-gray-400 uppercase tracking-wider mt-2">
                            Billing
                        </div>
                        <ResponsiveNavLink
                            href={route('subscription.portal')}
                            active={route().current('subscription.portal')}
                        >
                            Subscription
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            href={route('billing.ad-spend')}
                            active={route().current('billing.ad-spend')}
                        >
                            Ad Spend Credits
                        </ResponsiveNavLink>
                        
                        {user.isAdmin && (
                            <>
                                <div className="px-4 py-2 text-xs font-semibold text-gray-400 uppercase tracking-wider mt-2">
                                    Admin
                                </div>
                                <ResponsiveNavLink
                                    href={route('admin.dashboard')}
                                    active={route().current('admin.dashboard')}
                                >
                                    Admin Dashboard
                                </ResponsiveNavLink>
                            </>
                        )}
                    </div>

                    <div className="border-t border-gray-200 pb-1 pt-4">
                        <div className="px-4">
                            <div className="text-base font-medium text-gray-800">
                                {user.name}
                            </div>
                            <div className="text-sm font-medium text-gray-500">
                                {user.email}
                            </div>
                        </div>

                        <div className="mt-3 space-y-1">
                            <ResponsiveNavLink href={route('profile.edit')}>
                                Profile
                            </ResponsiveNavLink>
                            {activeCustomer && (
                                <ResponsiveNavLink href={route('customers.edit', activeCustomer.id)}>
                                    Customer Settings
                                </ResponsiveNavLink>
                            )}
                            <ResponsiveNavLink href={route('subscription.portal')}>
                                Subscription Billing
                            </ResponsiveNavLink>
                            <ResponsiveNavLink href={route('billing.ad-spend')}>
                                Ad Spend Billing
                            </ResponsiveNavLink>
                            {user.isAdmin && (
                                <ResponsiveNavLink href={route('admin.dashboard')}>
                                    Admin
                                </ResponsiveNavLink>
                            )}
                            <ResponsiveNavLink
                                method="post"
                                href={route('logout')}
                                as="button"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            {flash?.message && showBanner && (
                <div className={`relative ${flash.type === 'success' ? 'bg-green-500' : 'bg-red-500'} text-white text-center p-4`}>
                    {flash.message}
                    <button 
                        onClick={() => setShowBanner(false)} 
                        className="absolute top-1/2 right-4 -translate-y-1/2 text-white hover:text-gray-200 text-xl font-bold"
                    >
                        &times;
                    </button>
                </div>
            )}

            {header && (
                <header className="bg-white shadow">
                    <div className="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">{header}</div>
                </header>
            )}

            <main>{children}</main>
        </div>
    );
}
