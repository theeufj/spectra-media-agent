import React, { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SideNav from './SideNav';

export default function Settings({ settings }) {
    const deploymentSetting = settings.find(s => s.key === 'deployment_enabled');
    
    const { data, setData, post, processing } = useForm({
        deployment_enabled: deploymentSetting ? deploymentSetting.value === '1' : false,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.settings.update'));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Admin - Settings
                </h2>
            }
        >
            <Head title="Admin - Settings" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 p-8">
                    <div className="max-w-4xl mx-auto">
                        <div className="bg-white shadow-md rounded-lg overflow-hidden">
                            <div className="px-6 py-4 bg-gradient-to-r from-purple-600 to-indigo-600">
                                <h3 className="text-lg font-semibold text-white">System Settings</h3>
                                <p className="text-sm text-purple-100 mt-1">Configure platform-wide settings and features</p>
                            </div>

                            <form onSubmit={handleSubmit} className="p-6 space-y-6">
                                {/* Deployment Settings Section */}
                                <div className="border-b border-gray-200 pb-6">
                                    <h4 className="text-lg font-medium text-gray-900 mb-4">Deployment Settings</h4>
                                    
                                    <div className="flex items-start">
                                        <div className="flex-1">
                                            <label className="flex items-center cursor-pointer">
                                                <div className="relative">
                                                    <input
                                                        type="checkbox"
                                                        checked={data.deployment_enabled}
                                                        onChange={(e) => setData('deployment_enabled', e.target.checked)}
                                                        className="sr-only"
                                                    />
                                                    <div className={`block w-14 h-8 rounded-full transition ${
                                                        data.deployment_enabled 
                                                            ? 'bg-green-500' 
                                                            : 'bg-gray-300'
                                                    }`}></div>
                                                    <div className={`absolute left-1 top-1 bg-white w-6 h-6 rounded-full transition-transform ${
                                                        data.deployment_enabled 
                                                            ? 'transform translate-x-6' 
                                                            : ''
                                                    }`}></div>
                                                </div>
                                                <div className="ml-4">
                                                    <span className="text-sm font-medium text-gray-900">
                                                        Enable Campaign Deployment
                                                    </span>
                                                    <p className="text-sm text-gray-500 mt-1">
                                                        When disabled, users can still subscribe and create campaigns, but deployment to advertising platforms will be blocked with a friendly maintenance message.
                                                    </p>
                                                </div>
                                            </label>
                                        </div>
                                    </div>

                                    {/* Status Banner */}
                                    <div className={`mt-4 p-4 rounded-lg ${
                                        data.deployment_enabled 
                                            ? 'bg-green-50 border border-green-200' 
                                            : 'bg-yellow-50 border border-yellow-200'
                                    }`}>
                                        <div className="flex items-start">
                                            {data.deployment_enabled ? (
                                                <>
                                                    <svg className="w-5 h-5 text-green-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    <div className="ml-3">
                                                        <h5 className="text-sm font-medium text-green-800">Deployment Active</h5>
                                                        <p className="text-sm text-green-700 mt-1">
                                                            Users can deploy campaigns to advertising platforms.
                                                        </p>
                                                    </div>
                                                </>
                                            ) : (
                                                <>
                                                    <svg className="w-5 h-5 text-yellow-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                                    </svg>
                                                    <div className="ml-3">
                                                        <h5 className="text-sm font-medium text-yellow-800">Deployment Disabled</h5>
                                                        <p className="text-sm text-yellow-700 mt-1">
                                                            Users will see a maintenance message when attempting to deploy. This is useful for testing subscription conversion without affecting live campaigns.
                                                        </p>
                                                    </div>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Save Button */}
                                <div className="flex justify-end">
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="inline-flex items-center px-6 py-3 bg-gradient-to-r from-purple-600 to-indigo-600 border border-transparent rounded-md font-semibold text-white hover:from-purple-700 hover:to-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                                    >
                                        {processing ? (
                                            <>
                                                <svg className="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                Saving...
                                            </>
                                        ) : (
                                            <>
                                                <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                                </svg>
                                                Save Settings
                                            </>
                                        )}
                                    </button>
                                </div>
                            </form>
                        </div>

                        {/* Information Card */}
                        <div className="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-6">
                            <div className="flex items-start">
                                <svg className="w-6 h-6 text-blue-500 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <div className="ml-4">
                                    <h4 className="text-sm font-semibold text-blue-900">Testing Subscription Conversion</h4>
                                    <p className="text-sm text-blue-800 mt-2">
                                        By disabling deployment, you can measure how many users are willing to enter their payment details and subscribe before the full deployment feature is ready. Users will:
                                    </p>
                                    <ul className="list-disc list-inside text-sm text-blue-800 mt-2 space-y-1 ml-4">
                                        <li>Still be able to create campaigns and generate collateral</li>
                                        <li>Be able to subscribe and enter payment information</li>
                                        <li>See a friendly "coming soon" message when trying to deploy</li>
                                        <li>Not be able to push campaigns live to advertising platforms</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
