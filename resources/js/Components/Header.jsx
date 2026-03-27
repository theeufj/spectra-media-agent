import React, { useState } from 'react';
import { Link } from '@inertiajs/react';

export default function Header({ auth }) {
    const [mobileOpen, setMobileOpen] = useState(false);

    const navLinks = [
        { label: 'Features', href: '/features' },
        { label: 'How It Works', href: '/how-it-works' },
        { label: 'Pricing', href: '/pricing' },
        { label: 'Free Audit', href: '/free-audit' },
        { label: 'About', href: '/about' },
    ];

    return (
        <header className="bg-white shadow-sm sticky top-0 z-50">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="flex justify-between items-center h-16">
                    <Link href="/" className="flex-shrink-0">
                        <span className="text-2xl font-bold text-flame-orange-600">sitetospend</span>
                    </Link>

                    {/* Desktop Nav */}
                    <nav className="hidden md:flex items-center space-x-8">
                        {navLinks.map((link) => (
                            <Link
                                key={link.href}
                                href={link.href}
                                className="text-sm font-medium text-gray-600 hover:text-flame-orange-600 transition-colors"
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
                                    className="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-flame-orange-600 hover:bg-flame-orange-700 transition-colors"
                                >
                                    Start Free
                                </a>
                            </>
                        )}
                    </div>

                    {/* Mobile menu button */}
                    <button
                        onClick={() => setMobileOpen(!mobileOpen)}
                        className="md:hidden inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100"
                    >
                        <svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                                className="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:text-flame-orange-600 hover:bg-gray-50"
                                onClick={() => setMobileOpen(false)}
                            >
                                {link.label}
                            </Link>
                        ))}
                        <div className="pt-4 border-t border-gray-200 space-y-2">
                            {auth && auth.user ? (
                                <Link href={route('dashboard')} className="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:bg-gray-50">
                                    Dashboard
                                </Link>
                            ) : (
                                <>
                                    <a href="/login" className="block px-3 py-2 rounded-md text-base font-medium text-gray-700 hover:bg-gray-50">
                                        Log in
                                    </a>
                                    <a href="/register" className="block px-3 py-2 rounded-md text-base font-medium text-white bg-flame-orange-600 hover:bg-flame-orange-700 text-center">
                                        Start Free
                                    </a>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </header>
    );
}
