
import React, { useState, useEffect } from 'react';
import DataTable from './DataTable';
import Spinner from './Spinner';
import Card from './Card';

const InvoiceHistory = () => {
    const [invoices, setInvoices] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        // In a real application, you would fetch this data from your backend API
        // For demonstration, we are using mock data.
        const fetchInvoices = async () => {
            try {
                // const response = await axios.get('/api/invoices');
                // setInvoices(response.data);
                const mockInvoices = [
                    { id: 'inv_123', date: '2025-10-01', amount: '$99.00', status: 'Paid', url: '#' },
                    { id: 'inv_124', date: '2025-09-01', amount: '$99.00', status: 'Paid', url: '#' },
                    { id: 'inv_125', date: '2025-08-01', amount: '$99.00', status: 'Paid', url: '#' },
                ];
                setInvoices(mockInvoices);
            } catch (error) {
                console.error("Error fetching invoices:", error);
            } finally {
                setLoading(false);
            }
        };

        fetchInvoices();
    }, []);

    const headers = ['Invoice ID', 'Date', 'Amount', 'Status', 'Download'];
    const data = invoices.map(invoice => [
        invoice.id,
        invoice.date,
        invoice.amount,
        invoice.status,
        <a href={invoice.url} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">PDF</a>
    ]);

    return (
        <Card>
            <h3 className="text-lg font-semibold mb-4">Invoice History</h3>
            {loading ? (
                <Spinner />
            ) : (
                <DataTable headers={headers} data={data} />
            )}
        </Card>
    );
};

export default InvoiceHistory;
