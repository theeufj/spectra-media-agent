import { useState, useEffect } from 'react';
import PrimaryButton from '@/Components/PrimaryButton';

/**
 * Facebook Page Selection Modal
 * 
 * Displays when a user has multiple Facebook Pages and needs to select
 * which one to use for their ads.
 */
export default function FacebookPageSelector({ isOpen, onClose, onSelect, pages: initialPages }) {
    const [pages, setPages] = useState(initialPages || []);
    const [loading, setLoading] = useState(!initialPages || initialPages.length === 0);
    const [selectedPageId, setSelectedPageId] = useState(null);
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        if (isOpen && (!initialPages || initialPages.length === 0)) {
            fetchPages();
        }
    }, [isOpen]);

    const fetchPages = async () => {
        setLoading(true);
        setError(null);
        
        try {
            const response = await fetch('/facebook/pages', {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });
            
            if (!response.ok) {
                throw new Error('Failed to fetch pages');
            }
            
            const data = await response.json();
            setPages(data.pages || []);
            
            if (data.selected) {
                setSelectedPageId(data.selected);
            }
        } catch (err) {
            setError('Failed to load Facebook Pages. Please try again.');
            console.error('Error fetching pages:', err);
        } finally {
            setLoading(false);
        }
    };

    const handleSelect = async () => {
        if (!selectedPageId) return;
        
        const selectedPage = pages.find(p => p.id === selectedPageId);
        if (!selectedPage) return;
        
        setSubmitting(true);
        setError(null);
        
        try {
            const response = await fetch('/facebook/pages/select', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    page_id: selectedPage.id,
                    page_name: selectedPage.name,
                }),
            });
            
            if (!response.ok) {
                throw new Error('Failed to select page');
            }
            
            onSelect?.(selectedPage);
            onClose?.();
        } catch (err) {
            setError('Failed to select page. Please try again.');
            console.error('Error selecting page:', err);
        } finally {
            setSubmitting(false);
        }
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {/* Backdrop */}
                <div 
                    className="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75"
                    onClick={onClose}
                />

                {/* Modal */}
                <div className="inline-block w-full max-w-lg p-6 my-8 text-left align-middle transition-all transform bg-white shadow-xl rounded-lg">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-lg font-medium text-gray-900">
                            Select Facebook Page
                        </h3>
                        <button
                            onClick={onClose}
                            className="text-gray-400 hover:text-gray-600"
                        >
                            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <p className="text-sm text-gray-600 mb-4">
                        Select which Facebook Page to use for your ad campaigns. This page will be shown on your ads.
                    </p>

                    {loading ? (
                        <div className="flex items-center justify-center py-8">
                            <svg className="animate-spin h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                            </svg>
                        </div>
                    ) : error ? (
                        <div className="text-center py-8">
                            <p className="text-red-600 mb-4">{error}</p>
                            <button
                                onClick={fetchPages}
                                className="text-blue-600 hover:text-blue-800 underline"
                            >
                                Try Again
                            </button>
                        </div>
                    ) : pages.length === 0 ? (
                        <div className="text-center py-8">
                            <p className="text-gray-600 mb-2">No Facebook Pages found.</p>
                            <p className="text-sm text-gray-500">
                                Make sure you have admin access to at least one Facebook Page.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-2 max-h-64 overflow-y-auto">
                            {pages.map((page) => (
                                <label
                                    key={page.id}
                                    className={`flex items-center p-3 border rounded-lg cursor-pointer transition-colors ${
                                        selectedPageId === page.id
                                            ? 'border-blue-500 bg-blue-50'
                                            : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="facebook_page"
                                        value={page.id}
                                        checked={selectedPageId === page.id}
                                        onChange={() => setSelectedPageId(page.id)}
                                        className="h-4 w-4 text-blue-600 border-gray-300 focus:ring-blue-500"
                                    />
                                    <div className="ml-3 flex-1">
                                        <div className="flex items-center">
                                            {page.picture?.data?.url && (
                                                <img
                                                    src={page.picture.data.url}
                                                    alt={page.name}
                                                    className="w-8 h-8 rounded-full mr-3"
                                                />
                                            )}
                                            <div>
                                                <p className="text-sm font-medium text-gray-900">
                                                    {page.name}
                                                </p>
                                                <p className="text-xs text-gray-500">
                                                    {page.category || 'Page'} 
                                                    {page.fan_count && ` • ${page.fan_count.toLocaleString()} followers`}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </label>
                            ))}
                        </div>
                    )}

                    <div className="mt-6 flex justify-end space-x-3">
                        <button
                            type="button"
                            onClick={onClose}
                            className="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900"
                        >
                            Cancel
                        </button>
                        <PrimaryButton
                            onClick={handleSelect}
                            disabled={!selectedPageId || submitting}
                        >
                            {submitting ? 'Selecting...' : 'Select Page'}
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>
    );
}
