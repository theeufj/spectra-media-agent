import React, { useState, useEffect } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import ConfirmationModal from '@/Components/ConfirmationModal';
import { useToast } from '@/Components/Toast';
import { MagnifyingGlassIcon, TrashIcon, PlusIcon, GlobeAltIcon, DocumentTextIcon, DocumentIcon } from '@heroicons/react/24/outline';

export default function KnowledgeBaseIndex({ knowledgeBases: paginatedData }) {
    const { props } = usePage();
    const [deleting, setDeleting] = useState(null);
    const [loading, setLoading] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState(null);
    const [searching, setSearching] = useState(false);
    const [selectedResult, setSelectedResult] = useState(null);
    const [confirmModal, setConfirmModal] = useState({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
    const toast = useToast();

    // Extract data from paginated response
    const knowledgeBases = paginatedData?.data || [];
    const pagination = {
        current_page: paginatedData?.current_page || 1,
        last_page: paginatedData?.last_page || 1,
        per_page: paginatedData?.per_page || 10,
        total: paginatedData?.total || 0,
        from: paginatedData?.from || 1,
        to: paginatedData?.to || 0,
        links: paginatedData?.links || [],
    };

    const handleDelete = (id) => {
        setConfirmModal({
            show: true,
            title: 'Delete Knowledge Base Entry',
            message: 'Are you sure you want to delete this knowledge base entry? This action cannot be undone.',
            onConfirm: () => {
                setConfirmModal({ show: false, title: '', message: '', onConfirm: null, isDestructive: false });
                setDeleting(id);
                setLoading(true);
                
                router.delete(route('knowledge-base.destroy', id), {
                    onSuccess: () => {
                        setDeleting(null);
                        setLoading(false);
                        // Redirect to the current page after deletion
                        router.visit(route('knowledge-base.index', { page: pagination.current_page }), { preserveScroll: true });
                    },
                    onError: () => {
                        toast.error('Failed to delete knowledge base entry');
                        setDeleting(null);
                        setLoading(false);
                    },
                });
            },
            isDestructive: true,
            confirmText: 'Delete',
            confirmButtonClass: 'bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800'
        });
    };

    const handleSearch = (e) => {
        e.preventDefault();
        
        if (!searchQuery.trim()) {
            setSearchResults(null);
            return;
        }

        setSearching(true);
        setSelectedResult(null);

        // Use axios which automatically handles CSRF tokens
        axios.post(route('knowledge-base.search'), {
            query: searchQuery,
        })
            .then((response) => {
                setSearchResults(response.data.results || []);
            })
            .catch((error) => {
                console.error('Search error:', error);
                toast.error('Failed to search knowledge base. Please try again.');
            })
            .finally(() => {
                setSearching(false);
            });
    };

    const clearSearch = () => {
        setSearchQuery('');
        setSearchResults(null);
        setSelectedResult(null);
    };

    const getSourceTypeLabel = (sourceType) => {
        const labels = {
            'url': 'Website',
            'pdf': 'PDF',
            'text': 'Text',
        };
        return labels[sourceType] || sourceType;
    };

    const getSourceTypeIcon = (sourceType) => {
        const icons = {
            'url': <GlobeAltIcon className="h-4 w-4" />,
            'pdf': <DocumentTextIcon className="h-4 w-4" />,
            'text': <DocumentIcon className="h-4 w-4" />,
        };
        return icons[sourceType] || null;
    };

    const getSourceTypeColor = (sourceType) => {
        const colors = {
            'url': 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-600/20',
            'pdf': 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20',
            'text': 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-600/20',
        };
        return colors[sourceType] || 'bg-gray-50 text-gray-700 ring-1 ring-inset ring-gray-600/20';
    };

    const getSourceBorderColor = (sourceType) => {
        const colors = {
            'url': 'border-l-blue-500',
            'pdf': 'border-l-red-500',
            'text': 'border-l-green-500',
        };
        return colors[sourceType] || 'border-l-gray-400';
    };

    const truncateUrl = (url, maxLength = 50) => {
        if (!url) return 'N/A';
        if (url.length > maxLength) {
            return url.substring(0, maxLength) + '...';
        }
        return url;
    };

    const formatDate = (dateString) => {
        const options = { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
        return new Date(dateString).toLocaleDateString('en-US', options);
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex justify-between items-center">
                    <h2 className="text-xl font-semibold text-gray-800">Knowledge Base</h2>
                    <Link
                        href={route('knowledge-base.create')}
                        className="inline-flex items-center gap-1.5 px-4 py-2 bg-gradient-to-r from-delft-blue to-air-superiority-blue text-white text-sm font-medium rounded-lg hover:from-delft-blue/90 hover:to-air-superiority-blue/90 shadow-sm transition"
                    >
                        <PlusIcon className="h-4 w-4" />
                        Add Source
                    </Link>
                </div>
            }
        >
            <Head title="Knowledge Base" />

            <ConfirmationModal
                show={confirmModal.show}
                onClose={() => setConfirmModal({ show: false, title: '', message: '', onConfirm: null, isDestructive: false })}
                onConfirm={confirmModal.onConfirm}
                title={confirmModal.title}
                message={confirmModal.message}
                confirmText={confirmModal.confirmText}
                isDestructive={confirmModal.isDestructive}
                confirmButtonClass={confirmModal.confirmButtonClass}
            />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {/* Search Section */}
                    <div className="mb-8">
                        <form onSubmit={handleSearch} className="bg-white overflow-hidden shadow-sm rounded-xl p-6 border border-gray-100">
                            <div>
                                <label htmlFor="search" className="block text-sm font-medium text-gray-700 mb-2">
                                    Search Your Knowledge Base
                                </label>
                                <div className="flex gap-2">
                                    <div className="flex-1 relative">
                                        <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5">
                                            <MagnifyingGlassIcon className="h-5 w-5 text-gray-400" />
                                        </div>
                                        <input
                                            id="search"
                                            type="text"
                                            value={searchQuery}
                                            onChange={(e) => setSearchQuery(e.target.value)}
                                            placeholder="Search for topics, keywords, or specific information..."
                                            className="w-full pl-10 pr-4 py-2.5 border border-gray-300 rounded-lg focus:ring-flame-orange-500 focus:border-flame-orange-500 text-sm"
                                        />
                                    </div>
                                    <button
                                        type="submit"
                                        disabled={searching || !searchQuery.trim()}
                                        className="px-5 py-2.5 bg-gradient-to-r from-delft-blue to-air-superiority-blue text-white text-sm font-medium rounded-lg hover:from-delft-blue/90 hover:to-air-superiority-blue/90 disabled:opacity-50 disabled:cursor-not-allowed shadow-sm transition"
                                    >
                                        {searching ? (
                                            <span className="flex items-center gap-2">
                                                <svg className="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                </svg>
                                                Searching...
                                            </span>
                                        ) : (
                                            'Search'
                                        )}
                                    </button>
                                    {searchResults !== null && (
                                        <button
                                            type="button"
                                            onClick={clearSearch}
                                            className="px-4 py-2.5 bg-gray-100 text-gray-600 text-sm rounded-lg hover:bg-gray-200 transition"
                                        >
                                            Clear
                                        </button>
                                    )}
                                </div>
                            </div>
                        </form>

                        {/* Search Results */}
                        {searchResults !== null && (
                            <div className="mt-6">
                                {searchResults.length > 0 ? (
                                    <div>
                                        <h3 className="text-lg font-semibold text-gray-900 mb-4">
                                            Found {searchResults.length} relevant {searchResults.length === 1 ? 'result' : 'results'}
                                        </h3>
                                        <div className="space-y-3">
                                            {searchResults.map((result, idx) => (
                                                <div
                                                    key={idx}
                                                    onClick={() => setSelectedResult(selectedResult === idx ? null : idx)}
                                                    className="bg-white border-l-4 border-delft-blue p-4 rounded-xl shadow-sm hover:shadow-md cursor-pointer transition-all duration-200"
                                                >
                                                    <div className="flex items-start justify-between mb-2">
                                                        <div>
                                                            <p className="text-sm text-gray-600 font-medium">
                                                                From: {result.source_name}
                                                            </p>
                                                            <p className="text-sm text-gray-500">
                                                                Match Score: {(result.similarity * 100).toFixed(1)}%
                                                            </p>
                                                        </div>
                                                        <svg 
                                                            className={`w-5 h-5 text-gray-400 transition-transform ${selectedResult === idx ? 'rotate-180' : ''}`}
                                                            fill="currentColor" 
                                                            viewBox="0 0 20 20"
                                                        >
                                                            <path fillRule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clipRule="evenodd" />
                                                        </svg>
                                                    </div>

                                                    {selectedResult === idx && (
                                                        <div className="mt-3 pt-3 border-t border-gray-200">
                                                            <p className="text-gray-700 whitespace-pre-wrap text-sm">
                                                                {result.chunk}
                                                            </p>
                                                        </div>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                ) : (
                                    <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
                                        <svg className="mx-auto h-8 w-8 text-yellow-400 mb-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                                        </svg>
                                        <p className="text-gray-700">No results found for "{searchQuery}". Try different keywords.</p>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                    {knowledgeBases && knowledgeBases.length > 0 ? (
                        <div>
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">
                                {searchResults !== null ? 'Your Knowledge Base' : 'Your Sources'}
                            </h3>
                            <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            {knowledgeBases.map((kb) => (
                                <div
                                    key={kb.id}
                                    className={`group bg-white overflow-hidden rounded-xl border border-gray-100 border-l-4 ${getSourceBorderColor(kb.source_type)} shadow-sm hover:shadow-lg hover:scale-[1.02] transition-all duration-200`}
                                >
                                    <div className="p-5">
                                        {/* Source Type Badge */}
                                        <div className="flex items-start justify-between mb-3">
                                            <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium ${getSourceTypeColor(kb.source_type)}`}>
                                                {getSourceTypeIcon(kb.source_type)}
                                                {getSourceTypeLabel(kb.source_type)}
                                            </span>
                                            <button
                                                onClick={() => handleDelete(kb.id)}
                                                disabled={deleting === kb.id || loading}
                                                className="opacity-0 group-hover:opacity-100 p-1.5 rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-200"
                                                title="Delete"
                                            >
                                                {deleting === kb.id ? (
                                                    <svg className="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                    </svg>
                                                ) : (
                                                    <TrashIcon className="w-4 h-4" />
                                                )}
                                            </button>
                                        </div>

                                        {/* Title/Filename */}
                                        <h3 className="text-sm font-semibold text-gray-900 mb-1.5 line-clamp-2">
                                            {kb.original_filename || kb.url}
                                        </h3>

                                        {/* URL Display */}
                                        {kb.url && (
                                            <p className="text-xs text-gray-400 mb-3 truncate font-mono" title={kb.url}>
                                                {truncateUrl(kb.url)}
                                            </p>
                                        )}

                                        {/* Content Preview */}
                                        {kb.content ? (
                                            <div className="mb-3 p-3 bg-gray-50/80 rounded-lg border border-gray-100">
                                                <p className="text-xs text-gray-500 font-medium mb-1">Preview</p>
                                                <p className="text-xs text-gray-600 line-clamp-3 leading-relaxed">
                                                    {kb.content.substring(0, 150)}...
                                                </p>
                                            </div>
                                        ) : (
                                            <div className="mb-3 p-3 bg-amber-50/80 rounded-lg border border-amber-100">
                                                <div className="flex items-center gap-2">
                                                    <div className="flex gap-1">
                                                        <span className="w-1.5 h-1.5 rounded-full bg-amber-400 animate-bounce [animation-delay:0ms]" />
                                                        <span className="w-1.5 h-1.5 rounded-full bg-amber-400 animate-bounce [animation-delay:150ms]" />
                                                        <span className="w-1.5 h-1.5 rounded-full bg-amber-400 animate-bounce [animation-delay:300ms]" />
                                                    </div>
                                                    <p className="text-xs font-medium text-amber-700">Processing content...</p>
                                                </div>
                                            </div>
                                        )}

                                        {/* Metadata */}
                                        <div className="text-xs text-gray-400">
                                            <p>Added {formatDate(kb.created_at)}</p>
                                        </div>
                                    </div>
                                </div>
                            ))}
                            </div>

                            {/* Pagination */}
                            {pagination.last_page > 1 && (
                                <div className="mt-8 flex flex-col sm:flex-row items-center justify-between gap-4">
                                    <div className="text-sm text-gray-500">
                                        Showing <span className="font-medium text-gray-700">{pagination.from}</span> to <span className="font-medium text-gray-700">{pagination.to}</span> of <span className="font-medium text-gray-700">{pagination.total}</span> sources
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        {/* Previous Button */}
                                        {pagination.current_page > 1 && (
                                            <Link
                                                href={`/knowledge-base?page=${pagination.current_page - 1}`}
                                                className="px-3 py-1.5 text-sm text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition"
                                            >
                                                Previous
                                            </Link>
                                        )}

                                        {/* Page Numbers */}
                                        {Array.from({ length: pagination.last_page }, (_, i) => i + 1).map((page) => (
                                            <Link
                                                key={page}
                                                href={`/knowledge-base?page=${page}`}
                                                className={`w-9 h-9 flex items-center justify-center text-sm rounded-lg transition ${
                                                    pagination.current_page === page
                                                        ? 'bg-gradient-to-r from-delft-blue to-air-superiority-blue text-white shadow-sm font-medium'
                                                        : 'text-gray-600 bg-white border border-gray-200 hover:bg-gray-50'
                                                }`}
                                            >
                                                {page}
                                            </Link>
                                        ))}

                                        {/* Next Button */}
                                        {pagination.current_page < pagination.last_page && (
                                            <Link
                                                href={`/knowledge-base?page=${pagination.current_page + 1}`}
                                                className="px-3 py-1.5 text-sm text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition"
                                            >
                                                Next
                                            </Link>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="bg-white overflow-hidden rounded-xl border border-gray-100 shadow-sm">
                            <div className="p-10 text-center">
                                <div className="mx-auto w-16 h-16 flex items-center justify-center rounded-2xl bg-gradient-to-br from-delft-blue/10 to-air-superiority-blue/10 mb-5">
                                    <DocumentTextIcon className="h-8 w-8 text-delft-blue" />
                                </div>
                                <h3 className="text-lg font-semibold text-gray-900 mb-2">No sources yet</h3>
                                <p className="text-sm text-gray-500 mb-8 max-w-sm mx-auto">
                                    Add a website, PDF, or text document to start building your knowledge base.
                                </p>
                                <Link
                                    href={route('knowledge-base.create')}
                                    className="inline-flex items-center gap-1.5 px-5 py-2.5 bg-gradient-to-r from-delft-blue to-air-superiority-blue text-white text-sm font-medium rounded-lg hover:from-delft-blue/90 hover:to-air-superiority-blue/90 shadow-sm transition"
                                >
                                    <PlusIcon className="h-4 w-4" />
                                    Add Your First Source
                                </Link>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
