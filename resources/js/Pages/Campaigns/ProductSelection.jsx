import React, { useState, useEffect } from 'react';
import axios from 'axios';
import InputLabel from '@/Components/InputLabel';
import { Combobox, ComboboxInput, ComboboxButton, ComboboxOptions, ComboboxOption } from '@headlessui/react';

export default function ProductSelection({ customerId, selectedPages, onSelectionChange }) {
    const [pages, setPages] = useState([]);
    const [loading, setLoading] = useState(false);
    const [query, setQuery] = useState('');
    const [selectedPageObject, setSelectedPageObject] = useState(null);

    useEffect(() => {
        if (customerId) {
            const timer = setTimeout(() => {
                fetchPages(query);
            }, 300);
            return () => clearTimeout(timer);
        }
    }, [customerId, query]);

    const fetchPages = async (searchQuery) => {
        setLoading(true);
        try {
            const response = await axios.get(route('api.customers.pages.index', { 
                customer: customerId,
                search: searchQuery
            }));
            setPages(response.data.data);
        } catch (error) {
            console.error("Failed to fetch product pages", error);
        } finally {
            setLoading(false);
        }
    };

    const handleSelection = (page) => {
        setSelectedPageObject(page);
        onSelectionChange(page ? [page.id] : []);
    };

    return (
        <div className="space-y-1">
            <InputLabel value="Campaign Destination (Landing Page)" />
            <Combobox value={selectedPageObject} onChange={handleSelection}>
                <div className="relative mt-1">
                    <div className="relative w-full cursor-default overflow-hidden rounded-md bg-white text-left shadow-sm focus:outline-none sm:text-sm">
                        <ComboboxInput
                            className="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm py-2 pl-3 pr-10 text-sm leading-5 text-gray-900"
                            displayValue={(page) => page?.title || ''}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search for a product page..."
                        />
                        <ComboboxButton className="absolute inset-y-0 right-0 flex items-center pr-2">
                            <svg className="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fillRule="evenodd" d="M10 3a1 1 0 01.707.293l3 3a1 1 0 01-1.414 1.414L10 5.414 7.707 7.707a1 1 0 01-1.414-1.414l3-3A1 1 0 0110 3zm-3.707 9.293a1 1 0 011.414 0L10 14.586l2.293-2.293a1 1 0 011.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clipRule="evenodd" />
                            </svg>
                        </ComboboxButton>
                    </div>
                    <ComboboxOptions className="absolute z-50 mt-1 max-h-60 w-full overflow-auto rounded-md bg-white py-1 text-base shadow-lg ring-1 ring-black/5 focus:outline-none sm:text-sm">
                        {loading ? (
                            <div className="relative cursor-default select-none px-4 py-2 text-gray-700">Loading...</div>
                        ) : pages.length === 0 ? (
                            <div className="relative cursor-default select-none px-4 py-2 text-gray-700">
                                {query !== '' ? 'Nothing found.' : 'No pages available.'}
                            </div>
                        ) : (
                            pages.map((page) => (
                                <ComboboxOption
                                    key={page.id}
                                    className={({ active }) =>
                                        `relative cursor-default select-none py-2 pl-10 pr-4 ${
                                            active ? 'bg-indigo-600 text-white' : 'text-gray-900'
                                        }`
                                    }
                                    value={page}
                                >
                                    {({ selected, active }) => (
                                        <>
                                            <span className={`block truncate ${selected ? 'font-medium' : 'font-normal'}`}>
                                                {page.title || 'Untitled Page'}
                                            </span>
                                            {selected ? (
                                                <span className={`absolute inset-y-0 left-0 flex items-center pl-3 ${active ? 'text-white' : 'text-indigo-600'}`}>
                                                    <svg className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                                                    </svg>
                                                </span>
                                            ) : null}
                                            <span className={`block truncate text-xs ${active ? 'text-indigo-200' : 'text-gray-500'}`}>
                                                {page.url}
                                            </span>
                                        </>
                                    )}
                                </ComboboxOption>
                            ))
                        )}
                    </ComboboxOptions>
                </div>
            </Combobox>
            {selectedPageObject && (
                <div className="text-xs text-gray-500">
                    Selected: {selectedPageObject.url}
                </div>
            )}
        </div>
    );
}
