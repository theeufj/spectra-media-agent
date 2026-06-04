import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';
import SideNav from './SideNav';

const fmt = (n) => '$' + Number(n).toFixed(2);
const fmtDate = (iso) => new Date(iso).toLocaleDateString('en-AU', { day: '2-digit', month: 'short', year: 'numeric' });

const typeMeta = {
    credit:     { label: 'Credit purchased', side: 'credit', color: 'text-green-700' },
    refund:     { label: 'Refund',            side: 'credit', color: 'text-green-700' },
    deduction:  { label: 'Daily spend',       side: 'debit',  color: 'text-red-700'   },
    adjustment: { label: 'Reconciliation',    side: 'debit',  color: 'text-red-700'   },
};

export default function CustomerCreditLedger({ auth }) {
    const { customer, credit, transactions } = usePage().props;
    const [expanded, setExpanded] = useState(null);

    const toggle = (id) => setExpanded(prev => prev === id ? null : id);

    const title = customer.business_name || customer.name || `Customer #${customer.id}`;

    return (
        <AuthenticatedLayout auth={auth}>
            <Head title={`Credit Ledger — ${title}`} />
            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8 space-y-6 overflow-auto">

                    {/* Header */}
                    <div className="flex items-center justify-between">
                        <div>
                            <Link
                                href={route('admin.customers.index')}
                                className="text-sm text-gray-500 hover:text-gray-700"
                            >
                                ← Customers
                            </Link>
                            <h1 className="text-2xl font-bold text-gray-900 mt-1">{title} — Credit Ledger</h1>
                        </div>
                        <div className="flex gap-6 text-right">
                            <div>
                                <p className="text-xs text-gray-500 uppercase tracking-wide">Balance</p>
                                <p className={`text-xl font-bold ${credit.current_balance >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                    {fmt(credit.current_balance)}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-gray-500 uppercase tracking-wide">Status</p>
                                <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-1 ${credit.payment_status === 'current' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
                                    {credit.payment_status}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Summary totals */}
                    <div className="grid grid-cols-2 gap-4">
                        <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                            <p className="text-xs font-medium text-green-700 uppercase tracking-wide">Total Credits Purchased</p>
                            <p className="text-2xl font-bold text-green-800 mt-1">{fmt(credit.total_credits)}</p>
                        </div>
                        <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                            <p className="text-xs font-medium text-red-700 uppercase tracking-wide">Total Credits Spent</p>
                            <p className="text-2xl font-bold text-red-800 mt-1">{fmt(credit.total_debits)}</p>
                        </div>
                    </div>

                    {/* Ledger table */}
                    {transactions.length === 0 ? (
                        <div className="bg-white shadow rounded-lg p-8 text-center text-gray-500">
                            No transactions yet.
                        </div>
                    ) : (
                        <div className="bg-white shadow rounded-lg overflow-hidden">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Date</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-green-700 uppercase tracking-wider w-32">Credit</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-red-700 uppercase tracking-wider w-32">Debit</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider w-32">Balance</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {transactions.map((tx) => {
                                        const meta = typeMeta[tx.type] || { label: tx.type, side: 'debit', color: 'text-gray-700' };
                                        const isDebit = meta.side === 'debit';
                                        const hasBreakdown = isDebit && tx.platform_breakdown?.some(p => p.spend > 0);
                                        const isOpen = expanded === tx.id;

                                        return (
                                            <React.Fragment key={tx.id}>
                                                <tr
                                                    className={`${hasBreakdown ? 'cursor-pointer hover:bg-gray-50' : ''} ${isOpen ? 'bg-gray-50' : ''}`}
                                                    onClick={() => hasBreakdown && toggle(tx.id)}
                                                >
                                                    <td className="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">{fmtDate(tx.created_at)}</td>
                                                    <td className="px-4 py-3 text-sm text-gray-900">
                                                        <div className="flex items-center gap-2">
                                                            <span className={`text-xs font-medium px-2 py-0.5 rounded-full ${isDebit ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'}`}>
                                                                {meta.label}
                                                            </span>
                                                            <span className="text-gray-600 truncate max-w-xs">{tx.description}</span>
                                                            {hasBreakdown && (
                                                                <span className="ml-auto text-xs text-gray-400">{isOpen ? '▲ Hide' : '▼ Platforms'}</span>
                                                            )}
                                                        </div>
                                                        {tx.stripe_charge_id && (
                                                            <div className="text-xs text-gray-400 mt-0.5 font-mono">{tx.stripe_charge_id}</div>
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-right font-medium text-green-700 whitespace-nowrap">
                                                        {!isDebit ? fmt(tx.amount) : '—'}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-right font-medium text-red-700 whitespace-nowrap">
                                                        {isDebit ? fmt(tx.amount) : '—'}
                                                    </td>
                                                    <td className="px-4 py-3 text-sm text-right font-semibold text-gray-900 whitespace-nowrap">
                                                        {fmt(tx.balance_after)}
                                                    </td>
                                                </tr>

                                                {/* Platform breakdown row */}
                                                {isOpen && (
                                                    <tr className="bg-gray-50">
                                                        <td></td>
                                                        <td colSpan={4} className="px-4 pb-3 pt-1">
                                                            <div className="flex gap-6 pl-2 border-l-2 border-red-200">
                                                                {tx.platform_breakdown.map(p => (
                                                                    <div key={p.platform} className="text-sm">
                                                                        <span className="text-gray-500">{p.platform}: </span>
                                                                        <span className="font-medium text-gray-900">{fmt(p.spend)}</span>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                )}
                                            </React.Fragment>
                                        );
                                    })}

                                    {/* Totals footer */}
                                    <tr className="bg-gray-50 font-semibold border-t-2 border-gray-300">
                                        <td className="px-4 py-3 text-sm text-gray-700" colSpan={2}>Totals</td>
                                        <td className="px-4 py-3 text-sm text-right text-green-700">{fmt(credit.total_credits)}</td>
                                        <td className="px-4 py-3 text-sm text-right text-red-700">{fmt(credit.total_debits)}</td>
                                        <td className="px-4 py-3 text-sm text-right text-gray-900">{fmt(credit.current_balance)}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    )}

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
