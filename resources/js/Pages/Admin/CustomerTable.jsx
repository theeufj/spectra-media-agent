import React from 'react';
import { router, Link } from '@inertiajs/react';
import DataTable from '@/Components/DataTable';
import ConfirmationModal from '@/Components/ConfirmationModal';

const CustomerTable = ({ customers, plans = [] }) => {
    const [confirmModal, setConfirmModal] = React.useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });

    const handleDeleteCustomer = (customerId) => {
        setConfirmModal({
            show: true,
            title: 'Delete Customer',
            message: 'Are you sure you want to delete this customer?',
            isDestructive: true,
            onConfirm: () => {
                setConfirmModal(prev => ({ ...prev, show: false }));
                router.delete(route('admin.customers.delete', customerId), { preserveScroll: true });
            },
        });
    };

    const handleAssignPlan = (userId, planId) => {
        router.post(route('admin.users.assign-plan', userId), {
            plan_id: planId || null,
        }, { preserveScroll: true });
    };

    const customerHeaders = ['Business Name', 'Owner', 'Email', 'Plan', 'Campaigns', 'Created At', 'Actions'];
    const customerData = customers.map(customer => {
        const owner = customer.users?.[0];
        return [
        customer.business_name || 'Unnamed',
        owner?.name || 'N/A',
        owner?.email || 'N/A',
        owner ? (
            <select
                value={owner.assigned_plan_id || ''}
                onChange={(e) => handleAssignPlan(owner.id, e.target.value)}
                className="text-sm border border-gray-300 rounded px-2 py-1"
            >
                <option value="">— No plan —</option>
                {plans.map(plan => (
                    <option key={plan.id} value={plan.id}>
                        {plan.name} ({plan.formatted_price})
                    </option>
                ))}
            </select>
        ) : 'N/A',
        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
            {customer.campaigns_count || 0} campaigns
        </span>,
        new Date(customer.created_at).toLocaleDateString(),
        <div className="flex gap-2">
            <Link 
                href={route('admin.customers.show', customer.id)} 
                className="text-flame-orange-600 hover:text-flame-orange-900 font-medium"
            >
                View
            </Link>
            <button 
                onClick={() => handleDeleteCustomer(customer.id)} 
                className="text-red-600 hover:text-red-900"
            >
                Delete
            </button>
        </div>
    ];});

    return (
        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6 text-gray-900">
                <h3 className="text-lg font-medium text-gray-900 mb-4">Customer List ({customers.length})</h3>
                <DataTable headers={customerHeaders} data={customerData} />
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

export default CustomerTable;
