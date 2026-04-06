import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function List({ products = [], filter }) {
    const statusColors = {
        approved: 'bg-green-100 text-green-700',
        disapproved: 'bg-red-100 text-red-700',
        pending: 'bg-yellow-100 text-yellow-700',
        expiring: 'bg-orange-100 text-orange-700',
    };

    const filters = [
        { label: 'All', value: '' },
        { label: 'Approved', value: 'approved' },
        { label: 'Disapproved', value: 'disapproved' },
        { label: 'Pending', value: 'pending' },
    ];

    return (
        <AuthenticatedLayout>
            <Head title="Products" />
            <div className="py-8">
                <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center justify-between mb-6">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-900">Products</h1>
                            <p className="mt-1 text-sm text-gray-500">{products.length} products shown</p>
                        </div>
                        <a href={route('products.index')} className="text-sm text-gray-500 hover:text-gray-700">← Feeds</a>
                    </div>

                    {/* Filters */}
                    <div className="flex gap-2 mb-4">
                        {filters.map(f => (
                            <a key={f.value} href={route('products.list', f.value ? { status: f.value } : {})} className={`text-xs px-3 py-1.5 rounded-lg ${(filter || '') === f.value ? 'bg-flame-orange-100 text-flame-orange-700 font-medium' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'}`}>{f.label}</a>
                        ))}
                    </div>

                    {products.length > 0 ? (
                        <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Product</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Price</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Availability</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Clicks</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Conv.</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {products.map(p => (
                                        <tr key={p.id} className="hover:bg-gray-50">
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-3">
                                                    {p.image_link && <img src={p.image_link} alt="" className="w-10 h-10 rounded object-cover" />}
                                                    <div>
                                                        <p className="text-sm font-medium text-gray-900 line-clamp-1">{p.title}</p>
                                                        <p className="text-xs text-gray-400">{p.brand || p.offer_id}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right text-sm">
                                                {p.sale_price ? (
                                                    <div><span className="line-through text-gray-400">${parseFloat(p.price).toFixed(2)}</span> <span className="text-red-600">${parseFloat(p.sale_price).toFixed(2)}</span></div>
                                                ) : (
                                                    <span className="text-gray-900">{p.price ? `$${parseFloat(p.price).toFixed(2)}` : '—'}</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3"><span className={`text-xs px-2 py-0.5 rounded ${p.availability === 'in_stock' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>{(p.availability || '').replace('_', ' ')}</span></td>
                                            <td className="px-4 py-3"><span className={`text-xs px-2 py-0.5 rounded ${statusColors[p.status] || 'bg-gray-100 text-gray-500'}`}>{p.status}</span></td>
                                            <td className="px-4 py-3 text-right text-sm text-gray-600">{p.clicks?.toLocaleString() || 0}</td>
                                            <td className="px-4 py-3 text-right text-sm text-gray-600">{p.conversions || 0}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="text-center py-16 bg-white rounded-lg border border-gray-200">
                            <h3 className="text-sm font-medium text-gray-900">No products found</h3>
                            <p className="mt-1 text-sm text-gray-500">Sync a product feed to see products here.</p>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
