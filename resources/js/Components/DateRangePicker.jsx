
import React from 'react';

const presets = [
    { label: 'Last 7 days', days: 7 },
    { label: 'Last 30 days', days: 30 },
    { label: 'Last 90 days', days: 90 },
];

const toInputDate = (date) => {
    if (!date) return '';
    const d = new Date(date);
    return d.toISOString().split('T')[0];
};

const DateRangePicker = ({ value, onChange }) => {
    const handlePreset = (days) => {
        const end = new Date();
        const start = new Date();
        start.setDate(start.getDate() - days);
        onChange({ start, end });
    };

    const handleStartChange = (e) => {
        const start = new Date(e.target.value + 'T00:00:00');
        if (!isNaN(start.getTime())) {
            onChange({ ...value, start });
        }
    };

    const handleEndChange = (e) => {
        const end = new Date(e.target.value + 'T00:00:00');
        if (!isNaN(end.getTime())) {
            onChange({ ...value, end });
        }
    };

    return (
        <div className="flex items-center gap-2 flex-wrap">
            <div className="flex items-center gap-1.5">
                <input
                    type="date"
                    value={toInputDate(value?.start)}
                    onChange={handleStartChange}
                    className="block w-[130px] text-sm border-gray-300 rounded-md shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                />
                <span className="text-gray-400 text-sm">–</span>
                <input
                    type="date"
                    value={toInputDate(value?.end)}
                    onChange={handleEndChange}
                    className="block w-[130px] text-sm border-gray-300 rounded-md shadow-sm focus:border-flame-orange-500 focus:ring-flame-orange-500"
                />
            </div>
            <div className="flex items-center gap-1">
                {presets.map((preset) => (
                    <button
                        key={preset.days}
                        type="button"
                        onClick={() => handlePreset(preset.days)}
                        className="px-2 py-1 text-xs font-medium text-gray-600 bg-gray-100 rounded hover:bg-gray-200 transition-colors"
                    >
                        {preset.label}
                    </button>
                ))}
            </div>
        </div>
    );
};

export default DateRangePicker;
