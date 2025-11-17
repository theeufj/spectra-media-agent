import React from 'react';
import { usePage, router } from '@inertiajs/react';
import Dropdown from '@/Components/Dropdown';

export default function CustomerSwitcher() {
    const { auth } = usePage().props;
    const { user } = auth;
    const { customers, active_customer } = user;

    const handleSwitch = (customer) => {
        router.post(route('customers.switch', customer));
    };

    return (
        <div className="relative">
            <Dropdown>
                <Dropdown.Trigger>
                    <span className="inline-flex rounded-md">
                        <button
                            type="button"
                            className="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150"
                        >
                            {active_customer ? active_customer.name : 'Select Customer'}
                            <svg
                                className="ml-2 -mr-0.5 h-4 w-4"
                                xmlns="http://www.w3.org/2000/svg"
                                viewBox="0 0 20 20"
                                fill="currentColor"
                            >
                                <path
                                    fillRule="evenodd"
                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                    clipRule="evenodd"
                                />
                            </svg>
                        </button>
                    </span>
                </Dropdown.Trigger>

                <Dropdown.Content>
                    {customers.map(customer => (
                        <Dropdown.Link key={customer.id} onClick={() => handleSwitch(customer)}>
                            {customer.name}
                        </Dropdown.Link>
                    ))}
                </Dropdown.Content>
            </Dropdown>
        </div>
    );
}
