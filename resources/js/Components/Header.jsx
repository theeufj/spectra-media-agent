import React, { useState } from 'react';
import { Link } from '@inertiajs/react';
import { useTenant } from '@/hooks/useTenant';
import TenantTheme from '@/Components/TenantTheme';

export default function Header({ auth }) {
    const [mobileOpen, setMobileOpen] = useState(false);
    const tenant = useTenant();

    const navLinks = [
        { label: 'Features', href: '/features' },
        { label: 'How It Works', href: '/how-it-works' },
        { label: 'Pricing', href: '/pricing' },
        { label: 'Blog', href: '/blog' },
        { label: 'About', href: '/about' },
    ];

    return (
        <>
            <TenantTheme />
            <header className="bg-white shadow-sm sticky top-0 z-50">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="flex justify-between items-center h-16">
                        <Link href="/" className="flex h-11 flex-shrink-0 items-center">
                            <span className="text-2xl font-bold text-brand-primary">{tenant.logo_text}</span>
                        </Link>

                        {/*
                            Hover is brand-dark rather than brand-primary throughout this
                            header: on the flagship skin #ff4d00 on white is 3.33:1, so
                            hovering a 14px link made it harder to read, not easier.
                            brand-dark is 4.96:1 and brand-darker 7.65:1, and both clear
                            AA on the navy skin by a wide margin.
                        */}
                        <nav className="hidden md:flex items-center space-x-8">
                            {navLinks.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    className="text-sm font-medium text-gray-600 hover:text-brand-dark transition-colors"
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </nav>

                        {/* Desktop Auth */}
                        <div className="hidden md:flex items-center space-x-4">
                            {auth && auth.user ? (
                                <Link href={route('dashboard')} className="text-sm font-medium text-gray-600 hover:text-gray-900">
                                    Dashboard
                                </Link>
                            ) : (
                                <>
                                    <a href="/login" className="text-sm font-medium text-gray-600 hover:text-gray-900">
                                        Log in
                                    </a>
                                    <a
                                        href="/register"
                                        className="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-brand-dark hover:bg-brand-darker transition-colors"
                                    >
                                        Start Free
                                    </a>
                                </>
                            )}
                        </div>

                        {/*
                            The only control on a phone, and it had no accessible name and
                            a gray-400 glyph at 2.54:1. gray-600 is 7.56:1.
                        */}
                        <button
                            type="button"
                            onClick={() => setMobileOpen(!mobileOpen)}
                            aria-expanded={mobileOpen}
                            aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
                            className="md:hidden inline-flex h-11 w-11 items-center justify-center rounded-md text-gray-600 hover:bg-gray-100 hover:text-gray-900"
                        >
                            <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                {mobileOpen ? (
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                ) : (
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                                )}
                            </svg>
                        </button>
                    </div>
                </div>

                {/* Mobile menu */}
                {mobileOpen && (
                    <div className="md:hidden border-t border-gray-200 bg-white">
                        <div className="px-4 pt-2 pb-4 space-y-1">
                            {navLinks.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    className="block rounded-md px-3 py-3 text-base font-medium text-gray-700 hover:bg-gray-50 hover:text-brand-dark"
                                    onClick={() => setMobileOpen(false)}
                                >
                                    {link.label}
                                </Link>
                            ))}
                            <div className="pt-4 border-t border-gray-200 space-y-2">
                                {auth && auth.user ? (
                                    <Link href={route('dashboard')} className="block rounded-md px-3 py-3 text-base font-medium text-gray-700 hover:bg-gray-50">
                                        Dashboard
                                    </Link>
                                ) : (
                                    <>
                                        <a href="/login" className="block rounded-md px-3 py-3 text-base font-medium text-gray-700 hover:bg-gray-50">
                                            Log in
                                        </a>
                                        <a href="/register" className="block rounded-md bg-brand-dark px-3 py-3 text-center text-base font-medium text-white hover:bg-brand-darker">
                                            Start Free
                                        </a>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </header>
        </>
    );
}
