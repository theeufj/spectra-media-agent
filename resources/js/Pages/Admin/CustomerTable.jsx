import React from 'react';
import { router, Link } from '@inertiajs/react';
import DataTable from '@/Components/DataTable';

const CustomerTable = ({ customers }) => {
    const handleDeleteCustomer = (customerId) => {
        if (confirm('Are you sure you want to delete this customer?')) {
            router.delete(route('admin.customers.delete', customerId), { preserveScroll: true });
        }
    };

    const customerHeaders = ['Business Name', 'Owner', 'Email', 'Campaigns', 'Created At', 'Actions'];
    const customerData = customers.map(customer => [
        customer.business_name || 'Unnamed',
        customer.users?.[0]?.name || 'N/A',
        customer.users?.[0]?.email || 'N/A',
        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
            {customer.campaigns_count || 0} campaigns
        </span>,
        new Date(customer.created_at).toLocaleDateString(),
        <div className="flex gap-2">
            <Link 
                href={route('admin.customers.show', customer.id)} 
                className="text-indigo-600 hover:text-indigo-900 font-medium"
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
    ]);

    return (
        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6 text-gray-900">
                <h3 className="text-lg font-medium text-gray-900 mb-4">Customer List ({customers.length})</h3>
                <DataTable headers={customerHeaders} data={customerData} />
            </div>
        </div>
    );
};

export default CustomerTable;
