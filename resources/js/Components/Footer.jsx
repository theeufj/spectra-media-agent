import React from 'react';
import { Link } from '@inertiajs/react';

export default function Footer() {
    return (
        <footer className="bg-gray-900">
            <div className="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
                <div className="grid grid-cols-2 md:grid-cols-5 gap-8">
                    {/* Brand */}
                    <div className="col-span-2 md:col-span-1">
                        <Link href="/" className="text-xl font-bold text-white">sitetospend</Link>
                        <p className="mt-3 text-sm text-gray-400 leading-relaxed">
                            AI-powered digital marketing that delivers agency-level results at a fraction of the cost.
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

                    {/* Help */}
                    <div>
                        <h3 className="text-sm font-semibold text-gray-300 uppercase tracking-wider">Help</h3>
                        <ul className="mt-4 space-y-3">
                            <li><Link href="/help" className="text-sm text-gray-400 hover:text-white transition-colors">Help Center</Link></li>
                            <li><Link href="/help/how-conversion-tracking-works" className="text-sm text-gray-400 hover:text-white transition-colors">Conversion Tracking</Link></li>
                            <li><Link href="/help/how-ai-agents-work" className="text-sm text-gray-400 hover:text-white transition-colors">How AI Agents Work</Link></li>
                            <li><Link href="/help/getting-started" className="text-sm text-gray-400 hover:text-white transition-colors">Getting Started</Link></li>
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
                    <p className="text-sm text-gray-500">&copy; {new Date().getFullYear()} sitetospend. All rights reserved.</p>
                    <div className="mt-4 sm:mt-0 flex items-center space-x-6">
                        {/* <a href="https://proveably.com/portal/public/trust/b294b3b8-245a-469c-a8fc-e478db31c249" target="_blank" rel="noopener noreferrer">
                            <img src="https://proveably.com/portal/public/trust/b294b3b8-245a-469c-a8fc-e478db31c249/badge.svg" alt="Secured by Proveably" className="h-8" />
                        </a> */}
                        <Link href={route('terms')} className="text-sm text-gray-500 hover:text-gray-300">Terms</Link>
                        <Link href={route('privacy')} className="text-sm text-gray-500 hover:text-gray-300">Privacy</Link>
                    </div>
                </div>
            </div>
        </footer>
    );
}
