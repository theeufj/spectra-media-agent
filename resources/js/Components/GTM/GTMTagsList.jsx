import React from 'react';

/**
 * GTMTagsList Component
 * Displays a list of GTM tags with their details
 */
export default function GTMTagsList({ tags = [] }) {
    if (!tags || tags.length === 0) {
        return (
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <p className="text-sm text-gray-600">No tags configured yet.</p>
            </div>
        );
    }

    const getTagTypeIcon = (type) => {
        switch (type) {
            case 'gads':
            case 'google_ads':
                return (
                    <svg className="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M2 6a2 2 0 012-2h6a2 2 0 012 2v8a2 2 0 01-2 2H4a2 2 0 01-2-2V6zM14.553 7.106A1 1 0 0014 8v4a1 1 0 00.553.894l2 1A1 1 0 0018 13V7a1 1 0 00-1.447-.894l-2 1z" />
                    </svg>
                );
            case 'ga4':
            case 'analytics':
                return (
                    <svg className="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z" />
                    </svg>
                );
            default:
                return (
                    <svg className="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                    </svg>
                );
        }
    };

    return (
        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
            <div className="px-4 py-3 bg-gray-50 border-b border-gray-200">
                <h4 className="text-sm font-medium text-gray-900">Configured Tags</h4>
            </div>
            <ul className="divide-y divide-gray-200">
                {tags.map((tag, index) => (
                    <li key={index} className="px-4 py-3 hover:bg-gray-50">
                        <div className="flex items-start">
                            <div className="flex-shrink-0 mt-1">{getTagTypeIcon(tag.type)}</div>
                            <div className="ml-3 flex-1">
                                <div className="flex items-center justify-between">
                                    <p className="text-sm font-medium text-gray-900">{tag.name}</p>
                                    {tag.status && (
                                        <span
                                            className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                tag.status === 'active'
                                                    ? 'bg-green-100 text-green-800'
                                                    : 'bg-gray-100 text-gray-800'
                                            }`}
                                        >
                                            {tag.status}
                                        </span>
                                    )}
                                </div>
                                {tag.type && (
                                    <p className="text-xs text-gray-500 mt-1">
                                        Type: {tag.type.toUpperCase()}
                                    </p>
                                )}
                                {tag.conversion_id && (
                                    <p className="text-xs text-gray-500 mt-1">
                                        Conversion ID: {tag.conversion_id}
                                    </p>
                                )}
                                {tag.trigger && (
                                    <p className="text-xs text-gray-500 mt-1">
                                        Trigger: {tag.trigger}
                                    </p>
                                )}
                            </div>
                        </div>
                    </li>
                ))}
            </ul>
        </div>
    );
}
