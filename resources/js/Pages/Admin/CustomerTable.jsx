import React from 'react';
import { router } from '@inertiajs/react';
import DataTable from '@/Components/DataTable';

const CustomerTable = ({ customers }) => {
    const handleDeleteCustomer = (customerId) => {
        if (confirm('Are you sure you want to delete this customer?')) {
            router.delete(route('admin.customers.delete', customerId), { preserveScroll: true });
        }
    };

    const customerHeaders = ['User Name', 'User Email', 'Stripe ID', 'Trial Ends At', 'Created At', 'Actions'];
    const customerData = customers.map(customer => [
        customer.user ? customer.user.name : 'N/A',
        customer.user ? customer.user.email : 'N/A',
        customer.stripe_id,
        customer.trial_ends_at || 'N/A',
        customer.created_at,
        <div className="text-right">
            <button onClick={() => handleDeleteCustomer(customer.id)} className="text-red-600 hover:text-red-900">Delete</button>
        </div>
    ]);

    return (
        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6 text-gray-900">
                <h3 className="text-lg font-medium text-gray-900 mb-4">Customer List</h3>
                <DataTable headers={customerHeaders} data={customerData} />
            </div>
        </div>
    );
};

export default CustomerTable;
