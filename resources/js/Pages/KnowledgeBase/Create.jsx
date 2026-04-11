import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState, useCallback } from 'react';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import FindSitemapInstructions from '@/Components/FindSitemapInstructions';
import SitemapProcessingModal from '@/Components/SitemapProcessingModal';
import { GlobeAltIcon, DocumentArrowUpIcon, ArrowLeftIcon, XMarkIcon } from '@heroicons/react/24/outline';

function formatFileSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

export default function Create({ auth }) {
    const [uploadMode, setUploadMode] = useState('sitemap');
    const [showModal, setShowModal] = useState(false);
    const [showInstructions, setShowInstructions] = useState(false);
    const [dragOver, setDragOver] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        sitemap_url: '',
        document: null,
    });

    const handleSitemapSubmit = (e) => {
        e.preventDefault();
        post(route('knowledge-base.store'), {
            onSuccess: () => {
                setShowModal(true);
            }
        });
    };

    const handleFileSubmit = (e) => {
        e.preventDefault();
        
        const formData = new FormData();
        if (data.document) {
            formData.append('document', data.document);
            formData.append('_token', document.querySelector('[name="_token"]')?.value);
        }

        post(route('knowledge-base.store'), {
            data: formData,
            headers: {
                'Content-Type': 'multipart/form-data',
            },
        });
    };

    const handleFileChange = (e) => {
        setData('document', e.target.files[0]);
    };

    const handleDrop = useCallback((e) => {
        e.preventDefault();
        setDragOver(false);
        const file = e.dataTransfer.files[0];
        if (file && (file.type === 'application/pdf' || file.type === 'text/plain' || file.name.endsWith('.txt'))) {
            setData('document', file);
        }
    }, [setData]);

    const handleDragOver = useCallback((e) => {
        e.preventDefault();
        setDragOver(true);
    }, []);

    const handleDragLeave = useCallback((e) => {
        e.preventDefault();
        setDragOver(false);
    }, []);

    const clearFile = () => {
        setData('document', null);
    };

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-jet leading-tight">Create Knowledge Base</h2>}
        >
            <Head title="Create Knowledge Base" />

            <div className="py-8 sm:py-12">
                <div className="max-w-3xl mx-auto sm:px-6 lg:px-8">
                    {/* Back Navigation */}
                    <Link
                        href={route('knowledge-base.index')}
                        className="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-delft-blue transition-colors mb-6"
                    >
                        <ArrowLeftIcon className="h-4 w-4" />
                        Back to Knowledge Base
                    </Link>

                    {/* Page Header */}
                    <div className="mb-8">
                        <h1 className="text-2xl font-bold text-gray-900">Add a new source</h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Import content from your website or upload documents to build your knowledge base.
                        </p>
                    </div>

                    {/* Mode Selector Cards */}
                    <div className="grid grid-cols-2 gap-4 mb-8">
                        <button
                            onClick={() => setUploadMode('sitemap')}
                            className={`relative group flex flex-col items-center gap-3 p-6 rounded-xl border-2 transition-all duration-200 ${
                                uploadMode === 'sitemap'
                                    ? 'border-delft-blue bg-gradient-to-br from-delft-blue/5 to-air-superiority-blue/5 shadow-md ring-1 ring-delft-blue/20'
                                    : 'border-gray-200 bg-white hover:-translate-y-0.5 hover:shadow-md hover:border-gray-300'
                            }`}
                        >
                            <div className={`flex items-center justify-center w-12 h-12 rounded-xl transition-colors ${
                                uploadMode === 'sitemap'
                                    ? 'bg-gradient-to-br from-delft-blue to-air-superiority-blue text-white'
                                    : 'bg-gray-100 text-gray-500 group-hover:bg-gray-200'
                            }`}>
                                <GlobeAltIcon className="h-6 w-6" />
                            </div>
                            <div className="text-center">
                                <p className={`font-semibold transition-colors ${
                                    uploadMode === 'sitemap' ? 'text-delft-blue' : 'text-gray-700'
                                }`}>
                                    From Sitemap
                                </p>
                                <p className="text-xs text-gray-500 mt-0.5">Crawl your website pages</p>
                            </div>
                            {uploadMode === 'sitemap' && (
                                <div className="absolute top-3 right-3 w-2.5 h-2.5 rounded-full bg-delft-blue" />
                            )}
                        </button>

                        <button
                            onClick={() => setUploadMode('file')}
                            className={`relative group flex flex-col items-center gap-3 p-6 rounded-xl border-2 transition-all duration-200 ${
                                uploadMode === 'file'
                                    ? 'border-delft-blue bg-gradient-to-br from-delft-blue/5 to-air-superiority-blue/5 shadow-md ring-1 ring-delft-blue/20'
                                    : 'border-gray-200 bg-white hover:-translate-y-0.5 hover:shadow-md hover:border-gray-300'
                            }`}
                        >
                            <div className={`flex items-center justify-center w-12 h-12 rounded-xl transition-colors ${
                                uploadMode === 'file'
                                    ? 'bg-gradient-to-br from-delft-blue to-air-superiority-blue text-white'
                                    : 'bg-gray-100 text-gray-500 group-hover:bg-gray-200'
                            }`}>
                                <DocumentArrowUpIcon className="h-6 w-6" />
                            </div>
                            <div className="text-center">
                                <p className={`font-semibold transition-colors ${
                                    uploadMode === 'file' ? 'text-delft-blue' : 'text-gray-700'
                                }`}>
                                    Upload Document
                                </p>
                                <p className="text-xs text-gray-500 mt-0.5">PDF or text files</p>
                            </div>
                            {uploadMode === 'file' && (
                                <div className="absolute top-3 right-3 w-2.5 h-2.5 rounded-full bg-delft-blue" />
                            )}
                        </button>
                    </div>

                    {/* Form Sections with transition */}
                    <div className="relative">
                        {/* Sitemap Mode */}
                        <div className={`transition-all duration-300 ${
                            uploadMode === 'sitemap' ? 'opacity-100 translate-y-0' : 'opacity-0 absolute inset-0 pointer-events-none -translate-y-2'
                        }`}>
                            <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                                <div className="p-6 sm:p-8">
                                    <p className="text-gray-600 text-sm mb-6">
                                        Enter the URL to your website's sitemap.xml file. We'll crawl the links to build a knowledge base about your brand and products.
                                    </p>
                                    <form onSubmit={handleSitemapSubmit}>
                                        <div>
                                            <InputLabel htmlFor="sitemap_url" value="Sitemap URL" className="text-gray-700 font-medium" />
                                            <div className="mt-1.5 relative">
                                                <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                    <GlobeAltIcon className="h-5 w-5 text-gray-400" />
                                                </div>
                                                <input
                                                    id="sitemap_url"
                                                    name="sitemap_url"
                                                    type="url"
                                                    value={data.sitemap_url}
                                                    className="block w-full rounded-lg border-gray-300 pl-10 shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500 text-sm"
                                                    autoComplete="off"
                                                    autoFocus
                                                    onChange={(e) => setData('sitemap_url', e.target.value)}
                                                    placeholder="https://example.com/sitemap.xml"
                                                    required
                                                />
                                            </div>
                                            <InputError message={errors.sitemap_url} className="mt-2" />
                                        </div>

                                        {/* Collapsible Instructions */}
                                        <div className="mt-4">
                                            <FindSitemapInstructions
                                                isOpen={showInstructions}
                                                onToggle={() => setShowInstructions(!showInstructions)}
                                            />
                                        </div>

                                        <div className="flex items-center justify-end mt-6">
                                            <button
                                                type="submit"
                                                disabled={processing}
                                                className="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-delft-blue to-air-superiority-blue px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:from-delft-blue/90 hover:to-air-superiority-blue/90 focus:outline-none focus:ring-2 focus:ring-delft-blue focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition"
                                            >
                                                {processing ? (
                                                    <>
                                                        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                        </svg>
                                                        Crawling...
                                                    </>
                                                ) : (
                                                    'Start Crawling'
                                                )}
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        {/* File Upload Mode */}
                        <div className={`transition-all duration-300 ${
                            uploadMode === 'file' ? 'opacity-100 translate-y-0' : 'opacity-0 absolute inset-0 pointer-events-none -translate-y-2'
                        }`}>
                            <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                                <div className="p-6 sm:p-8">
                                    <p className="text-gray-600 text-sm mb-6">
                                        Upload a PDF or text document. We'll extract the content and use it to inform your marketing strategies.
                                    </p>
                                    <form onSubmit={handleFileSubmit}>
                                        <div>
                                            <InputLabel htmlFor="document" value="Document" className="text-gray-700 font-medium" />
                                            <div
                                                onDrop={handleDrop}
                                                onDragOver={handleDragOver}
                                                onDragLeave={handleDragLeave}
                                                className={`mt-1.5 flex items-center justify-center px-6 py-8 border-2 border-dashed rounded-xl transition-all duration-200 ${
                                                    dragOver
                                                        ? 'border-delft-blue bg-delft-blue/5 scale-[1.01]'
                                                        : data.document
                                                            ? 'border-green-300 bg-green-50/50'
                                                            : 'border-gray-300 bg-gray-50/50 hover:border-gray-400 hover:bg-gray-50'
                                                }`}
                                            >
                                                <div className="text-center">
                                                    {data.document ? (
                                                        <div className="flex flex-col items-center gap-2">
                                                            <div className="flex items-center justify-center w-12 h-12 rounded-xl bg-green-100">
                                                                <DocumentArrowUpIcon className="h-6 w-6 text-green-600" />
                                                            </div>
                                                            <div>
                                                                <p className="text-sm font-medium text-gray-900">{data.document.name}</p>
                                                                <p className="text-xs text-gray-500 mt-0.5">
                                                                    {formatFileSize(data.document.size)} · {data.document.type === 'application/pdf' ? 'PDF' : 'Text'}
                                                                </p>
                                                            </div>
                                                            <button
                                                                type="button"
                                                                onClick={clearFile}
                                                                className="inline-flex items-center gap-1 text-xs text-red-500 hover:text-red-700 transition-colors mt-1"
                                                            >
                                                                <XMarkIcon className="h-3.5 w-3.5" />
                                                                Remove
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <>
                                                            <div className="flex items-center justify-center w-12 h-12 mx-auto rounded-xl bg-gray-100 mb-3">
                                                                <DocumentArrowUpIcon className="h-6 w-6 text-gray-400" />
                                                            </div>
                                                            <div className="flex text-sm text-gray-600">
                                                                <label htmlFor="document" className="relative cursor-pointer font-semibold text-delft-blue hover:text-air-superiority-blue transition-colors">
                                                                    <span>Click to upload</span>
                                                                    <input
                                                                        id="document"
                                                                        name="document"
                                                                        type="file"
                                                                        className="sr-only"
                                                                        onChange={handleFileChange}
                                                                        accept=".pdf,.txt"
                                                                        required={!data.document}
                                                                    />
                                                                </label>
                                                                <p className="pl-1">or drag and drop</p>
                                                            </div>
                                                            <p className="text-xs text-gray-400 mt-1.5">PDF or TXT up to 10MB</p>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                            <InputError message={errors.document} className="mt-2" />
                                        </div>

                                        <div className="flex items-center justify-end mt-6">
                                            <button
                                                type="submit"
                                                disabled={processing || !data.document}
                                                className="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-delft-blue to-air-superiority-blue px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:from-delft-blue/90 hover:to-air-superiority-blue/90 focus:outline-none focus:ring-2 focus:ring-delft-blue focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed transition"
                                            >
                                                {processing ? (
                                                    <>
                                                        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                        </svg>
                                                        Uploading...
                                                    </>
                                                ) : (
                                                    'Upload Document'
                                                )}
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <SitemapProcessingModal 
                show={showModal} 
                onClose={() => setShowModal(false)} 
            />
        </AuthenticatedLayout>
    );
}

