import React from 'react';
import { Link } from '@inertiajs/react';
import { useTenant } from '@/hooks/useTenant';

export default function Footer() {
    const tenant = useTenant();

    return (
        <footer className="bg-gray-900">
            <div className="mx-auto max-w-7xl px-4 py-10 sm:px-6 sm:py-12 lg:px-8">
                <div className="grid grid-cols-2 gap-x-6 gap-y-8 md:grid-cols-5 md:gap-8">
                    {/* Brand */}
                    <div className="col-span-2 md:col-span-1">
                        <Link href="/" className="inline-block py-2 text-xl font-bold text-white">{tenant.logo_text}</Link>
                        <p className="mt-3 text-sm text-gray-300 leading-relaxed">
                            {tenant.tagline}
                        </p>
                    </div>

                    {/* Product */}
                    <div>
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-white">Product</h3>
                        <ul className="mt-2 sm:mt-3">
                            <li><Link href="/features" className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">Features</Link></li>
                            <li><Link href="/how-it-works" className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">How It Works</Link></li>
                            <li><Link href="/pricing" className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">Pricing</Link></li>
                        </ul>
                    </div>

                    {/* Blog */}
                    <div>
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-white">Blog</h3>
                        <ul className="mt-2 sm:mt-3">
                            <li><Link href="/blog" className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">All Articles</Link></li>
                            <li><Link href="/blog/how-conversion-tracking-works" className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">Conversion Tracking</Link></li>
                            <li><Link href="/blog/how-ai-agents-work" className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">How AI Agents Work</Link></li>
                            <li><Link href="/blog/getting-started" className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">Getting Started</Link></li>
                        </ul>
                    </div>

                    {/* Company */}
                    <div>
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-white">Company</h3>
                        <ul className="mt-2 sm:mt-3">
                            <li><Link href="/about" className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">About</Link></li>
                            <li><Link href={route('terms')} className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">Terms of Service</Link></li>
                            <li><Link href={route('privacy')} className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">Privacy Policy</Link></li>
                        </ul>
                    </div>

                    {/* Get Started */}
                    <div>
                        <h3 className="text-sm font-semibold uppercase tracking-wider text-white">Get Started</h3>
                        <ul className="mt-2 sm:mt-3">
                            <li><a href="/register" className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">Sign Up Free</a></li>
                            <li><a href="/login" className="block py-3 text-sm text-gray-300 transition-colors hover:text-white">Log In</a></li>
                        </ul>
                    </div>
                </div>

                <div className="mt-8 border-t border-gray-800 pt-6 sm:mt-12 sm:pt-8 flex flex-col sm:flex-row justify-between items-center">
                    <p className="text-sm text-gray-300">&copy; {new Date().getFullYear()} {tenant.name}. All rights reserved.</p>
                    <div className="mt-4 sm:mt-0 flex items-center space-x-6">
                        <Link href={route('terms')} className="inline-block py-3 text-sm text-gray-300 transition-colors hover:text-white">Terms</Link>
                        <Link href={route('privacy')} className="inline-block py-3 text-sm text-gray-300 transition-colors hover:text-white">Privacy</Link>
                    </div>
                </div>
            </div>
        </footer>
    );
}
