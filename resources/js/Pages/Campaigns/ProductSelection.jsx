import React, { useState, useEffect } from 'react';
import axios from 'axios';
import InputLabel from '@/Components/InputLabel';

export default function ProductSelection({ customerId, selectedPages, onSelectionChange }) {
    const [pages, setPages] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');

    useEffect(() => {
        if (customerId) {
            fetchPages();
        }
    }, [customerId, search]);

    const fetchPages = async () => {
        setLoading(true);
        try {
            const response = await axios.get(route('api.customers.pages.index', { 
                customer: customerId,
                type: 'product',
                search: search
            }));
            setPages(response.data.data);
        } catch (error) {
            console.error("Failed to fetch product pages", error);
        } finally {
            setLoading(false);
        }
    };

    const togglePage = (pageId) => {
        const newSelection = selectedPages.includes(pageId)
            ? selectedPages.filter(id => id !== pageId)
            : [...selectedPages, pageId];
        onSelectionChange(newSelection);
    };

    return (
        <div className="space-y-4">
            <div className="flex justify-between items-center">
                <InputLabel value="Select Products to Advertise" />
                <input 
                    type="text" 
                    placeholder="Search products..." 
                    className="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                />
            </div>

            {loading ? (
                <div className="text-sm text-gray-500">Loading products...</div>
            ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-96 overflow-y-auto p-2 border rounded-md bg-gray-50">
                    {pages.length > 0 ? (
                        pages.map(page => (
                            <div 
                                key={page.id} 
                                className={`p-3 border rounded-lg cursor-pointer transition-colors ${
                                    selectedPages.includes(page.id) 
                                        ? 'border-indigo-500 bg-indigo-50' 
                                        : 'border-gray-200 bg-white hover:border-indigo-300'
                                }`}
                                onClick={() => togglePage(page.id)}
                            >
                                <div className="flex items-start space-x-3">
                                    <input 
                                        type="checkbox" 
                                        checked={selectedPages.includes(page.id)}
                                        onChange={() => {}} // Handled by parent div click
                                        className="mt-1 rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                                    />
                                    <div className="flex-1 min-w-0">
                                        <h4 className="text-sm font-medium text-gray-900 truncate" title={page.title}>
                                            {page.title || 'Untitled Product'}
                                        </h4>
                                        <p className="text-xs text-gray-500 truncate" title={page.url}>
                                            {page.url}
                                        </p>
                                        {page.metadata?.price && (
                                            <p className="text-xs font-semibold text-green-600 mt-1">
                                                {page.metadata.price}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ))
                    ) : (
                        <div className="col-span-2 text-center py-4 text-gray-500 text-sm">
                            No product pages found. Try crawling your site first.
                        </div>
                    )}
                </div>
            )}
            <div className="text-xs text-gray-500 text-right">
                {selectedPages.length} products selected
            </div>
        </div>
    );
}
