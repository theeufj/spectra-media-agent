import React from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import ConfirmationModal from '@/Components/ConfirmationModal';
import SideNav from '../SideNav';

export default function Index({ auth, platforms }) {
    const [confirmModal, setConfirmModal] = React.useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
    const { post, processing } = useForm();

    const handleToggle = (platformId) => {
        setConfirmModal({
            show: true,
            title: 'Toggle Platform Status',
            message: 'Are you sure you want to toggle this platform status?',
            onConfirm: () => {
                setConfirmModal({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
                post(route('admin.platforms.toggle', platformId));
            },
            isDestructive: false,
            confirmText: 'Toggle'
        });
    };

    const handleDelete = (platformId) => {
        setConfirmModal({
            show: true,
            title: 'Delete Platform',
            message: 'Are you sure you want to delete this platform? This action cannot be undone.',
            onConfirm: () => {
                setConfirmModal({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
                post(route('admin.platforms.destroy', platformId), {
                    method: 'delete',
                });
            },
            isDestructive: true,
            confirmText: 'Delete',
            confirmButtonClass: 'bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800'
        });
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-jet leading-tight">Platform Management</h2>}
        >
            <Head title="Platform Management" />

            <ConfirmationModal
                show={confirmModal.show}
                onClose={() => setConfirmModal({ show: false, title: '', message: '', onConfirm: null, isDestructive: false })}
                onConfirm={confirmModal.onConfirm}
                title={confirmModal.title}
                message={confirmModal.message}
                confirmText={confirmModal.confirmText}
                isDestructive={confirmModal.isDestructive}
                confirmButtonClass={confirmModal.confirmButtonClass}
            />

            <div className="flex">
                <SideNav />
                <div className="flex-1 py-12">
                    <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                        <div className="mb-6 flex justify-end">
                            <Link href={route('admin.platforms.create')}>
                                <PrimaryButton>Add New Platform</PrimaryButton>
                            </Link>
                        </div>

                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Name
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Slug
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Description
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Sort Order
                                        </th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {platforms.map((platform) => (
                                        <tr key={platform.id}>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                {platform.name}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {platform.slug}
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-500">
                                                {platform.description || 'N/A'}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm">
                                                <span
                                                    className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                                                        platform.is_enabled
                                                            ? 'bg-green-100 text-green-800'
                                                            : 'bg-red-100 text-red-800'
                                                    }`}
                                                >
                                                    {platform.is_enabled ? 'Enabled' : 'Disabled'}
                                                </span>
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {platform.sort_order}
                                            </td>
                                            <td className="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                                <button
                                                    onClick={() => handleToggle(platform.id)}
                                                    disabled={processing}
                                                    className="text-indigo-600 hover:text-indigo-900"
                                                >
                                                    Toggle
                                                </button>
                                                <Link
                                                    href={route('admin.platforms.edit', platform.id)}
                                                    className="text-blue-600 hover:text-blue-900"
                                                >
                                                    Edit
                                                </Link>
                                                <button
                                                    onClick={() => handleDelete(platform.id)}
                                                    disabled={processing}
                                                    className="text-red-600 hover:text-red-900"
                                                >
                                                    Delete
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>

                            {platforms.length === 0 && (
                                <div className="text-center py-8 text-gray-500">
                                    No platforms found. Click "Add New Platform" to create one.
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </AuthenticatedLayout>
    );
}
