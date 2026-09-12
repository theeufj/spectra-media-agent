import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import SideNav from './SideNav';
import AdminNotificationForm from './AdminNotificationForm';

export default function Notifications({ auth }) {
    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight" contained={false}>Admin - Notifications</h2>}
        >
            <Head title="Admin - Notifications" />

            <div className="flex flex-col lg:flex-row">
                <SideNav />
                <div className="min-w-0 flex-1 py-12">
                    <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                        <AdminNotificationForm />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
