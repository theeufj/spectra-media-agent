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
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            You're logged in!
                        </div>
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
