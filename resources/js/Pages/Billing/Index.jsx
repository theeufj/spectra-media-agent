
import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import SubscriptionTierSelector from '@/Components/SubscriptionTierSelector';
import InvoiceHistory from '@/Components/InvoiceHistory';

const Index = ({ auth }) => {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Billing</h2>}
        >
            <Head title="Billing" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                    <SubscriptionTierSelector />
                    <InvoiceHistory />
                </div>
            </div>
        </AuthenticatedLayout>
    );
};

export default Index;
