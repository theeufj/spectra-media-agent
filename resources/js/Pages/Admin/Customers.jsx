import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage } from '@inertiajs/react';
import SideNav from './SideNav';
import CustomerTable from './CustomerTable';

export default function Customers({ auth }) {
    const { customers } = usePage().props;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Admin - Customers</h2>}
        >
            <Head title="Admin - Customers" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 py-12">
                    <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                        <CustomerTable customers={customers} />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
