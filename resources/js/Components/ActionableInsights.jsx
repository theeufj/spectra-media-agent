
import React from 'react';
import Card from './Card';

const impactStyles = {
    high: 'bg-red-100 text-red-800',
    medium: 'bg-yellow-100 text-yellow-800',
    low: 'bg-blue-100 text-blue-800',
};

const typeIcons = {
    budget: (
        <svg className="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
    ),
    performance: (
        <svg className="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
        </svg>
    ),
    targeting: (
        <svg className="w-5 h-5 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
    ),
};

const ActionableInsights = ({ insights, onAction }) => {
    if (!insights || insights.length === 0) {
        return (
            <Card>
                <h3 className="text-lg font-semibold mb-4">Actionable Insights</h3>
                <p className="text-gray-500 text-center py-4">
                    No insights available yet. Insights will appear as your campaigns gather data.
                </p>
            </Card>
        );
    }

    return (
        <Card>
            <h3 className="text-lg font-semibold mb-4">Actionable Insights</h3>
            <div className="space-y-3">
                {insights.map((insight, index) => (
                    <div key={index} className="flex items-start gap-3 p-3 rounded-lg bg-gray-50 hover:bg-gray-100 transition-colors">
                        <div className="flex-shrink-0 mt-0.5">
                            {typeIcons[insight.type] || typeIcons.performance}
                        </div>
                        <div className="flex-1 min-w-0">
                            <div className="flex items-center gap-2 mb-0.5">
                                <h4 className="text-sm font-medium text-gray-900">{insight.title}</h4>
                                {insight.impact && (
                                    <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium ${impactStyles[insight.impact] || impactStyles.medium}`}>
                                        {insight.impact}
                                    </span>
                                )}
                            </div>
                            <p className="text-sm text-gray-600">{insight.description}</p>
                        </div>
                        {onAction && insight.action && (
                            <button
                                onClick={() => onAction(insight)}
                                className="flex-shrink-0 px-3 py-1 text-xs font-medium text-flame-orange-600 bg-white border border-flame-orange-200 rounded hover:bg-flame-orange-50 transition-colors"
                            >
                                {insight.action}
                            </button>
                        )}
                    </div>
                ))}
            </div>
        </Card>
    );
};

export default ActionableInsights;
