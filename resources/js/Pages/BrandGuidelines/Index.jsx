import React, { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function BrandGuidelinesIndex({ brandGuideline, customer, canEdit }) {
    const [isEditing, setIsEditing] = useState(false);
    const [activeSection, setActiveSection] = useState('overview');
    
    const { data, setData, put, processing, errors } = useForm({
        brand_voice: brandGuideline?.brand_voice || { primary_tone: '', description: '', examples: [] },
        tone_attributes: brandGuideline?.tone_attributes || [],
        writing_patterns: brandGuideline?.writing_patterns || {},
        color_palette: brandGuideline?.color_palette || { primary_colors: [], secondary_colors: [], description: '', usage_notes: '' },
        typography: brandGuideline?.typography || { heading_style: '', body_style: '', fonts_detected: [], font_weights: '', letter_spacing: '' },
        visual_style: brandGuideline?.visual_style || { overall_aesthetic: '', imagery_style: '', description: '', color_treatment: '', layout_preference: '' },
        messaging_themes: brandGuideline?.messaging_themes || [],
        unique_selling_propositions: brandGuideline?.unique_selling_propositions || [],
        target_audience: brandGuideline?.target_audience || { primary: '', demographics: '', psychographics: '', pain_points: [], language_level: '', familiarity_assumption: '' },
        competitor_differentiation: brandGuideline?.competitor_differentiation || [],
        brand_personality: brandGuideline?.brand_personality || { archetype: '', characteristics: [], if_brand_were_person: '' },
        do_not_use: brandGuideline?.do_not_use || [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('brand-guidelines.update', brandGuideline.id), {
            preserveScroll: true,
            onSuccess: () => setIsEditing(false),
        });
    };

    const handleVerify = () => {
        router.post(route('brand-guidelines.verify', brandGuideline.id), {}, {
            preserveScroll: true,
        });
    };

    const handleReExtract = () => {
        if (confirm('This will re-analyze your website and update the brand guidelines. Your manual edits will be preserved. Continue?')) {
            router.post(route('brand-guidelines.re-extract'), {}, {
                preserveScroll: true,
            });
        }
    };

    const addArrayItem = (field, value = '') => {
        setData(field, [...(data[field] || []), value]);
    };

    const removeArrayItem = (field, index) => {
        setData(field, data[field].filter((_, i) => i !== index));
    };

    const updateArrayItem = (field, index, value) => {
        const newArray = [...data[field]];
        newArray[index] = value;
        setData(field, newArray);
    };

    if (!brandGuideline) {
        return (
            <AuthenticatedLayout>
                <Head title="Brand Guidelines" />
                <div className="py-12">
                    <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                            <div className="p-6 text-center">
                                <h2 className="text-2xl font-bold text-gray-900 mb-4">No Brand Guidelines Yet</h2>
                                <p className="text-gray-600 mb-6">
                                    We haven't extracted your brand guidelines yet. This usually happens automatically after crawling your website.
                                </p>
                                <button
                                    onClick={handleReExtract}
                                    className="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                >
                                    <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                    Extract Brand Guidelines
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </AuthenticatedLayout>
        );
    }

    const sections = [
        { id: 'overview', name: 'Overview', icon: '📊' },
        { id: 'voice', name: 'Brand Voice', icon: '🎤' },
        { id: 'visual', name: 'Visual Identity', icon: '🎨' },
        { id: 'messaging', name: 'Messaging', icon: '💬' },
        { id: 'audience', name: 'Target Audience', icon: '👥' },
        { id: 'differentiation', name: 'Differentiation', icon: '🎯' },
    ];

    return (
        <AuthenticatedLayout>
            <Head title="Brand Guidelines" />
            
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="mb-8">
                        <div className="flex justify-between items-start">
                            <div>
                                <h1 className="text-3xl font-bold text-gray-900">{customer.name} - Brand Guidelines</h1>
                                <p className="mt-2 text-sm text-gray-600">
                                    Last extracted: {new Date(brandGuideline.extracted_at).toLocaleDateString()} 
                                    {brandGuideline.user_verified && (
                                        <span className="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            ✓ Verified
                                        </span>
                                    )}
                                    <span className="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        Quality: {brandGuideline.quality_score}/100
                                    </span>
                                </p>
                            </div>
                            <div className="flex space-x-3">
                                {!isEditing && canEdit && (
                                    <>
                                        <button
                                            onClick={() => setIsEditing(true)}
                                            className="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                        >
                                            <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                            Edit Guidelines
                                        </button>
                                        {!brandGuideline.user_verified && (
                                            <button
                                                onClick={handleVerify}
                                                className="inline-flex items-center px-4 py-2 bg-green-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-green-700 focus:bg-green-700 active:bg-green-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                            >
                                                <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                </svg>
                                                Verify as Accurate
                                            </button>
                                        )}
                                        <button
                                            onClick={handleReExtract}
                                            className="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                        >
                                            <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                            </svg>
                                            Re-Extract
                                        </button>
                                    </>
                                )}
                                {isEditing && (
                                    <>
                                        <button
                                            onClick={() => setIsEditing(false)}
                                            className="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            onClick={handleSubmit}
                                            disabled={processing}
                                            className="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50"
                                        >
                                            {processing ? 'Saving...' : 'Save Changes'}
                                        </button>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex gap-6">
                        {/* Sidebar Navigation */}
                        <div className="w-64 flex-shrink-0">
                            <nav className="space-y-1 sticky top-6">
                                {sections.map((section) => (
                                    <button
                                        key={section.id}
                                        onClick={() => setActiveSection(section.id)}
                                        className={`w-full flex items-center px-4 py-2 text-sm font-medium rounded-md transition-colors ${
                                            activeSection === section.id
                                                ? 'bg-blue-100 text-blue-700'
                                                : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900'
                                        }`}
                                    >
                                        <span className="mr-3 text-lg">{section.icon}</span>
                                        {section.name}
                                    </button>
                                ))}
                            </nav>
                        </div>

                        {/* Main Content */}
                        <div className="flex-1">
                            <div className="bg-white shadow-sm rounded-lg">
                                <form onSubmit={handleSubmit} className="p-6 space-y-8">
                                    {/* Overview Section */}
                                    {activeSection === 'overview' && (
                                        <div className="space-y-6">
                                            <h2 className="text-2xl font-bold text-gray-900">Overview</h2>
                                            
                                            {/* Quality Score */}
                                            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                                <h3 className="text-sm font-semibold text-blue-900 mb-2">Quality Score</h3>
                                                <div className="flex items-center">
                                                    <div className="flex-1 bg-gray-200 rounded-full h-4 mr-4">
                                                        <div
                                                            className="bg-blue-600 h-4 rounded-full transition-all duration-300"
                                                            style={{ width: `${brandGuideline.quality_score}%` }}
                                                        ></div>
                                                    </div>
                                                    <span className="text-2xl font-bold text-blue-900">{brandGuideline.quality_score}</span>
                                                </div>
                                                <p className="text-xs text-blue-700 mt-2">
                                                    {brandGuideline.quality_score >= 80 && 'Excellent extraction quality!'}
                                                    {brandGuideline.quality_score >= 60 && brandGuideline.quality_score < 80 && 'Good extraction quality. Consider reviewing and refining.'}
                                                    {brandGuideline.quality_score < 60 && 'Low extraction quality. Manual review recommended.'}
                                                </p>
                                            </div>

                                            {/* Unique Selling Propositions */}
                                            <div>
                                                <h3 className="text-lg font-semibold text-gray-900 mb-3">Unique Selling Propositions</h3>
                                                {!isEditing ? (
                                                    <ul className="list-disc list-inside space-y-2">
                                                        {data.unique_selling_propositions.map((usp, index) => (
                                                            <li key={index} className="text-gray-700">{usp}</li>
                                                        ))}
                                                    </ul>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {data.unique_selling_propositions.map((usp, index) => (
                                                            <div key={index} className="flex gap-2">
                                                                <input
                                                                    type="text"
                                                                    value={usp}
                                                                    onChange={(e) => updateArrayItem('unique_selling_propositions', index, e.target.value)}
                                                                    className="flex-1 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => removeArrayItem('unique_selling_propositions', index)}
                                                                    className="px-3 py-2 text-red-600 hover:text-red-800"
                                                                >
                                                                    ✕
                                                                </button>
                                                            </div>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            onClick={() => addArrayItem('unique_selling_propositions', '')}
                                                            className="text-sm text-blue-600 hover:text-blue-800"
                                                        >
                                                            + Add USP
                                                        </button>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Do Not Use */}
                                            <div>
                                                <h3 className="text-lg font-semibold text-gray-900 mb-3">⛔ Do Not Use</h3>
                                                <p className="text-sm text-gray-600 mb-3">Words, phrases, or concepts to avoid in marketing materials.</p>
                                                {!isEditing ? (
                                                    <div className="flex flex-wrap gap-2">
                                                        {data.do_not_use.map((item, index) => (
                                                            <span key={index} className="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                                                                {item}
                                                            </span>
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {data.do_not_use.map((item, index) => (
                                                            <div key={index} className="flex gap-2">
                                                                <input
                                                                    type="text"
                                                                    value={item}
                                                                    onChange={(e) => updateArrayItem('do_not_use', index, e.target.value)}
                                                                    className="flex-1 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => removeArrayItem('do_not_use', index)}
                                                                    className="px-3 py-2 text-red-600 hover:text-red-800"
                                                                >
                                                                    ✕
                                                                </button>
                                                            </div>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            onClick={() => addArrayItem('do_not_use', '')}
                                                            className="text-sm text-blue-600 hover:text-blue-800"
                                                        >
                                                            + Add Restriction
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {/* Brand Voice Section */}
                                    {activeSection === 'voice' && (
                                        <div className="space-y-6">
                                            <h2 className="text-2xl font-bold text-gray-900">Brand Voice & Tone</h2>
                                            
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700 mb-2">Primary Tone</label>
                                                {!isEditing ? (
                                                    <p className="text-gray-900 text-lg">{data.brand_voice.primary_tone}</p>
                                                ) : (
                                                    <input
                                                        type="text"
                                                        value={data.brand_voice.primary_tone}
                                                        onChange={(e) => setData('brand_voice', { ...data.brand_voice, primary_tone: e.target.value })}
                                                        className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                        placeholder="e.g., Professional yet approachable"
                                                    />
                                                )}
                                            </div>

                                            <div>
                                                <label className="block text-sm font-medium text-gray-700 mb-2">Voice Description</label>
                                                {!isEditing ? (
                                                    <p className="text-gray-700">{data.brand_voice.description}</p>
                                                ) : (
                                                    <textarea
                                                        rows={4}
                                                        value={data.brand_voice.description}
                                                        onChange={(e) => setData('brand_voice', { ...data.brand_voice, description: e.target.value })}
                                                        className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                        placeholder="Detailed description of the brand voice..."
                                                    />
                                                )}
                                            </div>

                                            <div>
                                                <label className="block text-sm font-medium text-gray-700 mb-2">Voice Examples</label>
                                                {!isEditing ? (
                                                    <ul className="list-disc list-inside space-y-2">
                                                        {data.brand_voice.examples?.map((example, index) => (
                                                            <li key={index} className="text-gray-700 italic">"{example}"</li>
                                                        ))}
                                                    </ul>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {data.brand_voice.examples?.map((example, index) => (
                                                            <div key={index} className="flex gap-2">
                                                                <input
                                                                    type="text"
                                                                    value={example}
                                                                    onChange={(e) => {
                                                                        const newExamples = [...data.brand_voice.examples];
                                                                        newExamples[index] = e.target.value;
                                                                        setData('brand_voice', { ...data.brand_voice, examples: newExamples });
                                                                    }}
                                                                    className="flex-1 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        const newExamples = data.brand_voice.examples.filter((_, i) => i !== index);
                                                                        setData('brand_voice', { ...data.brand_voice, examples: newExamples });
                                                                    }}
                                                                    className="px-3 py-2 text-red-600 hover:text-red-800"
                                                                >
                                                                    ✕
                                                                </button>
                                                            </div>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setData('brand_voice', { 
                                                                    ...data.brand_voice, 
                                                                    examples: [...(data.brand_voice.examples || []), ''] 
                                                                });
                                                            }}
                                                            className="text-sm text-blue-600 hover:text-blue-800"
                                                        >
                                                            + Add Example
                                                        </button>
                                                    </div>
                                                )}
                                            </div>

                                            <div>
                                                <label className="block text-sm font-medium text-gray-700 mb-2">Tone Attributes</label>
                                                {!isEditing ? (
                                                    <div className="flex flex-wrap gap-2">
                                                        {data.tone_attributes?.map((tone, index) => (
                                                            <span key={index} className="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-purple-100 text-purple-800">
                                                                {tone}
                                                            </span>
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {data.tone_attributes?.map((tone, index) => (
                                                            <div key={index} className="flex gap-2">
                                                                <input
                                                                    type="text"
                                                                    value={tone}
                                                                    onChange={(e) => {
                                                                        const newTones = [...data.tone_attributes];
                                                                        newTones[index] = e.target.value;
                                                                        setData('tone_attributes', newTones);
                                                                    }}
                                                                    className="flex-1 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        const newTones = data.tone_attributes.filter((_, i) => i !== index);
                                                                        setData('tone_attributes', newTones);
                                                                    }}
                                                                    className="px-3 py-2 text-red-600 hover:text-red-800"
                                                                >
                                                                    ✕
                                                                </button>
                                                            </div>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setData('tone_attributes', [...(data.tone_attributes || []), '']);
                                                            }}
                                                            className="text-sm text-blue-600 hover:text-blue-800"
                                                        >
                                                            + Add Tone
                                                        </button>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Brand Personality */}
                                            <div>
                                                <h3 className="text-lg font-semibold text-gray-900 mb-3">Brand Personality</h3>
                                                <div className="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">Archetype</label>
                                                        {!isEditing ? (
                                                            <p className="text-gray-900">{data.brand_personality.archetype}</p>
                                                        ) : (
                                                            <input
                                                                type="text"
                                                                value={data.brand_personality.archetype}
                                                                onChange={(e) => setData('brand_personality', { ...data.brand_personality, archetype: e.target.value })}
                                                                className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                placeholder="e.g., The Hero, The Sage"
                                                            />
                                                        )}
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">Characteristics</label>
                                                        {!isEditing ? (
                                                            <div className="flex flex-wrap gap-2">
                                                                {data.brand_personality.characteristics?.map((char, index) => (
                                                                    <span key={index} className="inline-flex items-center px-2 py-1 rounded text-sm bg-gray-100 text-gray-800">
                                                                        {char}
                                                                    </span>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <div className="space-y-1">
                                                                {data.brand_personality.characteristics?.map((char, index) => (
                                                                    <div key={index} className="flex gap-2">
                                                                        <input
                                                                            type="text"
                                                                            value={char}
                                                                            onChange={(e) => {
                                                                                const newChars = [...data.brand_personality.characteristics];
                                                                                newChars[index] = e.target.value;
                                                                                setData('brand_personality', { ...data.brand_personality, characteristics: newChars });
                                                                            }}
                                                                            className="flex-1 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm text-sm"
                                                                        />
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => {
                                                                                const newChars = data.brand_personality.characteristics.filter((_, i) => i !== index);
                                                                                setData('brand_personality', { ...data.brand_personality, characteristics: newChars });
                                                                            }}
                                                                            className="px-2 py-1 text-red-600 hover:text-red-800 text-sm"
                                                                        >
                                                                            ✕
                                                                        </button>
                                                                    </div>
                                                                ))}
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        setData('brand_personality', { 
                                                                            ...data.brand_personality, 
                                                                            characteristics: [...(data.brand_personality.characteristics || []), ''] 
                                                                        });
                                                                    }}
                                                                    className="text-xs text-blue-600 hover:text-blue-800"
                                                                >
                                                                    + Add
                                                                </button>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="mt-4">
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">If Brand Were a Person</label>
                                                    {!isEditing ? (
                                                        <p className="text-gray-700">{data.brand_personality.if_brand_were_person}</p>
                                                    ) : (
                                                        <textarea
                                                            rows={2}
                                                            value={data.brand_personality.if_brand_were_person}
                                                            onChange={(e) => setData('brand_personality', { ...data.brand_personality, if_brand_were_person: e.target.value })}
                                                            className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                        />
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Visual Identity Section */}
                                    {activeSection === 'visual' && (
                                        <div className="space-y-6">
                                            <h2 className="text-2xl font-bold text-gray-900">Visual Identity</h2>
                                            
                                            {/* Color Palette */}
                                            <div>
                                                <h3 className="text-lg font-semibold text-gray-900 mb-3">Color Palette</h3>
                                                <div className="grid grid-cols-2 gap-4 mb-4">
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">Primary Colors</label>
                                                        <div className="flex flex-wrap gap-2">
                                                            {data.color_palette.primary_colors?.map((color, index) => (
                                                                <div key={index} className="flex flex-col items-center gap-1">
                                                                    <div 
                                                                        className="w-12 h-12 rounded border border-gray-300" 
                                                                        style={{ backgroundColor: color }}
                                                                    ></div>
                                                                    <span className="text-xs text-gray-600 font-mono">{color}</span>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">Secondary Colors</label>
                                                        <div className="flex flex-wrap gap-2">
                                                            {data.color_palette.secondary_colors?.map((color, index) => (
                                                                <div key={index} className="flex flex-col items-center gap-1">
                                                                    <div 
                                                                        className="w-12 h-12 rounded border border-gray-300" 
                                                                        style={{ backgroundColor: color }}
                                                                    ></div>
                                                                    <span className="text-xs text-gray-600 font-mono">{color}</span>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="space-y-4">
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">Color Description</label>
                                                        {!isEditing ? (
                                                            <p className="text-gray-700">{data.color_palette.description}</p>
                                                        ) : (
                                                            <textarea
                                                                rows={3}
                                                                value={data.color_palette.description}
                                                                onChange={(e) => setData('color_palette', { ...data.color_palette, description: e.target.value })}
                                                                className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                            />
                                                        )}
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">Usage Notes</label>
                                                        {!isEditing ? (
                                                            <p className="text-gray-700">{data.color_palette.usage_notes}</p>
                                                        ) : (
                                                            <textarea
                                                                rows={2}
                                                                value={data.color_palette.usage_notes}
                                                                onChange={(e) => setData('color_palette', { ...data.color_palette, usage_notes: e.target.value })}
                                                                className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                            />
                                                        )}
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Typography */}
                                            <div>
                                                <h3 className="text-lg font-semibold text-gray-900 mb-3">Typography</h3>
                                                <div className="space-y-4">
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">Heading Style</label>
                                                        {!isEditing ? (
                                                            <p className="text-gray-900">{data.typography.heading_style}</p>
                                                        ) : (
                                                            <textarea
                                                                rows={2}
                                                                value={data.typography.heading_style}
                                                                onChange={(e) => setData('typography', { ...data.typography, heading_style: e.target.value })}
                                                                className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                            />
                                                        )}
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">Body Style</label>
                                                        {!isEditing ? (
                                                            <p className="text-gray-900">{data.typography.body_style}</p>
                                                        ) : (
                                                            <textarea
                                                                rows={2}
                                                                value={data.typography.body_style}
                                                                onChange={(e) => setData('typography', { ...data.typography, body_style: e.target.value })}
                                                                className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                            />
                                                        )}
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">Fonts Detected</label>
                                                        {!isEditing ? (
                                                            <div className="flex flex-wrap gap-2">
                                                                {data.typography.fonts_detected?.map((font, index) => (
                                                                    <span key={index} className="inline-flex items-center px-3 py-1 rounded bg-gray-100 text-gray-800 font-mono text-sm">
                                                                        {font}
                                                                    </span>
                                                                ))}
                                                            </div>
                                                        ) : (
                                                            <input
                                                                type="text"
                                                                value={data.typography.fonts_detected?.join(', ')}
                                                                onChange={(e) => setData('typography', { ...data.typography, fonts_detected: e.target.value.split(',').map(f => f.trim()) })}
                                                                className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                placeholder="Arial, Helvetica, sans-serif"
                                                            />
                                                        )}
                                                    </div>
                                                    <div className="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">Font Weights</label>
                                                            {!isEditing ? (
                                                                <p className="text-gray-900">{data.typography.font_weights}</p>
                                                            ) : (
                                                                <input
                                                                    type="text"
                                                                    value={data.typography.font_weights}
                                                                    onChange={(e) => setData('typography', { ...data.typography, font_weights: e.target.value })}
                                                                    className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                            )}
                                                        </div>
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">Letter Spacing</label>
                                                            {!isEditing ? (
                                                                <p className="text-gray-900">{data.typography.letter_spacing}</p>
                                                            ) : (
                                                                <input
                                                                    type="text"
                                                                    value={data.typography.letter_spacing}
                                                                    onChange={(e) => setData('typography', { ...data.typography, letter_spacing: e.target.value })}
                                                                    className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            {/* Visual Style */}
                                            <div>
                                                <h3 className="text-lg font-semibold text-gray-900 mb-3">Visual Style</h3>
                                                <div className="space-y-4">
                                                    <div className="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">Overall Aesthetic</label>
                                                            {!isEditing ? (
                                                                <p className="text-gray-900">{data.visual_style.overall_aesthetic}</p>
                                                            ) : (
                                                                <input
                                                                    type="text"
                                                                    value={data.visual_style.overall_aesthetic}
                                                                    onChange={(e) => setData('visual_style', { ...data.visual_style, overall_aesthetic: e.target.value })}
                                                                    className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                            )}
                                                        </div>
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">Imagery Style</label>
                                                            {!isEditing ? (
                                                                <p className="text-gray-900">{data.visual_style.imagery_style}</p>
                                                            ) : (
                                                                <input
                                                                    type="text"
                                                                    value={data.visual_style.imagery_style}
                                                                    onChange={(e) => setData('visual_style', { ...data.visual_style, imagery_style: e.target.value })}
                                                                    className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">Color Treatment</label>
                                                            {!isEditing ? (
                                                                <p className="text-gray-900">{data.visual_style.color_treatment}</p>
                                                            ) : (
                                                                <input
                                                                    type="text"
                                                                    value={data.visual_style.color_treatment}
                                                                    onChange={(e) => setData('visual_style', { ...data.visual_style, color_treatment: e.target.value })}
                                                                    className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                            )}
                                                        </div>
                                                        <div>
                                                            <label className="block text-sm font-medium text-gray-700 mb-2">Layout Preference</label>
                                                            {!isEditing ? (
                                                                <p className="text-gray-900">{data.visual_style.layout_preference}</p>
                                                            ) : (
                                                                <input
                                                                    type="text"
                                                                    value={data.visual_style.layout_preference}
                                                                    onChange={(e) => setData('visual_style', { ...data.visual_style, layout_preference: e.target.value })}
                                                                    className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <label className="block text-sm font-medium text-gray-700 mb-2">Description</label>
                                                        {!isEditing ? (
                                                            <p className="text-gray-700">{data.visual_style.description}</p>
                                                        ) : (
                                                            <textarea
                                                                rows={4}
                                                                value={data.visual_style.description}
                                                                onChange={(e) => setData('visual_style', { ...data.visual_style, description: e.target.value })}
                                                                className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                            />
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Messaging Section */}
                                    {activeSection === 'messaging' && (
                                        <div className="space-y-6">
                                            <h2 className="text-2xl font-bold text-gray-900">Messaging Themes</h2>
                                            
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700 mb-2">Core Themes</label>
                                                <p className="text-xs text-gray-500 mb-3">Primary themes and messages the brand consistently communicates</p>
                                                {!isEditing ? (
                                                    <ul className="list-disc list-inside space-y-2">
                                                        {data.messaging_themes?.map((theme, index) => (
                                                            <li key={index} className="text-gray-700">{theme}</li>
                                                        ))}
                                                    </ul>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {data.messaging_themes?.map((theme, index) => (
                                                            <div key={index} className="flex gap-2">
                                                                <input
                                                                    type="text"
                                                                    value={theme}
                                                                    onChange={(e) => {
                                                                        const newThemes = [...data.messaging_themes];
                                                                        newThemes[index] = e.target.value;
                                                                        setData('messaging_themes', newThemes);
                                                                    }}
                                                                    className="flex-1 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        const newThemes = data.messaging_themes.filter((_, i) => i !== index);
                                                                        setData('messaging_themes', newThemes);
                                                                    }}
                                                                    className="px-3 py-2 text-red-600 hover:text-red-800"
                                                                >
                                                                    ✕
                                                                </button>
                                                            </div>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setData('messaging_themes', [...(data.messaging_themes || []), '']);
                                                            }}
                                                            className="text-sm text-blue-600 hover:text-blue-800"
                                                        >
                                                            + Add Theme
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}

                                    {/* Target Audience Section */}
                                    {activeSection === 'audience' && (
                                        <div className="space-y-6">
                                            <h2 className="text-2xl font-bold text-gray-900">Target Audience</h2>
                                            
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700 mb-2">Primary Audience</label>
                                                {!isEditing ? (
                                                    <p className="text-gray-700">{data.target_audience.primary}</p>
                                                ) : (
                                                    <textarea
                                                        rows={2}
                                                        value={data.target_audience.primary}
                                                        onChange={(e) => setData('target_audience', { ...data.target_audience, primary: e.target.value })}
                                                        className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                        placeholder="Detailed description of primary target audience"
                                                    />
                                                )}
                                            </div>

                                            <div>
                                                <label className="block text-sm font-medium text-gray-700 mb-2">Demographics</label>
                                                {!isEditing ? (
                                                    <p className="text-gray-700">{data.target_audience.demographics}</p>
                                                ) : (
                                                    <textarea
                                                        rows={3}
                                                        value={data.target_audience.demographics}
                                                        onChange={(e) => setData('target_audience', { ...data.target_audience, demographics: e.target.value })}
                                                        className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                        placeholder="Age, gender, location, income, job titles..."
                                                    />
                                                )}
                                            </div>

                                            <div>
                                                <label className="block text-sm font-medium text-gray-700 mb-2">Psychographics</label>
                                                {!isEditing ? (
                                                    <p className="text-gray-700">{data.target_audience.psychographics}</p>
                                                ) : (
                                                    <textarea
                                                        rows={3}
                                                        value={data.target_audience.psychographics}
                                                        onChange={(e) => setData('target_audience', { ...data.target_audience, psychographics: e.target.value })}
                                                        className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                        placeholder="Values, interests, lifestyle, attitudes..."
                                                    />
                                                )}
                                            </div>

                                            <div>
                                                <label className="block text-sm font-medium text-gray-700 mb-2">Pain Points</label>
                                                {!isEditing ? (
                                                    <ul className="list-disc list-inside space-y-1">
                                                        {data.target_audience.pain_points?.map((point, index) => (
                                                            <li key={index} className="text-gray-700">{point}</li>
                                                        ))}
                                                    </ul>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {data.target_audience.pain_points?.map((point, index) => (
                                                            <div key={index} className="flex gap-2">
                                                                <input
                                                                    type="text"
                                                                    value={point}
                                                                    onChange={(e) => {
                                                                        const newPoints = [...data.target_audience.pain_points];
                                                                        newPoints[index] = e.target.value;
                                                                        setData('target_audience', { ...data.target_audience, pain_points: newPoints });
                                                                    }}
                                                                    className="flex-1 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        const newPoints = data.target_audience.pain_points.filter((_, i) => i !== index);
                                                                        setData('target_audience', { ...data.target_audience, pain_points: newPoints });
                                                                    }}
                                                                    className="px-3 py-2 text-red-600 hover:text-red-800"
                                                                >
                                                                    ✕
                                                                </button>
                                                            </div>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setData('target_audience', { 
                                                                    ...data.target_audience, 
                                                                    pain_points: [...(data.target_audience.pain_points || []), ''] 
                                                                });
                                                            }}
                                                            className="text-sm text-blue-600 hover:text-blue-800"
                                                        >
                                                            + Add Pain Point
                                                        </button>
                                                    </div>
                                                )}
                                            </div>

                                            <div className="grid grid-cols-2 gap-4">
                                                <div>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">Language Level</label>
                                                    {!isEditing ? (
                                                        <p className="text-gray-900">{data.target_audience.language_level}</p>
                                                    ) : (
                                                        <input
                                                            type="text"
                                                            value={data.target_audience.language_level}
                                                            onChange={(e) => setData('target_audience', { ...data.target_audience, language_level: e.target.value })}
                                                            className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                            placeholder="e.g., professional, technical, casual"
                                                        />
                                                    )}
                                                </div>
                                                <div>
                                                    <label className="block text-sm font-medium text-gray-700 mb-2">Familiarity Assumption</label>
                                                    {!isEditing ? (
                                                        <p className="text-gray-900">{data.target_audience.familiarity_assumption}</p>
                                                    ) : (
                                                        <input
                                                            type="text"
                                                            value={data.target_audience.familiarity_assumption}
                                                            onChange={(e) => setData('target_audience', { ...data.target_audience, familiarity_assumption: e.target.value })}
                                                            className="w-full border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                            placeholder="e.g., beginner, intermediate, expert"
                                                        />
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Differentiation Section */}
                                    {activeSection === 'differentiation' && (
                                        <div className="space-y-6">
                                            <h2 className="text-2xl font-bold text-gray-900">Competitor Differentiation</h2>
                                            
                                            <div>
                                                <label className="block text-sm font-medium text-gray-700 mb-2">Key Differentiators</label>
                                                <p className="text-xs text-gray-500 mb-3">How the brand positions itself differently from competitors</p>
                                                {!isEditing ? (
                                                    <ul className="list-disc list-inside space-y-2">
                                                        {data.competitor_differentiation?.map((point, index) => (
                                                            <li key={index} className="text-gray-700">{point}</li>
                                                        ))}
                                                    </ul>
                                                ) : (
                                                    <div className="space-y-2">
                                                        {data.competitor_differentiation?.map((point, index) => (
                                                            <div key={index} className="flex gap-2">
                                                                <input
                                                                    type="text"
                                                                    value={point}
                                                                    onChange={(e) => {
                                                                        const newPoints = [...data.competitor_differentiation];
                                                                        newPoints[index] = e.target.value;
                                                                        setData('competitor_differentiation', newPoints);
                                                                    }}
                                                                    className="flex-1 border-gray-300 focus:border-blue-500 focus:ring-blue-500 rounded-md shadow-sm"
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        const newPoints = data.competitor_differentiation.filter((_, i) => i !== index);
                                                                        setData('competitor_differentiation', newPoints);
                                                                    }}
                                                                    className="px-3 py-2 text-red-600 hover:text-red-800"
                                                                >
                                                                    ✕
                                                                </button>
                                                            </div>
                                                        ))}
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                setData('competitor_differentiation', [...(data.competitor_differentiation || []), '']);
                                                            }}
                                                            className="text-sm text-blue-600 hover:text-blue-800"
                                                        >
                                                            + Add Point
                                                        </button>
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
