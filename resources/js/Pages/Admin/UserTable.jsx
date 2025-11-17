import React from 'react';
import { router } from '@inertiajs/react';
import DataTable from '@/Components/DataTable';

const UserTable = ({ users }) => {
    const handlePromote = (userId) => {
        router.post(route('admin.users.promote', userId), {}, { preserveScroll: true });
    };

    const handleBanUser = (userId) => {
        if (confirm('Are you sure you want to ban this user?')) {
            router.post(route('admin.users.ban', userId), {}, { preserveScroll: true });
        }
    };

    const handleUnbanUser = (userId) => {
        router.post(route('admin.users.unban', userId), {}, { preserveScroll: true });
    };

    const userHeaders = ['Name', 'Email', 'Roles', 'Status', 'Actions'];
    const userData = users.map(user => [
        user.name,
        user.email,
        user.roles.map(role => role.name).join(', '),
        user.banned_at ? 'Banned' : 'Active',
        <div className="text-right">
            {!user.roles.some(role => role.name === 'admin') && (
                <button onClick={() => handlePromote(user.id)} className="text-indigo-600 hover:text-indigo-900 mr-4">Promote</button>
            )}
            {user.banned_at ? (
                <button onClick={() => handleUnbanUser(user.id)} className="text-green-600 hover:text-green-900">Unban</button>
            ) : (
                <button onClick={() => handleBanUser(user.id)} className="text-red-600 hover:text-red-900">Ban</button>
            )}
        </div>
    ]);

    return (
        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-8">
            <div className="p-6 text-gray-900">
                <h3 className="text-lg font-medium text-gray-900 mb-4">User Management</h3>
                <DataTable headers={userHeaders} data={userData} />
            </div>
        </div>
    );
};

export default UserTable;
