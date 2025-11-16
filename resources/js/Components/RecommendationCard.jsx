
import React from 'react';
import Card from './Card';
import PrimaryButton from './PrimaryButton';
import SecondaryButton from './SecondaryButton';

const RecommendationCard = ({ recommendation, onApprove, onReject }) => {
    // Mock data structure
    const mockRecommendation = {
        id: 1,
        type: 'INCREASE_BUDGET',
        rationale: 'Campaign is performing well with a high ROAS of 3.5x. Consider increasing the budget by 20% to scale.',
        parameters: { increase_percentage: 20 },
    };

    const rec = recommendation || mockRecommendation;

    return (
        <Card className="bg-blue-50 border-l-4 border-blue-500">
            <h4 className="text-lg font-semibold text-blue-800">Recommendation</h4>
            <p className="mt-2 text-gray-700">{rec.rationale}</p>
            <div className="mt-4 flex justify-end space-x-2">
                <SecondaryButton onClick={() => onReject(rec.id)}>Reject</SecondaryButton>
                <PrimaryButton onClick={() => onApprove(rec.id)}>Approve</PrimaryButton>
            </div>
        </Card>
    );
};

export default RecommendationCard;
