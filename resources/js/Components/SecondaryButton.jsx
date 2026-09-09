export default function SecondaryButton({
    type = 'button',
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            type={type}
            className={
                'inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-xs font-semibold uppercase tracking-widest text-gray-700 shadow-sm transition duration-150 ease-in-out hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 ' +
                // Was opacity-25, which took gray-700 on white down to 1.6:1 — the
                // label vanished and nothing said the button was inert. Same greyed
                // treatment as PrimaryButton/DangerButton so the three read as one
                // disabled state; gray-600 on gray-300 is 5.13:1.
                'disabled:cursor-not-allowed disabled:border-gray-300 disabled:bg-gray-300 disabled:text-gray-600 ' +
                className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
