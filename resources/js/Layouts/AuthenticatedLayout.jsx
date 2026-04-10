
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ApplicationLogo from '@/Components/ApplicationLogo';
import NotificationBell from '@/Components/NotificationBell';
import ImpersonationBanner from '@/Components/ImpersonationBanner';
import { useToast } from '@/Components/Toast';
import { Link, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import { Transition } from '@headlessui/react';

function UserInitials({ name, className = '' }) {
    const initials = (name || '?')
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
    return (
        <div className={`flex items-center justify-center rounded-full bg-flame-orange-100 text-flame-orange-700 text-xs font-semibold ${className}`}>
            {initials}
        </div>
    );
}

function NavDropdownButton({ active, children }) {
    return (
        <button
            type="button"
            className={`inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md transition duration-150 ease-in-out focus:outline-none ${
                active
                    ? 'bg-gray-100 text-gray-900'
                    : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'
            }`}
        >
            {children}
            <svg className="ms-1 h-3.5 w-3.5 opacity-50" fill="currentColor" viewBox="0 0 20 20">
                <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
            </svg>
        </button>
    );
}

function MobileNavSection({ title, children }) {
    return (
        <div className="py-2">
            {title && (
                <p className="px-4 pb-1.5 text-[11px] font-semibold text-gray-400 uppercase tracking-wider">
                    {title}
                </p>
            )}
            <div className="space-y-0.5">{children}</div>
        </div>
    );
}

function MobileNavLink({ href, active = false, icon, children, ...props }) {
    return (
        <Link
            href={href}
            className={`flex items-center gap-3 mx-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors ${
                active
                    ? 'bg-flame-orange-50 text-flame-orange-700'
                    : 'text-gray-700 hover:bg-gray-50'
            }`}
            {...props}
        >
            {icon && <span className="w-5 h-5 flex items-center justify-center text-gray-400">{icon}</span>}
            {children}
        </Link>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const { flash } = usePage().props;
    const user = usePage().props.auth.user;
    const activeCustomer = user.active_customer;
    const customers = user.customers || [];
    const toast = useToast();
    const lastFlashRef = useRef(null);

    const [mobileOpen, setMobileOpen] = useState(false);

    // Bridge Inertia flash messages to toast notifications
    useEffect(() => {
        if (flash?.message && flash.message !== lastFlashRef.current) {
            lastFlashRef.current = flash.message;
            const type = flash.type === 'success' ? 'success' : flash.type === 'warning' ? 'warning' : flash.type === 'info' ? 'info' : 'error';
            toast[type](flash.message);
        }
    }, [flash?.message, flash?.type]);

    // Close mobile drawer on navigation
    useEffect(() => {
        const removeListener = router.on('navigate', () => {
            setMobileOpen(false);
        });
        return removeListener;
    }, []);

    // Prevent body scroll when mobile drawer is open
    useEffect(() => {
        if (mobileOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
        return () => { document.body.style.overflow = ''; };
    }, [mobileOpen]);

    const handleSwitchCustomer = (customer) => {
        router.post(route('customers.switch', customer));
    };

    return (
        <div className="min-h-screen bg-gray-50">
            <ImpersonationBanner />

            {/* ── Desktop + Tablet Navigation ── */}
            <nav className="sticky top-0 z-40 bg-white border-b border-gray-200 shadow-sm">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-14 items-center justify-between">

                        {/* Left: Logo + Nav Links */}
                        <div className="flex items-center gap-1">
                            <Link href={route('dashboard')} className="flex-shrink-0 mr-4">
                                <ApplicationLogo className="!text-xl" />
                            </Link>

                            <div className="hidden md:flex items-center gap-1">
                                <NavLink href={route('dashboard')} active={route().current('dashboard')}>
                                    Dashboard
                                </NavLink>

                                {/* Campaigns Dropdown */}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <NavDropdownButton active={route().current('campaigns.*')}>
                                            Campaigns
                                        </NavDropdownButton>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content align="left">
                                        <Dropdown.Link href={route('campaigns.index')}>All Campaigns</Dropdown.Link>
                                        <Dropdown.Link href={route('campaigns.wizard')}>Create New</Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>

                                {/* Content Dropdown */}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <NavDropdownButton active={route().current('knowledge-base.*') || route().current('brand-guidelines.*')}>
                                            Content
                                        </NavDropdownButton>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content align="left">
                                        <Dropdown.Link href={route('knowledge-base.index')}>Knowledge Base</Dropdown.Link>
                                        <Dropdown.Link href={route('brand-guidelines.index')}>Brand Guidelines</Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>

                                {/* Keywords Dropdown */}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <NavDropdownButton active={route().current('keywords.*')}>
                                            Keywords
                                        </NavDropdownButton>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content align="left">
                                        <Dropdown.Link href={route('keywords.index')}>Portfolio</Dropdown.Link>
                                        <Dropdown.Link href={route('keywords.research')}>Research</Dropdown.Link>
                                        <Dropdown.Link href={route('keywords.competitor-gap')}>Competitor Gap</Dropdown.Link>
                                        <Dropdown.Link href={route('keywords.negative-lists')}>Negative Lists</Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>

                                {/* Budget Dropdown */}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <NavDropdownButton active={route().current('budget.*')}>
                                            Budget
                                        </NavDropdownButton>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content align="left">
                                        <Dropdown.Link href={route('budget.allocator')}>Allocator</Dropdown.Link>
                                        <Dropdown.Link href={route('budget.history')}>History</Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>

                                {/* SEO Dropdown */}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <NavDropdownButton active={route().current('seo.*')}>
                                            SEO
                                        </NavDropdownButton>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content align="left">
                                        <Dropdown.Link href={route('seo.index')}>SEO Audit</Dropdown.Link>
                                        <Dropdown.Link href={route('seo.rankings')}>Rankings</Dropdown.Link>
                                        <Dropdown.Link href={route('seo.backlinks')}>Backlinks</Dropdown.Link>
                                        <Dropdown.Link href={route('seo.competitors')}>Competitors</Dropdown.Link>
                                        <Dropdown.Link href={route('seo.cro')}>CRO Audit</Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>

                                <NavLink href={route('strategy.war-room')} active={route().current('strategy.war-room')}>
                                    War Room
                                </NavLink>

                                <NavLink href={route('reports.index')} active={route().current('reports.*')}>
                                    Reports
                                </NavLink>

                                {/* More Dropdown */}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <NavDropdownButton active={route().current('integrations.*') || route().current('products.*') || route().current('support-tickets.*') || route().current('analytics.*') || route().current('proposals.*')}>
                                            More
                                        </NavDropdownButton>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content align="left">
                                        <Dropdown.Link href={route('analytics.index')}>Analytics</Dropdown.Link>
                                        <Dropdown.Link href={route('proposals.index')}>Proposals</Dropdown.Link>
                                        <Dropdown.Link href={route('integrations.index')}>Integrations</Dropdown.Link>
                                        <Dropdown.Link href={route('products.index')}>Products</Dropdown.Link>
                                        <Dropdown.Link href={route('support-tickets.index')}>Support</Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>

                        {/* Right: Actions */}
                        <div className="hidden md:flex items-center gap-2">
                            {/* New Campaign CTA */}
                            <Link
                                href={route('campaigns.wizard')}
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 transition-colors"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                </svg>
                                <span className="hidden lg:inline">New Campaign</span>
                            </Link>

                            {/* Notifications */}
                            <NotificationBell />

                            {/* User Menu (includes customer switching) */}
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button
                                        type="button"
                                        className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors hover:bg-gray-50 focus:outline-none"
                                    >
                                        <UserInitials name={user.name} className="h-8 w-8" />
                                        <div className="hidden lg:block text-left">
                                            <p className="text-sm font-medium text-gray-700 leading-tight">{user.name}</p>
                                            {activeCustomer && (
                                                <p className="text-xs text-gray-400 leading-tight">{activeCustomer.name}</p>
                                            )}
                                        </div>
                                        <svg className="h-4 w-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                                        </svg>
                                    </button>
                                </Dropdown.Trigger>

                                <Dropdown.Content width="64" contentClasses="py-1 bg-white">
                                    {/* Customer switcher section */}
                                    {customers.length > 0 && (
                                        <>
                                            <div className="px-4 py-2 border-b border-gray-100">
                                                <p className="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Workspace</p>
                                            </div>
                                            {customers.map((customer) => (
                                                <button
                                                    key={customer.id}
                                                    type="button"
                                                    onClick={() => handleSwitchCustomer(customer)}
                                                    className={`flex w-full items-center gap-2 px-4 py-2 text-sm transition-colors hover:bg-gray-50 ${
                                                        activeCustomer?.id === customer.id ? 'text-flame-orange-700 bg-flame-orange-50' : 'text-gray-700'
                                                    }`}
                                                >
                                                    <span className="flex h-5 w-5 items-center justify-center rounded bg-gray-100 text-[10px] font-bold text-gray-500">
                                                        {(customer.name || '?')[0].toUpperCase()}
                                                    </span>
                                                    <span className="truncate">{customer.name}</span>
                                                    {activeCustomer?.id === customer.id && (
                                                        <svg className="ml-auto h-4 w-4 text-flame-orange-600" fill="currentColor" viewBox="0 0 20 20">
                                                            <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                        </svg>
                                                    )}
                                                </button>
                                            ))}
                                            <div className="border-b border-gray-100 my-1" />
                                        </>
                                    )}

                                    {/* Account links */}
                                    <Dropdown.Link href={route('profile.edit')}>Profile</Dropdown.Link>
                                    {activeCustomer && (
                                        <Dropdown.Link href={route('customers.edit', activeCustomer.id)}>
                                            Customer Settings
                                        </Dropdown.Link>
                                    )}
                                    {activeCustomer && (
                                        <Dropdown.Link href={route('customers.gtm.setup', activeCustomer.id)}>
                                            GTM Integration
                                        </Dropdown.Link>
                                    )}

                                    <div className="border-b border-gray-100 my-1" />

                                    {/* Billing */}
                                    <Dropdown.Link href={route('subscription.portal')}>Subscription</Dropdown.Link>
                                    <Dropdown.Link href={route('billing.ad-spend')}>Ad Spend Credits</Dropdown.Link>
                                    <Dropdown.Link href={route('subscription.pricing')}>Pricing</Dropdown.Link>

                                    {user.isAdmin && (
                                        <>
                                            <div className="border-b border-gray-100 my-1" />
                                            <Dropdown.Link href={route('admin.dashboard')}>Admin</Dropdown.Link>
                                        </>
                                    )}

                                    <div className="border-b border-gray-100 my-1" />
                                    <Dropdown.Link href={route('logout')} method="post" as="button">
                                        Log Out
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>

                        {/* Mobile hamburger */}
                        <div className="flex items-center gap-2 md:hidden">
                            <NotificationBell />
                            <button
                                onClick={() => setMobileOpen(true)}
                                className="inline-flex items-center justify-center rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 focus:outline-none"
                            >
                                <svg className="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </nav>

            {/* ── Mobile Slide-Out Drawer ── */}
            {/* Backdrop */}
            <Transition
                show={mobileOpen}
                enter="transition-opacity duration-300"
                enterFrom="opacity-0"
                enterTo="opacity-100"
                leave="transition-opacity duration-200"
                leaveFrom="opacity-100"
                leaveTo="opacity-0"
            >
                <div
                    className="fixed inset-0 z-50 bg-black/30 backdrop-blur-sm md:hidden"
                    onClick={() => setMobileOpen(false)}
                />
            </Transition>

            {/* Drawer panel */}
            <Transition
                show={mobileOpen}
                enter="transition-transform duration-300 ease-out"
                enterFrom="translate-x-full"
                enterTo="translate-x-0"
                leave="transition-transform duration-200 ease-in"
                leaveFrom="translate-x-0"
                leaveTo="translate-x-full"
            >
                <div className="fixed inset-y-0 right-0 z-50 w-full max-w-xs bg-white shadow-xl md:hidden flex flex-col">
                    {/* Drawer header */}
                    <div className="flex items-center justify-between px-4 h-14 border-b border-gray-100">
                        <ApplicationLogo className="!text-lg" />
                        <button
                            onClick={() => setMobileOpen(false)}
                            className="rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition-colors"
                        >
                            <svg className="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    {/* Active customer badge */}
                    {activeCustomer && (
                        <div className="mx-4 mt-3 flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2">
                            <span className="flex h-6 w-6 items-center justify-center rounded bg-flame-orange-100 text-[10px] font-bold text-flame-orange-700">
                                {(activeCustomer.name || '?')[0].toUpperCase()}
                            </span>
                            <span className="text-sm font-medium text-gray-700 truncate">{activeCustomer.name}</span>
                        </div>
                    )}

                    {/* Scrollable nav */}
                    <div className="flex-1 overflow-y-auto py-2">
                        {/* New Campaign CTA */}
                        <div className="px-4 py-2">
                            <Link
                                href={route('campaigns.wizard')}
                                className="flex items-center justify-center gap-2 w-full px-4 py-2.5 text-sm font-medium text-white bg-flame-orange-600 rounded-lg hover:bg-flame-orange-700 transition-colors"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                </svg>
                                New Campaign
                            </Link>
                        </div>

                        <MobileNavSection>
                            <MobileNavLink
                                href={route('dashboard')}
                                active={route().current('dashboard')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" /></svg>}
                            >
                                Dashboard
                            </MobileNavLink>
                        </MobileNavSection>

                        <MobileNavSection title="Campaigns">
                            <MobileNavLink
                                href={route('campaigns.index')}
                                active={route().current('campaigns.index')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>}
                            >
                                All Campaigns
                            </MobileNavLink>
                            <MobileNavLink
                                href={route('campaigns.wizard')}
                                active={route().current('campaigns.wizard')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 4v16m8-8H4" /></svg>}
                            >
                                Create New
                            </MobileNavLink>
                        </MobileNavSection>

                        <MobileNavSection title="Content">
                            <MobileNavLink
                                href={route('knowledge-base.index')}
                                active={route().current('knowledge-base.*')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" /></svg>}
                            >
                                Knowledge Base
                            </MobileNavLink>
                            <MobileNavLink
                                href={route('brand-guidelines.index')}
                                active={route().current('brand-guidelines.*')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" /></svg>}
                            >
                                Brand Guidelines
                            </MobileNavLink>
                        </MobileNavSection>

                        <MobileNavSection title="Keywords">
                            <MobileNavLink
                                href={route('keywords.index')}
                                active={route().current('keywords.index')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" /></svg>}
                            >
                                Keyword Portfolio
                            </MobileNavLink>
                            <MobileNavLink
                                href={route('keywords.research')}
                                active={route().current('keywords.research')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>}
                            >
                                Research
                            </MobileNavLink>
                        </MobileNavSection>

                        <MobileNavSection title="Reports">
                            <MobileNavLink
                                href={route('reports.index')}
                                active={route().current('reports.*')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>}
                            >
                                Performance Reports
                            </MobileNavLink>
                        </MobileNavSection>

                        <MobileNavSection title="SEO">
                            <MobileNavLink
                                href={route('seo.index')}
                                active={route().current('seo.index')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>}
                            >
                                SEO Audit
                            </MobileNavLink>
                            <MobileNavLink
                                href={route('seo.rankings')}
                                active={route().current('seo.rankings')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>}
                            >
                                Rankings
                            </MobileNavLink>
                            <MobileNavLink
                                href={route('seo.backlinks')}
                                active={route().current('seo.backlinks')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>}
                            >
                                Backlinks
                            </MobileNavLink>
                            <MobileNavLink
                                href={route('seo.competitors')}
                                active={route().current('seo.competitors')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>}
                            >
                                Competitors
                            </MobileNavLink>
                            <MobileNavLink
                                href={route('seo.cro')}
                                active={route().current('seo.cro*')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>}
                            >
                                CRO Audit
                            </MobileNavLink>
                        </MobileNavSection>

                        <MobileNavSection title="Strategy">
                            <MobileNavLink
                                href={route('strategy.war-room')}
                                active={route().current('strategy.war-room')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>}
                            >
                                War Room
                            </MobileNavLink>
                            <MobileNavLink
                                href={route('proposals.index')}
                                active={route().current('proposals.*')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>}
                            >
                                Proposals
                            </MobileNavLink>
                        </MobileNavSection>

                        <MobileNavSection title="Support & Setup">
                            <MobileNavLink
                                href={route('support-tickets.index')}
                                active={route().current('support-tickets.*')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" /></svg>}
                            >
                                Support Tickets
                            </MobileNavLink>
                            {activeCustomer && (
                                <MobileNavLink
                                    href={route('customers.gtm.setup', activeCustomer.id)}
                                    active={route().current('customers.gtm.*')}
                                    icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>}
                                >
                                    GTM Integration
                                </MobileNavLink>
                            )}
                        </MobileNavSection>

                        <MobileNavSection title="Billing">
                            <MobileNavLink
                                href={route('subscription.portal')}
                                active={route().current('subscription.portal')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>}
                            >
                                Subscription
                            </MobileNavLink>
                            <MobileNavLink
                                href={route('billing.ad-spend')}
                                active={route().current('billing.ad-spend')}
                                icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>}
                            >
                                Ad Spend Credits
                            </MobileNavLink>
                        </MobileNavSection>

                        {user.isAdmin && (
                            <MobileNavSection title="Admin">
                                <MobileNavLink
                                    href={route('admin.dashboard')}
                                    active={route().current('admin.dashboard')}
                                    icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>}
                                >
                                    Admin Dashboard
                                </MobileNavLink>
                            </MobileNavSection>
                        )}

                        {/* Customer switcher in mobile */}
                        {customers.length > 1 && (
                            <MobileNavSection title="Switch Workspace">
                                {customers.map((customer) => (
                                    <button
                                        key={customer.id}
                                        type="button"
                                        onClick={() => handleSwitchCustomer(customer)}
                                        className={`flex items-center gap-3 mx-3 px-3 py-2.5 w-[calc(100%-1.5rem)] text-sm font-medium rounded-lg transition-colors ${
                                            activeCustomer?.id === customer.id
                                                ? 'bg-flame-orange-50 text-flame-orange-700'
                                                : 'text-gray-700 hover:bg-gray-50'
                                        }`}
                                    >
                                        <span className="flex h-5 w-5 items-center justify-center rounded bg-gray-100 text-[10px] font-bold text-gray-500">
                                            {(customer.name || '?')[0].toUpperCase()}
                                        </span>
                                        <span className="truncate">{customer.name}</span>
                                        {activeCustomer?.id === customer.id && (
                                            <svg className="ml-auto h-4 w-4 text-flame-orange-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                                <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                            </svg>
                                        )}
                                    </button>
                                ))}
                            </MobileNavSection>
                        )}
                    </div>

                    {/* Drawer footer: user info */}
                    <div className="border-t border-gray-100 p-4">
                        <div className="flex items-center gap-3 mb-3">
                            <UserInitials name={user.name} className="h-9 w-9" />
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-gray-900 truncate">{user.name}</p>
                                <p className="text-xs text-gray-500 truncate">{user.email}</p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Link
                                href={route('profile.edit')}
                                className="flex-1 text-center px-3 py-1.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                            >
                                Profile
                            </Link>
                            <Link
                                href={route('logout')}
                                method="post"
                                as="button"
                                className="flex-1 text-center px-3 py-1.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors"
                            >
                                Log Out
                            </Link>
                        </div>
                    </div>
                </div>
            </Transition>

            {header && (
                <header className="bg-white shadow-sm">
                    <div className="max-w-7xl mx-auto py-4 sm:py-6 px-4 sm:px-6 lg:px-8">{header}</div>
                </header>
            )}

            <main>{children}</main>
        </div>
    );
}
