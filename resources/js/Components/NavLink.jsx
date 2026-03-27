import { Link } from '@inertiajs/react';

export default function NavLink({
    active = false,
    className = '',
    children,
    ...props
}) {
    return (
        <Link
            {...props}
            className={
                'inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md transition duration-150 ease-in-out focus:outline-none ' +
                (active
                    ? 'bg-gray-100 text-gray-900'
                    : 'text-gray-500 hover:bg-gray-50 hover:text-gray-700') +
                ' ' + className
            }
        >
            {children}
        </Link>
    );
}
