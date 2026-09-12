import React from 'react';
import { router, Link } from '@inertiajs/react';
import DataTable from '@/Components/DataTable';
import ConfirmationModal from '@/Components/ConfirmationModal';

/*
 * Per-customer setup coverage: five areas, each complete / partial / empty.
 *
 * This was five 10px dots in green, orange and red, and colour was the only
 * thing carrying the state. Two problems, both measurable:
 *
 *   - Run through the palette validator, green-500 against orange-400 is ΔE 7.0
 *     under protanopia. That is inside the 6–8 band which is permissible *only*
 *     with a secondary encoding, and there was none — a protan reader saw five
 *     dots in two indistinguishable colours.
 *   - Both sit at about 2.2:1 against the page, under the 3:1 a meaningful
 *     graphic needs, which obliges a visible label.
 *
 * And nothing said what the five positions meant. The only explanation was a
 * `title` attribute, which never reaches a keyboard or a touch device.
 *
 * So: shape carries the state as well as colour — filled, half, hollow — a
 * visible "n/5" gives the summary without reference to colour at all, the group
 * announces the full readout to a screen reader, and there is a legend.
 */
const COVERAGE_AREAS = [
    ['brand', 'Brand guidelines'],
    ['knowledge', 'Knowledge base'],
    ['campaigns', 'Campaigns signed off'],
    ['creative', 'Creative (copy + imagery)'],
    ['keywords', 'Keywords'],
];

const COVERAGE_STATES = {
    green: { label: 'complete', className: 'bg-green-600 border-green-600' },
    orange: { label: 'partial', className: 'border-orange-500 bg-gradient-to-r from-orange-500 from-50% to-transparent to-50%' },
    red: { label: 'not started', className: 'border-red-500 bg-white' },
};
const UNKNOWN_STATE = { label: 'unknown', className: 'border-gray-300 bg-white' };

const stateOf = (customer, key) => COVERAGE_STATES[customer.coverage?.[key]] ?? UNKNOWN_STATE;

export function CoverageLegend() {
    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
            <span className="font-medium text-gray-700">Coverage:</span>
            {COVERAGE_AREAS.map(([key, label], i) => (
                <span key={key}>
                    {i + 1}. {label}
                </span>
            ))}
            <span className="flex items-center gap-3 border-l border-gray-200 pl-4">
                {Object.entries(COVERAGE_STATES).map(([key, state]) => (
                    <span key={key} className="flex items-center gap-1.5">
                        <span className={`inline-block h-2.5 w-2.5 rounded-full border-2 ${state.className}`} aria-hidden="true" />
                        {state.label}
                    </span>
                ))}
            </span>
        </div>
    );
}

const CoverageDots = ({ customer }) => {
    const complete = COVERAGE_AREAS.filter(([key]) => customer.coverage?.[key] === 'green').length;

    // The readout a screen reader gets, and the same thing the legend explains.
    const readout = COVERAGE_AREAS
        .map(([key, label]) => `${label}: ${stateOf(customer, key).label}`)
        .join('. ');

    return (
        <Link
            href={route('admin.customers.workspace', customer.uuid)}
            className="inline-flex items-center gap-2 rounded focus:outline-none focus:ring-2 focus:ring-brand-dark"
            aria-label={`Open workspace review. ${readout}`}
        >
            <span className="inline-flex items-center gap-1.5" aria-hidden="true">
                {COVERAGE_AREAS.map(([key]) => (
                    <span
                        key={key}
                        className={`inline-block h-2.5 w-2.5 rounded-full border-2 ${stateOf(customer, key).className}`}
                    />
                ))}
            </span>
            {/* The colour-independent summary. */}
            <span className="text-xs tabular-nums text-gray-600" aria-hidden="true">
                {complete}/{COVERAGE_AREAS.length}
            </span>
        </Link>
    );
};

const CustomerTable = ({ customers, plans = [] }) => {
    const [confirmModal, setConfirmModal] = React.useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });

    // The server requires the customer's name typed back exactly. A dialog is
    // advice; the typed name is the part an accidental click cannot satisfy —
    // and deletion pauses every live campaign first, so it is not reversible in
    // the sense of the ads simply carrying on.
    const handleDeleteCustomer = (customerUuid, customerName) => {
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

        router.delete(route('admin.customers.delete', customerUuid), {
            data: { confirm_name: typed },
            preserveScroll: true,
        });
    };

    const handleImpersonate = (customer) => {
        setConfirmModal({
            show: true,
            title: 'Impersonate Customer',
            message: `You will be logged in as the owner of "${customer.business_name || customer.name}" with their workspace active. Continue?`,
            isDestructive: false,
            onConfirm: () => {
                setConfirmModal(prev => ({ ...prev, show: false }));
                router.post(route('admin.impersonation.start-customer', customer.uuid));
            },
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
        // `business_name` is not a column on customers and never has been —
        // Eloquent returns null for an undefined attribute rather than raising,
        // so every row in this table read "Unnamed". The column is `name`.
        customer.name || 'Unnamed',
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
        <span className="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
            {customer.campaigns_count || 0} {(customer.campaigns_count || 0) === 1 ? 'campaign' : 'campaigns'}
        </span>,
        new Date(customer.created_at).toLocaleDateString(),
        <div className="flex gap-2">
            <Link
                href={route('admin.customers.show', customer.uuid)}
                className="text-brand-dark hover:text-brand-darker font-medium"
            >
                View
            </Link>
            <Link
                href={route('admin.customers.workspace', customer.uuid)}
                className="text-purple-600 hover:text-purple-900 font-medium"
            >
                Workspace
            </Link>
            <Link
                href={route('admin.customers.credit-ledger', customer.uuid)}
                className="text-blue-600 hover:text-blue-900 font-medium"
            >
                Ledger
            </Link>
            <button
                onClick={() => handleImpersonate(customer)}
                className="text-purple-600 hover:text-purple-900 font-medium"
                title="Log in as this customer's owner"
            >
                Impersonate
            </button>
            <button
                onClick={() => handleDeleteCustomer(customer.uuid, customer.business_name || customer.name)}
                className="text-red-600 hover:text-red-900"
            >
                Delete
            </button>
        </div>
    ];});

    return (
        <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div className="p-6 text-gray-900">
                <h3 className="text-lg font-medium text-gray-900">Customer list ({customers.length})</h3>
                {/* The five dots meant nothing without this. */}
                <div className="mb-4 mt-2">
                    <CoverageLegend />
                </div>
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
