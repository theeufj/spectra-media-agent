import React from 'react';
import { router } from '@inertiajs/react';
import DataTable from '@/Components/DataTable';
import ConfirmationModal from '@/Components/ConfirmationModal';

const UserTable = ({ users, plans = [] }) => {
    const [confirmModal, setConfirmModal] = React.useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });

    const handlePromote = (userId) => {
        router.post(route('admin.users.promote', userId), {}, { preserveScroll: true });
    };

    const handleBanUser = (userId) => {
        setConfirmModal({
            show: true,
            title: 'Ban User',
            message: 'Are you sure you want to ban this user?',
            isDestructive: true,
            onConfirm: () => {
                setConfirmModal(prev => ({ ...prev, show: false }));
                router.post(route('admin.users.ban', userId), {}, { preserveScroll: true });
            },
        });
    };

    const handleUnbanUser = (userId) => {
        router.post(route('admin.users.unban', userId), {}, { preserveScroll: true });
    };

    const handleDeleteUser = (userId) => {
        setConfirmModal({
            show: true,
            title: 'Delete User',
            message: 'Are you sure you want to permanently delete this user? This action cannot be undone.',
            isDestructive: true,
            onConfirm: () => {
                setConfirmModal(prev => ({ ...prev, show: false }));
                router.delete(route('admin.users.delete', userId), { preserveScroll: true });
            },
        });
    };

    const handleImpersonate = (userId) => {
        setConfirmModal({
            show: true,
            title: 'Impersonate User',
            message: 'You will be logged in as this user. Continue?',
            onConfirm: () => {
                setConfirmModal(prev => ({ ...prev, show: false }));
                router.post(route('admin.impersonation.start', userId));
            },
        });
    };

    const handleAssignPlan = (userId, planId) => {
        router.post(route('admin.users.assign-plan', userId), {
            plan_id: planId || null,
        }, { preserveScroll: true });
    };

    const userHeaders = ['Name', 'Email', 'Roles', 'Plan', 'Status', 'Actions'];
    const userData = users.map(user => [
        user.name,
        user.email,
        user.roles.map(role => role.name).join(', '),
        <select
            value={user.assigned_plan_id || ''}
            onChange={(e) => handleAssignPlan(user.id, e.target.value)}
            className="text-sm border border-gray-300 rounded px-2 py-1"
        >
            <option value="">— No plan —</option>
            {plans.map(plan => (
                <option key={plan.id} value={plan.id}>
                    {plan.name} ({plan.formatted_price})
                </option>
            ))}
        </select>,
        user.banned_at ? 'Banned' : 'Active',
        <div className="text-right space-x-2">
            {!user.roles.some(role => role.name === 'admin') && (
                <>
                    <button 
                        onClick={() => handleImpersonate(user.id)} 
                        className="text-purple-600 hover:text-purple-900"
                        title="Impersonate user"
                    >
                        <svg className="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </button>
                    <button onClick={() => handlePromote(user.id)} className="text-flame-orange-600 hover:text-flame-orange-900">Promote</button>
                </>
            )}
            {user.banned_at ? (
                <button onClick={() => handleUnbanUser(user.id)} className="text-green-600 hover:text-green-900">Unban</button>
            ) : (
                <button onClick={() => handleBanUser(user.id)} className="text-red-600 hover:text-red-900">Ban</button>
            )}
            {!user.roles.some(role => role.name === 'admin') && (
                <button onClick={() => handleDeleteUser(user.id)} className="text-red-800 hover:text-red-950">Delete</button>
            )}
        </div>
    ]);

    return (
        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-8">
            <div className="p-6 text-gray-900">
                <h3 className="text-lg font-medium text-gray-900 mb-4">User Management</h3>
                <DataTable headers={userHeaders} data={userData} />
            </div>
            <ConfirmationModal
                show={confirmModal.show}
                onClose={() => setConfirmModal(prev => ({ ...prev, show: false }))}
                onConfirm={confirmModal.onConfirm}
                title={confirmModal.title}
                message={confirmModal.message}
                isDestructive={confirmModal.isDestructive}
            />
        </div>
    );
};

export default UserTable;
