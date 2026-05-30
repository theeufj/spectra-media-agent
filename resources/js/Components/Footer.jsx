import React from 'react';
import { Link } from '@inertiajs/react';
import { useTenant } from '@/hooks/useTenant';

export default function Footer() {
    const tenant = useTenant();

    return (
        <footer className="bg-gray-900">
            <div className="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
                <div className="grid grid-cols-2 md:grid-cols-5 gap-8">
                    {/* Brand */}
                    <div className="col-span-2 md:col-span-1">
                        <Link href="/" className="text-xl font-bold text-white">{tenant.logo_text}</Link>
                        <p className="mt-3 text-sm text-gray-400 leading-relaxed">
                            {tenant.tagline}
                        </p>
                    </div>

                    {/* Product */}
                    <div>
                        <h3 className="text-sm font-semibold text-gray-300 uppercase tracking-wider">Product</h3>
                        <ul className="mt-4 space-y-3">
                            <li><Link href="/features" className="text-sm text-gray-400 hover:text-white transition-colors">Features</Link></li>
                            <li><Link href="/how-it-works" className="text-sm text-gray-400 hover:text-white transition-colors">How It Works</Link></li>
                            <li><Link href="/pricing" className="text-sm text-gray-400 hover:text-white transition-colors">Pricing</Link></li>
                        </ul>
                    </div>

                    {/* Blog */}
                    <div>
                        <h3 className="text-sm font-semibold text-gray-300 uppercase tracking-wider">Blog</h3>
                        <ul className="mt-4 space-y-3">
                            <li><Link href="/blog" className="text-sm text-gray-400 hover:text-white transition-colors">All Articles</Link></li>
                            <li><Link href="/blog/how-conversion-tracking-works" className="text-sm text-gray-400 hover:text-white transition-colors">Conversion Tracking</Link></li>
                            <li><Link href="/blog/how-ai-agents-work" className="text-sm text-gray-400 hover:text-white transition-colors">How AI Agents Work</Link></li>
                            <li><Link href="/blog/getting-started" className="text-sm text-gray-400 hover:text-white transition-colors">Getting Started</Link></li>
                        </ul>
                    </div>

                    {/* Company */}
                    <div>
                        <h3 className="text-sm font-semibold text-gray-300 uppercase tracking-wider">Company</h3>
                        <ul className="mt-4 space-y-3">
                            <li><Link href="/about" className="text-sm text-gray-400 hover:text-white transition-colors">About</Link></li>
                            <li><Link href={route('terms')} className="text-sm text-gray-400 hover:text-white transition-colors">Terms of Service</Link></li>
                            <li><Link href={route('privacy')} className="text-sm text-gray-400 hover:text-white transition-colors">Privacy Policy</Link></li>
                        </ul>
                    </div>

                    {/* Get Started */}
                    <div>
                        <h3 className="text-sm font-semibold text-gray-300 uppercase tracking-wider">Get Started</h3>
                        <ul className="mt-4 space-y-3">
                            <li><a href="/register" className="text-sm text-gray-400 hover:text-white transition-colors">Sign Up Free</a></li>
                            <li><a href="/login" className="text-sm text-gray-400 hover:text-white transition-colors">Log In</a></li>
                        </ul>
                    </div>
                </div>

                <div className="mt-12 pt-8 border-t border-gray-800 flex flex-col sm:flex-row justify-between items-center">
                    <p className="text-sm text-gray-500">&copy; {new Date().getFullYear()} {tenant.name}. All rights reserved.</p>
                    <div className="mt-4 sm:mt-0 flex items-center space-x-6">
                        <Link href={route('terms')} className="text-sm text-gray-500 hover:text-gray-300">Terms</Link>
                        <Link href={route('privacy')} className="text-sm text-gray-500 hover:text-gray-300">Privacy</Link>
                    </div>
                </div>
            </div>
        </footer>
    );
}
