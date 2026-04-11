import React from 'react';

const CampaignSelector = ({ campaigns, selectedCampaign, setSelectedCampaign, showAllOption = false }) => {
    return (
        <div>
            <label htmlFor="campaign" className="sr-only">Select Campaign</label>
            <select
                id="campaign"
                className="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-flame-orange-500 focus:border-flame-orange-500 sm:text-sm rounded-md"
                value={selectedCampaign ? selectedCampaign.id : ''}
                onChange={(e) => {
                    const val = e.target.value;
                    if (val === '' || val === 'all') {
                        setSelectedCampaign(null);
                        return;
                    }
                    const campaignId = parseInt(val);
                    const campaign = campaigns.find(c => c.id === campaignId);
                    setSelectedCampaign(campaign);
                }}
            >
                {showAllOption && <option value="">All Campaigns</option>}
                {campaigns.map(campaign => (
                    <option key={campaign.id} value={campaign.id}>{campaign.name}</option>
                ))}
            </select>
        </div>
    );
};

export default CampaignSelector;
