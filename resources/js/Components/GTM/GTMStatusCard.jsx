import React from 'react';
import GTMStatusBadge from '@/Components/GTM/GTMStatusBadge';

/**
 * GTMStatusCard Component
 * Displays the current GTM installation status with relevant details
 */
export default function GTMStatusCard({ customer, onRescan }) {
    const getStatusFromCustomer = () => {
        if (customer.gtm_installed && customer.gtm_last_verified) {
            return 'verified';
        }
        if (customer.gtm_container_id) {
            return 'linked';
        }
        if (customer.gtm_detected) {
            return 'detected';
        }
        return 'not_detected';
    };

    const status = getStatusFromCustomer();

    return (
        <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
            <div className="p-6">
                <div className="flex justify-between items-start mb-4">
                    <div>
                        <h3 className="text-lg font-semibold text-gray-900">GTM Status</h3>
                        <p className="text-sm text-gray-600 mt-1">
                            Current installation and verification status
                        </p>
                    </div>
                    <GTMStatusBadge status={status} />
                </div>

                <div className="space-y-3 mt-4">
                    {customer.gtm_detected && (
                        <div className="flex items-center text-sm">
                            <span className="text-gray-600 w-40">Detected Container:</span>
                            <span className="font-mono text-gray-900">
                                {customer.gtm_container_id || 'Unknown'}
                            </span>
                        </div>
                    )}

                    {customer.gtm_account_id && (
                        <div className="flex items-center text-sm">
                            <span className="text-gray-600 w-40">GTM Account ID:</span>
                            <span className="font-mono text-gray-900">{customer.gtm_account_id}</span>
                        </div>
                    )}

                    {customer.gtm_workspace_id && (
                        <div className="flex items-center text-sm">
                            <span className="text-gray-600 w-40">Workspace ID:</span>
                            <span className="font-mono text-gray-900">{customer.gtm_workspace_id}</span>
                        </div>
                    )}

                    {customer.gtm_last_verified && (
                        <div className="flex items-center text-sm">
                            <span className="text-gray-600 w-40">Last Verified:</span>
                            <span className="text-gray-900">
                                {new Date(customer.gtm_last_verified).toLocaleString()}
                            </span>
                        </div>
                    )}

                    {customer.gtm_detected_at && (
                        <div className="flex items-center text-sm">
                            <span className="text-gray-600 w-40">Detected At:</span>
                            <span className="text-gray-900">
                                {new Date(customer.gtm_detected_at).toLocaleString()}
                            </span>
                        </div>
                    )}
                </div>

                {onRescan && (
                    <div className="mt-6 pt-4 border-t border-gray-200">
                        <button
                            onClick={onRescan}
                            className="text-sm text-indigo-600 hover:text-indigo-900 font-medium"
                        >
                            Re-scan Website for GTM
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
