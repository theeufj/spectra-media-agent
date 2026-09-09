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
                // The nav used to strip the browser outline and put nothing back,
                // so tabbing across the header left no visible focus at all. The
                // ring is brand-primary against the white nav — 3.33:1 on the
                // orange skin, 11.02:1 on the navy one, both clearing the 3:1
                // floor for a non-text indicator. focus-visible, not focus, so a
                // mouse click on a nav item does not leave a ring behind.
                'inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md transition duration-150 ease-in-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary focus-visible:ring-offset-2 ' +
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
