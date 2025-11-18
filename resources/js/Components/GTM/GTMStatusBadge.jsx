import React from 'react';

/**
 * GTMStatusBadge Component
 * Displays a colored badge indicating the current GTM installation status
 */
export default function GTMStatusBadge({ status }) {
    const statusConfig = {
        not_detected: {
            label: 'Not Detected',
            className: 'bg-gray-100 text-gray-800 border-gray-300',
        },
        detected: {
            label: 'GTM Detected',
            className: 'bg-blue-100 text-blue-800 border-blue-300',
        },
        linked: {
            label: 'Container Linked',
            className: 'bg-green-100 text-green-800 border-green-300',
        },
        verified: {
            label: 'Verified & Ready',
            className: 'bg-green-100 text-green-800 border-green-300',
        },
        error: {
            label: 'Error',
            className: 'bg-red-100 text-red-800 border-red-300',
        },
    };

    const config = statusConfig[status] || statusConfig.not_detected;

    return (
        <span
            className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium border ${config.className}`}
        >
            {config.label}
        </span>
    );
}
