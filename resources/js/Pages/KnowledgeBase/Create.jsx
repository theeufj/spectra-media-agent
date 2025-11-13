import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import FindSitemapInstructions from '@/Components/FindSitemapInstructions';

export default function Create({ auth }) {
    const [uploadMode, setUploadMode] = useState('sitemap'); // 'sitemap' or 'file'
    const { data, setData, post, processing, errors } = useForm({
        sitemap_url: '',
        document: null,
    });

    const handleSitemapSubmit = (e) => {
        e.preventDefault();
        post(route('knowledge-base.store'));
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

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={<h2 className="font-semibold text-xl text-jet leading-tight">Create Knowledge Base</h2>}
        >
            <Head title="Create Knowledge Base" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <FindSitemapInstructions />
                    
                    {/* Mode Selector */}
                    <div className="mb-8 bg-mint-cream overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6">
                            <h3 className="text-lg font-semibold text-delft-blue mb-4">Choose how to add content:</h3>
                            <div className="flex gap-4">
                                <button
                                    onClick={() => setUploadMode('sitemap')}
                                    className={`flex-1 p-4 rounded-lg text-center font-semibold transition ${
                                        uploadMode === 'sitemap'
                                            ? 'bg-delft-blue text-white'
                                            : 'bg-white border-2 border-delft-blue text-delft-blue hover:bg-gray-50'
                                    }`}
                                >
                                    📍 From Sitemap
                                </button>
                                <button
                                    onClick={() => setUploadMode('file')}
                                    className={`flex-1 p-4 rounded-lg text-center font-semibold transition ${
                                        uploadMode === 'file'
                                            ? 'bg-delft-blue text-white'
                                            : 'bg-white border-2 border-delft-blue text-delft-blue hover:bg-gray-50'
                                    }`}
                                >
                                    📄 Upload Document
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Sitemap Mode */}
                    {uploadMode === 'sitemap' && (
                        <div className="bg-mint-cream overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6">
                                <p className="text-jet mb-4">
                                    Enter the URL to your website's sitemap.xml file. We'll crawl the links to build a knowledge base about your brand and products.
                                </p>
                                <form onSubmit={handleSitemapSubmit}>
                                    <div>
                                        <InputLabel htmlFor="sitemap_url" value="Sitemap URL" className="text-delft-blue" />
                                        <TextInput
                                            id="sitemap_url"
                                            name="sitemap_url"
                                            value={data.sitemap_url}
                                            className="mt-1 block w-full"
                                            autoComplete="off"
                                            isFocused={true}
                                            onChange={(e) => setData('sitemap_url', e.target.value)}
                                            placeholder="https://example.com/sitemap.xml"
                                            required
                                        />
                                        <InputError message={errors.sitemap_url} className="mt-2" />
                                    </div>

                                    <div className="flex items-center justify-end mt-4">
                                        <PrimaryButton className="bg-delft-blue hover:bg-air-superiority-blue" disabled={processing}>
                                            Start Crawling
                                        </PrimaryButton>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}

                    {/* File Upload Mode */}
                    {uploadMode === 'file' && (
                        <div className="bg-mint-cream overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6">
                                <p className="text-jet mb-4">
                                    Upload a PDF or text document to add to your knowledge base. We'll extract the content and use it to inform your marketing strategies.
                                </p>
                                <form onSubmit={handleFileSubmit}>
                                    <div>
                                        <InputLabel htmlFor="document" value="Upload Document (PDF or TXT)" className="text-delft-blue" />
                                        <div className="mt-2 flex items-center justify-center px-6 pt-5 pb-6 border-2 border-dashed border-delft-blue rounded-lg hover:border-air-superiority-blue transition cursor-pointer bg-white">
                                            <div className="space-y-1 text-center">
                                                <svg className="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                                    <path d="M28 8H12a4 4 0 00-4 4v20a4 4 0 004 4h24a4 4 0 004-4V16a4 4 0 00-4-4h-8m-8-4v8m0 0l3-3m-3 3l-3-3" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                                                </svg>
                                                <div className="flex text-sm text-gray-600">
                                                    <label htmlFor="document" className="relative cursor-pointer rounded-md font-medium text-delft-blue hover:text-air-superiority-blue">
                                                        <span>Click to upload</span>
                                                        <input
                                                            id="document"
                                                            name="document"
                                                            type="file"
                                                            className="sr-only"
                                                            onChange={handleFileChange}
                                                            accept=".pdf,.txt"
                                                            required
                                                        />
                                                    </label>
                                                    <p className="pl-1">or drag and drop</p>
                                                </div>
                                                <p className="text-xs text-gray-500">PDF or TXT up to 10MB</p>
                                                {data.document && (
                                                    <p className="text-sm text-green-600 mt-2">✓ {data.document.name} selected</p>
                                                )}
                                            </div>
                                        </div>
                                        <InputError message={errors.document} className="mt-2" />
                                    </div>

                                    <div className="flex items-center justify-end mt-4">
                                        <PrimaryButton className="bg-delft-blue hover:bg-air-superiority-blue" disabled={processing || !data.document}>
                                            Upload Document
                                        </PrimaryButton>
                                    </div>
                                </form>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

