import React from 'react';

const WaitingForData = () => (
    <div className="text-center bg-white p-12 rounded-lg shadow-md">
        <h3 className="text-2xl font-semibold text-gray-800">Campaign Deployed</h3>
        <p className="mt-2 text-gray-600">Your campaign is live and running. Performance data is usually available within a few hours.</p>
        <p className="mt-4 text-sm text-gray-500">Please check back soon to see your results.</p>
    </div>
);

export default WaitingForData;
