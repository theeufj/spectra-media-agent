
import Dropdown from '@/Components/Dropdown';
import NavLink from '@/Components/NavLink';
import ApplicationLogo from '@/Components/ApplicationLogo';
import NotificationBell from '@/Components/NotificationBell';
import ImpersonationBanner from '@/Components/ImpersonationBanner';
import OnboardingTour, { startTour } from '@/Components/OnboardingTour';
import TenantTheme from '@/Components/TenantTheme';
import SupportChat from '@/Components/SupportChat';
import { useToast } from '@/Components/Toast';
import { Link, usePage, router } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';
import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';

function UserInitials({ name, className = '' }) {
    const initials = (name || '?')
        .split(' ')
        .map((w) => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
    return (
        <div className={`flex items-center justify-center rounded-full bg-brand-tint-20 text-brand-darker text-xs font-semibold ${className}`}>
            {initials}
        </div>
    );
}

// Focus treatment matches NavLink: these four dropdown triggers sit in the same
// tab run as it and used to strip the outline with nothing put back.
function NavDropdownButton({ active, children }) {
    return (
        <button
            type="button"
            className={`inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md transition duration-150 ease-in-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2 ${
                active
                    ? 'bg-gray-100 text-gray-900'
                    : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700'
            }`}
        >
            {children}
            <svg className="ms-1 h-3.5 w-3.5 opacity-50" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
            </svg>
        </button>
    );
}

function MobileNavSection({ title, children }) {
    return (
        <div className="py-2">
            {title && (
                <p className="px-4 pb-1.5 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                    {title}
                </p>
            )}
            <div className="space-y-0.5">{children}</div>
        </div>
    );
}

// The active label sits on a 10% brand tint, not on white, so brand-dark is not
// dark enough: #cc3d00 on that tint is 4.37:1. brand-darker is 6.74:1 on the
// orange skin and 14.4:1 on the navy one. (The old text-brand-primary was
// 2.93:1 — the current page was the hardest item in the drawer to read.)
function MobileNavLink({ href, active = false, icon, children, ...props }) {
    return (
        <Link
            href={href}
            className={`flex items-center gap-3 mx-3 px-3 py-2.5 text-sm font-medium rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-primary ${
                active
                    ? 'bg-brand-tint-10 text-brand-darker'
                    : 'text-gray-700 hover:bg-gray-50'
            }`}
            {...props}
        >
            {icon && <span className="w-5 h-5 flex items-center justify-center text-gray-500" aria-hidden="true">{icon}</span>}
            {children}
        </Link>
    );
}

export default function AuthenticatedLayout({ header, children, contained = true }) {
    const { flash } = usePage().props;
    const user = usePage().props.auth.user;
    const activeCustomer = user.active_customer;
    const setupOnly = activeCustomer?.service_type === 'setup_only';
    const customers = user.customers || [];
    const toast = useToast();
    // One slot per flash channel. A single shared slot would let a response
    // carrying both `success` and `error` drop whichever arrived second, and the
    // dedupe exists because flash props survive in page props across partial
    // reloads — without it a polling `router.reload` re-toasts the same message.
    const lastFlashRef = useRef({});

    const [mobileOpen, setMobileOpen] = useState(false);

    // Bridge Inertia flash messages to toast notifications.
    //
    // `flash.message`/`flash.type` is the pair the layout has always read, but
    // HandleInertiaRequests also shares `success` and `error` — the keys that 30
    // customer-facing controller actions use with `->with('error', ...)`. Nothing
    // rendered those: the "pause it on the platforms first" explanation behind a
    // refused campaign delete, and the free-tier upgrade prompt on Knowledge
    // Base, were written by the server and shown to nobody.
    useEffect(() => {
        const show = (channel, kind, value) => {
            // Controllers occasionally flash a validation bag rather than a
            // string; rendering that as a toast body throws inside React.
            if (typeof value !== 'string' || value === '') return;
            if (lastFlashRef.current[channel] === value) return;
            lastFlashRef.current[channel] = value;
            toast[kind](value);
        };

        const messageKind = flash?.type === 'success' ? 'success'
            : flash?.type === 'warning' ? 'warning'
            : flash?.type === 'info' ? 'info'
            : 'error';

        show('message', messageKind, flash?.message);
        show('success', 'success', flash?.success);
        show('error', 'error', flash?.error);
    }, [flash?.message, flash?.type, flash?.success, flash?.error]);

    // Close mobile drawer on navigation
    useEffect(() => {
        const removeListener = router.on('navigate', () => {
            setMobileOpen(false);
        });
        return removeListener;
    }, []);

    // Headless UI's Dialog keeps focus trapped for as long as it is open, and
    // the drawer is hidden at md and up. Widening the window while it is open
    // would otherwise leave the trap armed inside a display:none panel — focus
    // locked in a drawer the user cannot see. (Dialog also owns the body scroll
    // lock now, which is why the manual document.body.style.overflow effect that
    // used to live here is gone: two owners of that property leave the page
    // permanently unscrollable when they disagree about who restores it.)
    useEffect(() => {
        if (!mobileOpen) return;
        const desktop = window.matchMedia('(min-width: 768px)');
        const closeIfDesktop = () => { if (desktop.matches) setMobileOpen(false); };
        closeIfDesktop();
        desktop.addEventListener('change', closeIfDesktop);
        return () => desktop.removeEventListener('change', closeIfDesktop);
    }, [mobileOpen]);

    const handleSwitchCustomer = (customer) => {
        router.post(route('customers.switch', customer));
    };

    return (
        <div className="min-h-screen bg-gray-50">
            {/*
              First focusable thing on every authenticated page. The header
              carries up to 30 links before the content starts, so without this
              a keyboard user pays that toll on every single navigation.
              sr-only until focused; white on brand-dark is 4.96:1 on the orange
              skin and 14.3:1 on the navy one. z-[70] clears the sticky nav
              (z-40) and the drawer (z-50).
            */}
            <a
                href="#main-content"
                className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-[70] focus:rounded-md focus:bg-brand-dark focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2"
            >
                Skip to main content
            </a>

            <TenantTheme />
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
                                <NavLink href={route('dashboard')} active={route().current('dashboard')} data-tour="dashboard">
                                    Dashboard
                                </NavLink>

                                {/* Campaigns Dropdown */}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span data-tour="campaigns">
                                        <NavDropdownButton active={route().current('campaigns.*') || route().current('creative-briefs.*')}>
                                            Campaigns
                                        </NavDropdownButton>
                                        </span>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content align="left">
                                        <Dropdown.Link href={route('campaigns.index')}>All Campaigns</Dropdown.Link>
                                        <Dropdown.Link href={route('campaigns.wizard')}>Create New</Dropdown.Link>
                                        <Dropdown.Divider />
                                        <Dropdown.Link href={route('creative-briefs.index')}>Creative Briefs</Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>

                                {/* Content Dropdown */}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span data-tour="content">
                                        <NavDropdownButton active={route().current('knowledge-base.*') || route().current('brand-guidelines.*') || route().current('products.*')}>
                                            Content
                                        </NavDropdownButton>
                                        </span>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content align="left">
                                        <Dropdown.Link href={route('knowledge-base.index')}>Knowledge Base</Dropdown.Link>
                                        <Dropdown.Link href={route('brand-guidelines.index')}>Brand Guidelines</Dropdown.Link>
                                        <Dropdown.Link href={route('products.index')}>Products</Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>

                                {/* Insights Dropdown (Keywords + SEO + Budget + Reports + Analytics) */}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span data-tour="insights">
                                        <NavDropdownButton active={route().current('keywords.*') || route().current('seo.*') || route().current('budget.*') || route().current('reports.*') || route().current('analytics.*')}>
                                            Insights
                                        </NavDropdownButton>
                                        </span>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content align="left" width="64" contentClasses="py-1 bg-white max-h-[70vh] overflow-y-auto">
                                        <Dropdown.Header>Keywords</Dropdown.Header>
                                        <Dropdown.Link href={route('keywords.index')}>Portfolio</Dropdown.Link>
                                        <Dropdown.Link href={route('keywords.research')}>Research</Dropdown.Link>
                                        <Dropdown.Link href={route('keywords.competitor-gap')}>Competitor Gap</Dropdown.Link>
                                        <Dropdown.Link href={route('keywords.negative-lists')}>Negative Lists</Dropdown.Link>
                                        <Dropdown.Divider />
                                        <Dropdown.Header>SEO</Dropdown.Header>
                                        <Dropdown.Link href={route('seo.index')}>SEO Audit</Dropdown.Link>
                                        <Dropdown.Link href={route('seo.rankings')}>Rankings</Dropdown.Link>
                                        <Dropdown.Link href={route('seo.backlinks')}>Backlinks</Dropdown.Link>
                                        <Dropdown.Link href={route('seo.competitors')}>Competitors</Dropdown.Link>
                                        <Dropdown.Link href={route('seo.cro')}>CRO Audit</Dropdown.Link>
                                        <Dropdown.Divider />
                                        <Dropdown.Header>Performance</Dropdown.Header>
                                        <Dropdown.Link href={route('budget.allocator')}>Budget Allocator</Dropdown.Link>
                                        <Dropdown.Link href={route('budget.history')}>Budget History</Dropdown.Link>
                                        <Dropdown.Link href={route('personas.index')}>Audience Personas</Dropdown.Link>
                                        <Dropdown.Link href={route('reports.index')}>Reports</Dropdown.Link>
                                        <Dropdown.Link href={route('analytics.attribution')}>Attribution</Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>

                                {/*
                                    Admin-only, matching the route. The link was
                                    shown to everyone while routes/web.php put
                                    the whole sandbox group behind ['auth',
                                    'admin'], so a customer clicking it got a
                                    403 — and the onboarding tour walked them
                                    straight to it.
                                */}
                                {user.isAdmin && (
                                    <NavLink href={route('sandbox.index')} active={route().current('sandbox.*')} data-tour="sandbox">
                                        Sandbox
                                    </NavLink>
                                )}

                                {/* Strategy Dropdown */}
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <span data-tour="strategy">
                                        <NavDropdownButton active={route().current('strategy.*') || route().current('proposals.*')}>
                                            Strategy
                                        </NavDropdownButton>
                                        </span>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content align="left">
                                        <Dropdown.Link href={route('strategy.war-room')}>Activity &amp; competitors</Dropdown.Link>
                                        <Dropdown.Link href={route('proposals.index')}>Proposals</Dropdown.Link>
                                    </Dropdown.Content>
                                </Dropdown>
                            </div>
                        </div>

                        {/* Right: Actions */}
                        <div className="hidden md:flex items-center gap-2">
                            {/* New Campaign CTA */}
                            {/* bg-brand-dark, not bg-brand-primary: white on
                                #ff4d00 is 3.33:1 and this label is 14px. Same
                                swap as PrimaryButton so the header CTA and the
                                in-page primaries agree. */}
                            {/* Not for a one-time setup customer: we write the
                                campaign, and the wizard they landed in told them
                                to contact us to get their account configured. */}
                            {!setupOnly && <Link
                                href={route('campaigns.wizard')}
                                data-tour="new-campaign"
                                className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium text-white bg-brand-dark rounded-lg hover:bg-brand-darker transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                            >
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                </svg>
                                <span className="hidden lg:inline">New Campaign</span>
                                {/* The label collapses below lg, leaving a bare
                                    plus icon with no accessible name. */}
                                <span className="sr-only lg:hidden">New Campaign</span>
                            </Link>}

                            {/* Notifications */}
                            <NotificationBell />

                            {/* User Menu (includes customer switching) */}
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button
                                        type="button"
                                        data-tour="profile"
                                        className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                                    >
                                        {/* Below lg the only content is the
                                            initials disc, so the button
                                            announced as "JS" with no hint that
                                            it opens the account menu. sr-only
                                            rather than aria-label so the visible
                                            name stays part of the accessible
                                            name at lg. */}
                                        <span className="sr-only">Account menu</span>
                                        <UserInitials name={user.name} className="h-8 w-8" />
                                        <div className="hidden lg:block text-left">
                                            <p className="text-sm font-medium text-gray-700 leading-tight">{user.name}</p>
                                            {activeCustomer && (
                                                <p className="text-xs text-gray-500 leading-tight">{activeCustomer.name}</p>
                                            )}
                                        </div>
                                        <svg className="h-4 w-4 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                            <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                                        </svg>
                                    </button>
                                </Dropdown.Trigger>

                                <Dropdown.Content width="64" contentClasses="py-1 bg-white">
                                    {/* Customer switcher section */}
                                    {customers.length > 0 && (
                                        <>
                                            <div className="px-4 py-2 border-b border-gray-100">
                                                <p className="text-xs font-semibold text-gray-500 uppercase tracking-wider">Workspace</p>
                                            </div>
                                            {customers.map((customer) => (
                                                <button
                                                    key={customer.id}
                                                    type="button"
                                                    onClick={() => handleSwitchCustomer(customer)}
                                                    aria-current={activeCustomer?.id === customer.id ? 'true' : undefined}
                                                    className={`flex w-full items-center gap-2 px-4 py-2 text-sm transition-colors hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-primary ${
                                                        activeCustomer?.id === customer.id ? 'text-brand-darker bg-brand-tint-10' : 'text-gray-700'
                                                    }`}
                                                >
                                                    <span className="flex h-5 w-5 items-center justify-center rounded bg-gray-100 text-xs font-bold text-gray-500" aria-hidden="true">
                                                        {(customer.name || '?')[0].toUpperCase()}
                                                    </span>
                                                    <span className="truncate">{customer.name}</span>
                                                    {activeCustomer?.id === customer.id && (
                                                        <svg className="ml-auto h-4 w-4 text-brand-darker" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
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
                                        <Dropdown.Link href={route('customers.edit', activeCustomer.uuid)}>
                                            Customer Settings
                                        </Dropdown.Link>
                                    )}
                                    {activeCustomer && (
                                        <Dropdown.Link href={route('customers.gtm.setup', activeCustomer.uuid)}>
                                            GTM Integration
                                        </Dropdown.Link>
                                    )}

                                    <div className="border-b border-gray-100 my-1" />

                                    {/* Billing */}
                                    <Dropdown.Link href={route('subscription.portal')}>Subscription</Dropdown.Link>
                                    <Dropdown.Link href={route('billing.ad-spend')}>Ad Spend Credits</Dropdown.Link>
                                    <Dropdown.Link href={route('creative-usage')}>Creative Usage</Dropdown.Link>
                                    <Dropdown.Link href={route('subscription.pricing')}>Pricing</Dropdown.Link>

                                    <div className="border-b border-gray-100 my-1" />

                                    {/* Tools & Support */}
                                    <Dropdown.Link href={route('integrations.index')}>Integrations</Dropdown.Link>
                                    <Dropdown.Link href={route('support-tickets.index')}>Support</Dropdown.Link>

                                    {user.has_inbox && (
                                        <>
                                            <div className="border-b border-gray-100 my-1" />
                                            <Dropdown.Link href={route('inbox.index')}>Inbox</Dropdown.Link>
                                        </>
                                    )}

                                    {user.isAdmin && (
                                        <>
                                            <div className="border-b border-gray-100 my-1" />
                                            <Dropdown.Link href={route('admin.dashboard')}>Admin</Dropdown.Link>
                                        </>
                                    )}

                                    <div className="border-b border-gray-100 my-1" />
                                    <button
                                        type="button"
                                        onClick={startTour}
                                        className="block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700 transition duration-150 ease-in-out hover:bg-gray-50 focus-visible:bg-gray-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-primary"
                                    >
                                        Site Tour
                                    </button>
                                    <Dropdown.Link href={route('logout')} method="post" as="button">
                                        Log Out
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>

                        {/* Mobile hamburger */}
                        <div className="flex items-center gap-2 md:hidden">
                            <NotificationBell />
                            {/* Icon-only, so it announced as "button" with no
                                name and no state. aria-controls points at the
                                DialogPanel below. */}
                            <button
                                type="button"
                                onClick={() => setMobileOpen(true)}
                                aria-label="Open menu"
                                aria-expanded={mobileOpen}
                                aria-controls="mobile-menu"
                                className="inline-flex items-center justify-center rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                            >
                                <svg className="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 6h16M4 12h16M4 18h16" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </nav>

            {/*
              ── Mobile Slide-Out Drawer ──
              Headless UI's Dialog rather than two bare Transitions, and for the
              accessibility, not the animation: it supplies role="dialog",
              aria-modal, the initial focus move into the panel, the focus trap,
              Escape-to-close, the focus restore back to the hamburger, and
              aria-hidden on the rest of the page. Before this, opening the menu
              left focus on the trigger *underneath* the overlay and Tab then
              walked through the invisible page behind it. Same composition as
              Components/Modal.jsx.

              The positioning also lives on the TransitionChild rather than on a
              div inside it. A translate-x-* on an ancestor makes that ancestor
              the containing block for any `fixed` descendant, so the old shape
              collapsed the panel to the wrapper's zero height for the length of
              every open/close animation.
            */}
            <Transition show={mobileOpen}>
                <Dialog
                    as="div"
                    className="relative z-50 md:hidden"
                    aria-label="Site menu"
                    onClose={() => setMobileOpen(false)}
                >
                    <TransitionChild
                        as="div"
                        className="fixed inset-0 bg-black/30 backdrop-blur-sm"
                        enter="transition-opacity duration-300"
                        enterFrom="opacity-0"
                        enterTo="opacity-100"
                        leave="transition-opacity duration-200"
                        leaveFrom="opacity-100"
                        leaveTo="opacity-0"
                    />

                    <TransitionChild
                        className="fixed inset-y-0 right-0 flex w-full max-w-xs"
                        enter="transition-transform duration-300 ease-out"
                        enterFrom="translate-x-full"
                        enterTo="translate-x-0"
                        leave="transition-transform duration-200 ease-in"
                        leaveFrom="translate-x-0"
                        leaveTo="translate-x-full"
                    >
                        <DialogPanel id="mobile-menu" className="flex w-full flex-col bg-white shadow-xl">
                            {/* Drawer header */}
                            <div className="flex items-center justify-between px-4 h-14 border-b border-gray-100">
                                <ApplicationLogo className="!text-lg" />
                                <button
                                    type="button"
                                    onClick={() => setMobileOpen(false)}
                                    aria-label="Close menu"
                                    className="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                                >
                                    <svg className="h-5 w-5" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            {/* Active customer badge */}
                            {activeCustomer && (
                                <div className="mx-4 mt-3 flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2">
                                    {/* text-brand-primary on a 20% tint of itself is
                                        2.57:1; brand-darker is 5.92:1 on the orange skin
                                        and 11.9:1 on the navy one. */}
                                    <span className="flex h-6 w-6 items-center justify-center rounded bg-brand-tint-20 text-xs font-bold text-brand-darker" aria-hidden="true">
                                        {(activeCustomer.name || '?')[0].toUpperCase()}
                                    </span>
                                    <span className="text-sm font-medium text-gray-700 truncate">{activeCustomer.name}</span>
                                </div>
                            )}

                            {/* Scrollable nav */}
                            <div className="flex-1 overflow-y-auto py-2">
                                {/* New Campaign CTA */}
                                {!setupOnly && <div className="px-4 py-2">
                                    <Link
                                        href={route('campaigns.wizard')}
                                        className="flex items-center justify-center gap-2 w-full px-4 py-2.5 text-sm font-medium text-white bg-brand-dark rounded-lg hover:bg-brand-darker transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                                    >
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                        </svg>
                                        New Campaign
                                    </Link>
                                </div>}

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
                                    <MobileNavLink
                                        href={route('products.index')}
                                        active={route().current('products.*')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg>}
                                    >
                                        Products
                                    </MobileNavLink>
                                </MobileNavSection>

                                <MobileNavSection title="Insights">
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
                                        Keyword Research
                                    </MobileNavLink>
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
                                        href={route('seo.cro')}
                                        active={route().current('seo.cro*')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>}
                                    >
                                        CRO Audit
                                    </MobileNavLink>
                                    <MobileNavLink
                                        href={route('budget.allocator')}
                                        active={route().current('budget.*')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>}
                                    >
                                        Budget
                                    </MobileNavLink>
                                    <MobileNavLink
                                        href={route('personas.index')}
                                        active={route().current('personas.*')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" /></svg>}
                                    >
                                        Personas
                                    </MobileNavLink>
                                    <MobileNavLink
                                        href={route('reports.index')}
                                        active={route().current('reports.*')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>}
                                    >
                                        Reports
                                    </MobileNavLink>
                                    <MobileNavLink
                                        href={route('analytics.attribution')}
                                        active={route().current('analytics.attribution')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>}
                                    >
                                        Attribution
                                    </MobileNavLink>
                                </MobileNavSection>

                                {/* Admin-only here too — the route is gated, so
                                    the whole section is empty for a customer. */}
                                {user.isAdmin && (
                                <MobileNavSection title="Tools">
                                    <MobileNavLink
                                        href={route('sandbox.index')}
                                        active={route().current('sandbox.*')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" /></svg>}
                                    >
                                        Sandbox
                                    </MobileNavLink>
                                </MobileNavSection>
                                )}

                                <MobileNavSection title="Strategy">
                                    <MobileNavLink
                                        href={route('strategy.war-room')}
                                        active={route().current('strategy.war-room')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>}
                                    >
                                        Activity &amp; competitors
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
                                        href={route('integrations.index')}
                                        active={route().current('integrations.*')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>}
                                    >
                                        Integrations
                                    </MobileNavLink>
                                    <MobileNavLink
                                        href={route('support-tickets.index')}
                                        active={route().current('support-tickets.*')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" /></svg>}
                                    >
                                        Support Tickets
                                    </MobileNavLink>
                                    {activeCustomer && (
                                        <MobileNavLink
                                            href={route('customers.gtm.setup', activeCustomer.uuid)}
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
                                    <MobileNavLink
                                        href={route('creative-usage')}
                                        active={route().current('creative-usage')}
                                        icon={<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>}
                                    >
                                        Creative Usage
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
                                                aria-current={activeCustomer?.id === customer.id ? 'true' : undefined}
                                                className={`flex items-center gap-3 mx-3 px-3 py-2.5 w-[calc(100%-1.5rem)] text-sm font-medium rounded-lg transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-primary ${
                                                    activeCustomer?.id === customer.id
                                                        ? 'bg-brand-tint-10 text-brand-darker'
                                                        : 'text-gray-700 hover:bg-gray-50'
                                                }`}
                                            >
                                                <span className="flex h-5 w-5 items-center justify-center rounded bg-gray-100 text-xs font-bold text-gray-500" aria-hidden="true">
                                                    {(customer.name || '?')[0].toUpperCase()}
                                                </span>
                                                <span className="truncate">{customer.name}</span>
                                                {activeCustomer?.id === customer.id && (
                                                    <svg className="ml-auto h-4 w-4 text-brand-darker flex-shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
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
                                        className="flex-1 text-center px-3 py-1.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                                    >
                                        Profile
                                    </Link>
                                    <Link
                                        href={route('logout')}
                                        method="post"
                                        as="button"
                                        className="flex-1 text-center px-3 py-1.5 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2"
                                    >
                                        Log Out
                                    </Link>
                                </div>
                            </div>
                        </DialogPanel>
                    </TransitionChild>
                </Dialog>
            </Transition>

            {header && (
                <header className="bg-white shadow-sm">
                    <div className="max-w-7xl mx-auto py-4 sm:py-6 px-4 sm:px-6 lg:px-8">{header}</div>
                </header>
            )}

            {/*
              The content column is owned here, not by the page. `<main>` used to
              be bare, so ~100 pages each declared their own width and padding —
              five widths and three top paddings between them — and because the
              nav stays full-bleed the column visibly re-centred and re-widthed
              under a fixed header on almost every click.

              max-w-7xl matches the header block above it, so the two align, and
              it is the widest wrapper any page currently asks for. The pages
              still carrying their own wrapper compose rather than break: they
              inherit an extra gutter and an extra band of vertical padding until
              a later pass strips them. Nothing here clips, sizes or transforms,
              so a redundant inner `mx-auto max-w-* px-* py-*` only adds
              whitespace.

              `contained={false}` is the escape hatch for a page that is
              genuinely full-bleed — the admin console's SideNav + content flex
              row is the one shape in the app that is.

              id/tabIndex are the skip link's target.
            */}
            <main id="main-content" tabIndex={-1}>
                {contained ? (
                    <div className="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">{children}</div>
                ) : (
                    children
                )}
            </main>

            {/* Onboarding tour for new users */}
            <OnboardingTour />

            {/*
              Mounted on the layout rather than per page, so it is present on
              every in-app screen — including the admin console, which uses this
              same layout.
            */}
            <SupportChat />
        </div>
    );
}
