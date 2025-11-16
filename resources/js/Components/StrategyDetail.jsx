
import React from 'react';
import Card from './Card';

const StrategyDetail = ({ strategy }) => {
    // Mock data structure
    const mockStrategy = {
        platform: 'Google Ads (SEM)',
        ad_copy_strategy: 'Write concise, keyword-rich headlines and descriptions...',
        imagery_strategy: 'For Responsive Display Ads, use high-contrast infographics...',
        video_strategy: 'N/A for text-based search ads.',
        bidding_strategy: {
            name: 'TargetCpa',
            parameters: { targetCpaMicros: 50000000 },
        },
        revenue_cpa_multiple: 3.0,
    };

    const strat = strategy || mockStrategy;

    return (
        <Card>
            <h3 className="text-lg font-semibold mb-2">{strat.platform} Strategy</h3>
            <div className="space-y-4">
                <div>
                    <h4 className="font-semibold">Ad Copy</h4>
                    <p className="text-gray-600">{strat.ad_copy_strategy}</p>
                </div>
                <div>
                    <h4 className="font-semibold">Imagery</h4>
                    <p className="text-gray-600">{strat.imagery_strategy}</p>
                </div>
                <div>
                    <h4 className="font-semibold">Video</h4>
                    <p className="text-gray-600">{strat.video_strategy}</p>
                </div>
                <div>
                    <h4 className="font-semibold">Bidding Strategy</h4>
                    <p className="text-gray-600">
                        {strat.bidding_strategy.name}
                        {strat.bidding_strategy.parameters.targetCpaMicros &&
                            ` ($${strat.bidding_strategy.parameters.targetCpaMicros / 1000000} Target CPA)`
                        }
                    </p>
                </div>
                <div>
                    <h4 className="font-semibold">Revenue Multiple</h4>
                    <p className="text-gray-600">{strat.revenue_cpa_multiple}x</p>
                </div>
            </div>
        </Card>
    );
};

export default StrategyDetail;
