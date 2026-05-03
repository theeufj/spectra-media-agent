import React from 'react';
import { Link } from '@inertiajs/react';

export default function DemoResultsPanel({ result }) {
    if (!result) return null;

    const { url, ad_copy, visuals } = result;

    return (
        <div className="w-full max-w-5xl mx-auto bg-white rounded-xl shadow-xl overflow-hidden mt-8 border border-gray-100">
            <div className="p-8">
                <div className="text-center mb-10">
                    <h2 className="text-3xl font-extrabold text-gray-900">Your AI-Generated Ad Package</h2>
                    <p className="mt-2 text-gray-600">Extracted from <span className="font-semibold">{url}</span></p>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-10">
                    {/* Brand Identity / Visuals */}
                    <div>
                        <h3 className="text-xl font-bold text-gray-800 mb-4 flex items-center">
                            <span className="text-2xl mr-2">🎨</span> Extracted Brand Identity
                        </h3>
                        <div className="bg-gray-50 rounded-lg p-6 border border-gray-200 h-full">
                            <div className="mb-6">
                                <h4 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">Color Palette</h4>
                                <div className="flex flex-wrap gap-3">
                                    {visuals?.colors?.length > 0 ? (
                                        visuals.colors.map((color, idx) => (
                                            <div key={idx} className="flex flex-col items-center">
                                                <div 
                                                    className="w-12 h-12 rounded-full border border-gray-300 shadow-sm"
                                                    style={{ backgroundColor: color }}
                                                ></div>
                                                <span className="text-xs mt-1 text-gray-600 uppercase">{color}</span>
                                            </div>
                                        ))
                                    ) : (
                                        <p className="text-sm text-gray-500">No specific colors extracted.</p>
                                    )}
                                </div>
                            </div>
                            
                            <div className="mb-6">
                                <h4 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Typography</h4>
                                {visuals?.fonts?.length > 0 ? (
                                    <ul className="list-disc pl-5 text-gray-800">
                                        {visuals.fonts.map((font, idx) => (
                                            <li key={idx}>{font}</li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-sm text-gray-500">Standard Web Fonts</p>
                                )}
                            </div>

                            <div>
                                <h4 className="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-2">Visual Vibe</h4>
                                <p className="text-gray-800 text-sm leading-relaxed">{visuals?.style_description || "Professional & Modern"}</p>
                            </div>
                        </div>
                    </div>

                    {/* Google Ad Preview */}
                    <div>
                        <h3 className="text-xl font-bold text-gray-800 mb-4 flex items-center">
                            <span className="text-2xl mr-2">🔍</span> Google Ad Preview
                        </h3>
                        <div className="bg-white rounded-lg p-6 border border-gray-200 shadow-sm h-full">
                            <div className="flex items-center text-sm text-gray-600 mb-1">
                                <span className="font-bold text-black mr-2">Ad</span> · {url}
                            </div>
                            <div className="text-blue-700 text-xl font-medium hover:underline cursor-pointer leading-tight mb-2">
                                {ad_copy?.headlines?.slice(0, 3).join(' | ') || "Transform Your Business | Sign Up Today"}
                            </div>
                            <div className="text-gray-600 text-sm">
                                {ad_copy?.descriptions?.slice(0, 2).join(' ') || "Discover why thousands trust our platform. Flexible pricing to suit any scale."}
                            </div>
                        </div>
                    </div>
                </div>

                <div className="mt-12 text-center bg-flame-orange-50 rounded-lg p-8 border border-flame-orange-100">
                    <h3 className="text-2xl font-bold text-gray-900 mb-2">Ready to deploy these campaigns?</h3>
                    <p className="text-gray-600 mb-6">Our AI agents will build out your entire account structure, write dozens of variations, and manage the budget automatically.</p>
                    <Link 
                        href={`/register?demo_url=${encodeURIComponent(url)}`}
                        className="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-lg text-white bg-flame-orange-600 hover:bg-flame-orange-700 shadow-lg transition-colors w-full sm:w-auto"
                    >
                        Deploy Automatically — Start Free Trial
                    </Link>
                </div>
            </div>
        </div>
    );
}
