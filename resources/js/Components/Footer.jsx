import React from 'react';
import { Link } from '@inertiajs/react';

export default function Footer() {
    return (
        <footer className="bg-white">
            <div className="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
                <div className="flex justify-center space-x-6">
                    <Link href={route('terms')} className="text-base text-gray-500 hover:text-gray-900">
                        Terms of Service
                    </Link>
                    <Link href={route('privacy')} className="text-base text-gray-500 hover:text-gray-900">
                        Privacy Policy
                    </Link>
                </div>
                <p className="mt-8 text-center text-base text-gray-400">&copy; 2025 sitetospend. All rights reserved.</p>
            </div>
        </footer>
    );
}
