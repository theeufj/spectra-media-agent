
import React from 'react';
import Card from './Card';

const severityStyles = {
    high: 'bg-red-100 text-red-800',
    medium: 'bg-yellow-100 text-yellow-800',
    low: 'bg-green-100 text-green-800',
};

const ConflictCard = ({ conflict, onResolve }) => {
    if (!conflict) return null;

    const { title, description, severity = 'medium', campaigns = [], recommendation } = conflict;

    return (
        <Card className="border-l-4 border-l-yellow-400">
            <div className="flex items-start justify-between gap-3">
                <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-1">
                        <svg className="w-5 h-5 text-yellow-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                        </svg>
                        <h4 className="font-medium text-gray-900 truncate">{title}</h4>
                        <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${severityStyles[severity] || severityStyles.medium}`}>
                            {severity}
                        </span>
                    </div>
                    <p className="text-sm text-gray-600 mb-2">{description}</p>
                    {campaigns.length > 0 && (
                        <div className="flex flex-wrap gap-1 mb-2">
                            {campaigns.map((name, i) => (
                                <span key={i} className="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-700">
                                    {name}
                                </span>
                            ))}
                        </div>
                    )}
                    {recommendation && (
                        <p className="text-sm text-blue-700 bg-blue-50 rounded p-2">
                            <span className="font-medium">Recommendation:</span> {recommendation}
                        </p>
                    )}
                </div>
                {onResolve && (
                    <button
                        onClick={() => onResolve(conflict)}
                        className="flex-shrink-0 px-3 py-1.5 text-sm font-medium text-flame-orange-600 bg-flame-orange-50 rounded-md hover:bg-flame-orange-100 transition-colors"
                    >
                        Resolve
                    </button>
                )}
            </div>
        </Card>
    );
};

export default ConflictCard;
