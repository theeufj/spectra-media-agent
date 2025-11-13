import React, { useState, useEffect } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function KnowledgeBaseIndex({ knowledgeBases: paginatedData }) {
    const { props } = usePage();
    const [deleting, setDeleting] = useState(null);
    const [loading, setLoading] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState(null);
    const [searching, setSearching] = useState(false);
    const [selectedResult, setSelectedResult] = useState(null);

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
        if (window.confirm('Are you sure you want to delete this knowledge base entry? This action cannot be undone.')) {
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
                    alert('Failed to delete knowledge base entry');
                    setDeleting(null);
                    setLoading(false);
                },
            });
        }
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
                alert('Failed to search knowledge base. Please try again.');
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
            'url': '🌐 Website',
            'pdf': '📄 PDF',
            'text': '📝 Text',
        };
        return labels[sourceType] || sourceType;
    };

    const getSourceTypeColor = (sourceType) => {
        const colors = {
            'url': 'bg-blue-100 text-blue-800',
            'pdf': 'bg-red-100 text-red-800',
            'text': 'bg-green-100 text-green-800',
        };
        return colors[sourceType] || 'bg-gray-100 text-gray-800';
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
                        className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                    >
                        + Add Source
                    </Link>
                </div>
            }
        >
            <Head title="Knowledge Base" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {/* Search Section */}
                    <div className="mb-8">
                        <form onSubmit={handleSearch} className="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                            <div className="mb-4">
                                <label htmlFor="search" className="block text-sm font-medium text-gray-700 mb-2">
                                    Search Your Knowledge Base
                                </label>
                                <div className="flex gap-2">
                                    <div className="flex-1">
                                        <input
                                            id="search"
                                            type="text"
                                            value={searchQuery}
                                            onChange={(e) => setSearchQuery(e.target.value)}
                                            placeholder="Search for topics, keywords, or specific information..."
                                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500"
                                        />
                                    </div>
                                    <button
                                        type="submit"
                                        disabled={searching || !searchQuery.trim()}
                                        className="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                                    >
                                        {searching ? (
                                            <span className="flex items-center gap-2">
                                                <svg className="w-4 h-4 animate-spin" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fillRule="evenodd" d="M4.293 5.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
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
                                            className="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition"
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
                                                    className="bg-white border-l-4 border-blue-500 p-4 rounded-lg shadow-sm hover:shadow-md cursor-pointer transition"
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
                                    className="bg-white overflow-hidden shadow-sm sm:rounded-lg hover:shadow-lg transition"
                                >
                                    <div className="p-6">
                                        {/* Source Type Badge */}
                                        <div className="flex items-start justify-between mb-3">
                                            <span className={`inline-block px-3 py-1 rounded-full text-sm font-medium ${getSourceTypeColor(kb.source_type)}`}>
                                                {getSourceTypeLabel(kb.source_type)}
                                            </span>
                                            <button
                                                onClick={() => handleDelete(kb.id)}
                                                disabled={deleting === kb.id || loading}
                                                className="text-red-600 hover:text-red-900 disabled:opacity-50 disabled:cursor-not-allowed transition"
                                                title="Delete"
                                            >
                                                {deleting === kb.id ? (
                                                    <svg className="w-5 h-5 animate-spin" fill="currentColor" viewBox="0 0 20 20">
                                                        <path fillRule="evenodd" d="M4.293 5.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
                                                    </svg>
                                                ) : (
                                                    <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                )}
                                            </button>
                                        </div>

                                        {/* Title/Filename */}
                                        <h3 className="text-lg font-semibold text-gray-900 mb-2 line-clamp-2">
                                            {kb.original_filename || kb.url}
                                        </h3>

                                        {/* URL Display */}
                                        <p className="text-sm text-gray-600 mb-3 truncate" title={kb.url}>
                                            {truncateUrl(kb.url)}
                                        </p>

                                        {/* Content Preview */}
                                        {kb.content ? (
                                            <div className="mb-3 p-2 bg-gray-50 rounded border border-gray-200">
                                                <p className="text-xs font-medium text-gray-500 mb-1">Content Preview:</p>
                                                <p className="text-sm text-gray-700 line-clamp-3">
                                                    {kb.content.substring(0, 150)}...
                                                </p>
                                            </div>
                                        ) : (
                                            <div className="mb-3 p-2 bg-yellow-50 rounded border border-yellow-200">
                                                <p className="text-xs font-medium text-yellow-700">
                                                    ⏳ Processing... Content extraction in progress
                                                </p>
                                            </div>
                                        )}

                                        {/* Metadata */}
                                        <div className="text-xs text-gray-500 space-y-1">
                                            <p>Added: {formatDate(kb.created_at)}</p>
                                            {kb.updated_at && kb.updated_at !== kb.created_at && (
                                                <p>Updated: {formatDate(kb.updated_at)}</p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                            </div>

                            {/* Pagination */}
                            {pagination.last_page > 1 && (
                                <div className="mt-6 flex items-center justify-between">
                                    <div className="text-sm text-gray-600">
                                        Showing <span className="font-semibold">{pagination.from}</span> to <span className="font-semibold">{pagination.to}</span> of <span className="font-semibold">{pagination.total}</span> results
                                    </div>
                                    <div className="flex gap-2">
                                        {/* Previous Button */}
                                        {pagination.current_page > 1 && (
                                            <Link
                                                href={`/knowledge-base?page=${pagination.current_page - 1}`}
                                                className="px-3 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition"
                                            >
                                                ← Previous
                                            </Link>
                                        )}

                                        {/* Page Numbers */}
                                        <div className="flex gap-1">
                                            {Array.from({ length: pagination.last_page }, (_, i) => i + 1).map((page) => (
                                                <Link
                                                    key={page}
                                                    href={`/knowledge-base?page=${page}`}
                                                    className={`px-3 py-2 rounded-lg transition ${
                                                        pagination.current_page === page
                                                            ? 'bg-blue-600 text-white'
                                                            : 'bg-gray-200 text-gray-700 hover:bg-gray-300'
                                                    }`}
                                                >
                                                    {page}
                                                </Link>
                                            ))}
                                        </div>

                                        {/* Next Button */}
                                        {pagination.current_page < pagination.last_page && (
                                            <Link
                                                href={`/knowledge-base?page=${pagination.current_page + 1}`}
                                                className="px-3 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition"
                                            >
                                                Next →
                                            </Link>
                                        )}
                                    </div>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6 text-center">
                                <div className="mb-4">
                                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                                <h3 className="text-lg font-medium text-gray-900 mb-2">No Knowledge Base Sources</h3>
                                <p className="text-gray-600 mb-6">
                                    Get started by adding a website, PDF, or text document to your knowledge base.
                                </p>
                                <Link
                                    href={route('knowledge-base.create')}
                                    className="inline-block px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                                >
                                    + Add Your First Source
                                </Link>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
