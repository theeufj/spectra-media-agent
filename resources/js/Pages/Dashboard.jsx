import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import SitemapSubmittedModal from '@/Components/SitemapSubmittedModal';

export default function Dashboard() {
    const { flash } = usePage().props;
    const [showSitemapModal, setShowSitemapModal] = useState(false);

    useEffect(() => {
        if (flash?.sitemap_submitted) {
            setShowSitemapModal(true);
        }
    }, [flash]);

    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('verified') === '1' && typeof gtag === 'function') {
            gtag('event', 'conversion', {
                send_to: 'AW-18115663500/JPlcCMyP26YcEIytnL5D',
                value: 99,
                currency: 'USD',
            });
        }
    }, []);

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Dashboard
                </h2>
            }
        >
            <Head title="Dashboard" />

            <div className="py-6 sm:py-12">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            You're logged in!
                        </div>
                    </div>

                    {/* Sandbox CTA */}
                    <div className="bg-gradient-to-r from-violet-600 to-indigo-600 rounded-xl p-6 text-white flex items-center justify-between">
                        <div>
                            <h3 className="text-lg font-bold">Try Our AI Agents — Risk Free</h3>
                            <p className="text-violet-100 text-sm mt-1">
                                Launch a sandbox with realistic campaigns and see how our agents optimize, alert, and heal.
                            </p>
                        </div>
                        <a
                            href={route('sandbox.index')}
                            className="shrink-0 px-5 py-2.5 bg-white text-violet-700 font-semibold text-sm rounded-lg hover:bg-violet-50 transition"
                        >
                            Launch Sandbox
                        </a>
                    </div>
                </div>
            </div>

            <SitemapSubmittedModal
                show={showSitemapModal}
                onClose={() => setShowSitemapModal(false)}
            />
        </AuthenticatedLayout>
    );
}
