import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, usePage, Link } from '@inertiajs/react';
import SideNav from './SideNav';

const JsonViewer = ({ data, title }) => (
    <div className="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div className="px-6 py-4 border-b border-gray-200 bg-gray-50">
            <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
        </div>
        <div className="p-6">
            <pre className="bg-gray-900 text-green-400 p-4 rounded-lg overflow-x-auto text-sm">
                {JSON.stringify(data, null, 2)}
            </pre>
        </div>
    </div>
);

const InfoCard = ({ label, value, className = '' }) => (
    <div className={`bg-white rounded-lg shadow p-4 ${className}`}>
        <dt className="text-sm font-medium text-gray-500 mb-1">{label}</dt>
        <dd className="text-lg font-semibold text-gray-900">{value}</dd>
    </div>
);

const StatusBadge = ({ success }) => (
    <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
        success 
            ? 'bg-green-100 text-green-800' 
            : 'bg-red-100 text-red-800'
    }`}>
        {success ? (
            <>
                <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                </svg>
                Success
            </>
        ) : (
            <>
                <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                </svg>
                Failed
            </>
        )}
    </span>
);

const ErrorsList = ({ errors }) => {
    if (!errors || errors.length === 0) {
        return (
            <div className="text-center py-8 text-gray-500">
                No errors recorded
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {errors.map((error, index) => (
                <div key={index} className="bg-red-50 border border-red-200 rounded-lg p-4">
                    <div className="flex items-start">
                        <svg className="w-5 h-5 text-red-600 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                        </svg>
                        <div className="flex-1">
                            <h4 className="text-sm font-medium text-red-900">
                                {error.type || 'Unknown Error'}
                            </h4>
                            <p className="text-sm text-red-700 mt-1">
                                {error.message || 'No error message provided'}
                            </p>
                            {error.context && (
                                <div className="mt-2">
                                    <details className="text-xs text-red-600">
                                        <summary className="cursor-pointer hover:text-red-800">
                                            View context
                                        </summary>
                                        <pre className="mt-2 bg-red-100 p-2 rounded overflow-x-auto">
                                            {JSON.stringify(error.context, null, 2)}
                                        </pre>
                                    </details>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            ))}
        </div>
    );
};

const ExecutionSteps = ({ plan }) => {
    if (!plan || !plan.steps || plan.steps.length === 0) {
        return (
            <div className="text-center py-8 text-gray-500">
                No execution steps available
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {plan.steps.map((step, index) => (
                <div key={index} className="flex">
                    <div className="flex flex-col items-center mr-4">
                        <div className="flex items-center justify-center w-8 h-8 rounded-full bg-indigo-600 text-white text-sm font-bold">
                            {index + 1}
                        </div>
                        {index < plan.steps.length - 1 && (
                            <div className="w-0.5 h-full bg-indigo-300 mt-2"></div>
                        )}
                    </div>
                    <div className="flex-1 bg-gray-50 rounded-lg p-4 mb-2">
                        <h4 className="font-medium text-gray-900">{step.action || step.type}</h4>
                        {step.description && (
                            <p className="text-sm text-gray-600 mt-1">{step.description}</p>
                        )}
                        {step.budget && (
                            <p className="text-sm text-gray-500 mt-2">
                                <span className="font-medium">Budget:</span> ${step.budget}
                            </p>
                        )}
                        {step.parameters && Object.keys(step.parameters).length > 0 && (
                            <details className="mt-2">
                                <summary className="text-xs text-indigo-600 cursor-pointer hover:text-indigo-800">
                                    View parameters
                                </summary>
                                <pre className="mt-2 text-xs bg-white p-2 rounded border border-gray-200 overflow-x-auto">
                                    {JSON.stringify(step.parameters, null, 2)}
                                </pre>
                            </details>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
};

export default function ExecutionDetail({ auth }) {
    const { strategy } = usePage().props;

    return (
        <AuthenticatedLayout
            user={auth.user}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="font-semibold text-xl text-gray-800 leading-tight">
                        Execution Details
                    </h2>
                    <Link
                        href={route('admin.execution.metrics')}
                        className="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 focus:bg-gray-700 active:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition ease-in-out duration-150"
                    >
                        <svg className="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                        </svg>
                        Back to Metrics
                    </Link>
                </div>
            }
        >
            <Head title="Admin - Execution Detail" />

            <div className="flex">
                <SideNav />
                <div className="flex-1 py-12">
                    <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                        {/* Header Info */}
                        <div className="bg-white rounded-lg shadow p-6 mb-6">
                            <div className="flex items-center justify-between mb-4">
                                <div>
                                    <h3 className="text-2xl font-bold text-gray-900">
                                        {strategy.campaign_name}
                                    </h3>
                                    <p className="text-sm text-gray-600 mt-1">
                                        Customer: {strategy.customer_name}
                                    </p>
                                </div>
                                <StatusBadge success={strategy.execution_result?.success} />
                            </div>
                            
                            <dl className="grid grid-cols-1 md:grid-cols-4 gap-4 mt-6">
                                <InfoCard 
                                    label="Platform" 
                                    value={strategy.platform} 
                                />
                                <InfoCard 
                                    label="Execution Time" 
                                    value={`${strategy.execution_time || 0}s`} 
                                />
                                <InfoCard 
                                    label="Created" 
                                    value={new Date(strategy.created_at).toLocaleDateString()} 
                                />
                                <InfoCard 
                                    label="Last Updated" 
                                    value={new Date(strategy.updated_at).toLocaleDateString()} 
                                />
                            </dl>
                        </div>

                        {/* Execution Plan */}
                        <div className="bg-white rounded-lg shadow overflow-hidden mb-6">
                            <div className="px-6 py-4 border-b border-gray-200 bg-gray-50">
                                <h3 className="text-lg font-semibold text-gray-900">Execution Plan</h3>
                                <p className="text-sm text-gray-600 mt-1">AI-generated deployment strategy</p>
                            </div>
                            <div className="p-6">
                                <ExecutionSteps plan={strategy.execution_plan} />
                            </div>
                        </div>

                        {/* Execution Errors */}
                        {strategy.execution_errors && strategy.execution_errors.length > 0 && (
                            <div className="bg-white rounded-lg shadow overflow-hidden mb-6">
                                <div className="px-6 py-4 border-b border-gray-200 bg-gray-50">
                                    <h3 className="text-lg font-semibold text-gray-900">Execution Errors</h3>
                                    <p className="text-sm text-gray-600 mt-1">
                                        {strategy.execution_errors.length} error{strategy.execution_errors.length !== 1 ? 's' : ''} encountered
                                    </p>
                                </div>
                                <div className="p-6">
                                    <ErrorsList errors={strategy.execution_errors} />
                                </div>
                            </div>
                        )}

                        {/* Full JSON Views */}
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <JsonViewer 
                                data={strategy.execution_plan} 
                                title="Execution Plan (JSON)" 
                            />
                            <JsonViewer 
                                data={strategy.execution_result} 
                                title="Execution Result (JSON)" 
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
