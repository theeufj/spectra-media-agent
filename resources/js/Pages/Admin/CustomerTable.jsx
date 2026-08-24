import React from 'react';
import { router, Link } from '@inertiajs/react';
import DataTable from '@/Components/DataTable';
import ConfirmationModal from '@/Components/ConfirmationModal';

// Same traffic-light language as the workspace page: green has data,
// orange partial, red empty. One dot per load-bearing area.
const COVERAGE_AREAS = [
    ['brand', 'Brand guidelines'],
    ['knowledge', 'Knowledge base'],
    ['campaigns', 'Campaigns signed off'],
    ['creative', 'Creative (copy + imagery)'],
    ['keywords', 'Keywords'],
];
const DOT_COLORS = { green: 'bg-green-500', orange: 'bg-orange-400', red: 'bg-red-500' };

const CoverageDots = ({ customer }) => (
    <Link
        href={route('admin.customers.workspace', customer.id)}
        title="Open workspace review"
        className="inline-flex items-center gap-1.5"
    >
        {COVERAGE_AREAS.map(([key, label]) => (
            <span
                key={key}
                title={`${label}: ${customer.coverage?.[key] === 'green' ? 'has data' : customer.coverage?.[key] === 'orange' ? 'partial' : 'empty'}`}
                className={`inline-block w-2.5 h-2.5 rounded-full ${DOT_COLORS[customer.coverage?.[key]] || 'bg-gray-300'}`}
            />
        ))}
    </Link>
);

const CustomerTable = ({ customers, plans = [] }) => {
    const [confirmModal, setConfirmModal] = React.useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });

    // The server requires the customer's name typed back exactly. A dialog is
    // advice; the typed name is the part an accidental click cannot satisfy —
    // and deletion pauses every live campaign first, so it is not reversible in
    // the sense of the ads simply carrying on.
    const handleDeleteCustomer = (customerId, customerName) => {
        const typed = window.prompt(
            `This pauses every live campaign for "${customerName}" and removes them from the console.\n\n`
            + `Type the customer name exactly to confirm:`
        );

        if (typed === null) {
            return;
        }

        if (typed !== customerName) {
            setConfirmModal({
                show: true,
                title: 'Name did not match',
                message: `Nothing was deleted. Expected "${customerName}".`,
                isDestructive: false,
                onConfirm: () => setConfirmModal(prev => ({ ...prev, show: false })),
            });

            return;
        }

        router.delete(route('admin.customers.delete', customerId), {
            data: { confirm_name: typed },
            preserveScroll: true,
        });
    };

    const handleAssignPlan = (userId, planId) => {
        router.post(route('admin.users.assign-plan', userId), {
            plan_id: planId || null,
        }, { preserveScroll: true });
    };

    const customerHeaders = ['Business Name', 'Coverage', 'Owner', 'Email', 'Plan', 'Campaigns', 'Created At', 'Actions'];
    const customerData = customers.map(customer => {
        const owner = customer.users?.[0];
        return [
        customer.business_name || 'Unnamed',
        <CoverageDots customer={customer} />,
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
            <Link
                href={route('admin.customers.workspace', customer.id)}
                className="text-purple-600 hover:text-purple-900 font-medium"
            >
                Workspace
            </Link>
            <Link
                href={route('admin.customers.credit-ledger', customer.id)}
                className="text-blue-600 hover:text-blue-900 font-medium"
            >
                Ledger
            </Link>
            <button
                onClick={() => handleDeleteCustomer(customer.id, customer.business_name || customer.name)}
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
