
import React, { useState } from 'react';

const DataTable = ({ headers, data }) => {
    const [searchTerm, setSearchTerm] = useState('');

    const filteredData = data.filter(row =>
        row.some(cell =>
            String(cell).toLowerCase().includes(searchTerm.toLowerCase())
        )
    );

    return (
        <div>
            <div className="mb-4">
                <input
                    type="text"
                    placeholder="Search..."
                    className="w-full px-3 py-2 border border-gray-300 rounded-md"
                    onChange={e => setSearchTerm(e.target.value)}
                />
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-full bg-white">
                    <thead>
                        <tr>
                            {headers.map((header, index) => (
                                <th key={index} className="px-6 py-3 border-b-2 border-gray-300 text-left leading-4 text-blue-500 tracking-wider">{header}</th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {filteredData.map((row, rowIndex) => (
                            <tr key={rowIndex}>
                                {row.map((cell, cellIndex) => (
                                    <td key={cellIndex} className="px-6 py-4 whitespace-no-wrap border-b border-gray-500">{cell}</td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default DataTable;
