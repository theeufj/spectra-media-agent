import React from 'react';
import { router, usePage } from '@inertiajs/react';

export default function ImpersonationBanner() {
    const { impersonation } = usePage().props;

    if (!impersonation?.isImpersonating) {
        return null;
    }

    const stopImpersonating = () => {
        router.post(route('admin.impersonation.stop'));
    };

    return (
        <div className="bg-yellow-500 text-yellow-900">
            <div className="max-w-7xl mx-auto py-2 px-3 sm:px-6 lg:px-8">
                <div className="flex items-center justify-between flex-wrap">
                    <div className="w-0 flex-1 flex items-center">
                        <span className="flex p-1 rounded-lg bg-yellow-600">
                            <svg className="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </span>
                        <p className="ml-3 font-medium text-sm">
                            <span className="hidden md:inline">
                                You are currently impersonating <strong>{impersonation.userName}</strong>
                            </span>
                            <span className="md:hidden">
                                Impersonating <strong>{impersonation.userName}</strong>
                            </span>
                        </p>
                    </div>
                    <div className="order-3 mt-2 flex-shrink-0 w-full sm:order-2 sm:mt-0 sm:w-auto">
                        <button
                            onClick={stopImpersonating}
                            className="flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-yellow-600 bg-white hover:bg-yellow-50"
                        >
                            Stop Impersonating
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
