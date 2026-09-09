export default function InputLabel({
    value,
    className = '',
    children,
    required = false,
    ...props
}) {
    return (
        <label
            {...props}
            className={
                `block text-sm font-medium text-gray-700 ` +
                className
            }
        >
            {value ? value : children}
            {required && (
                <>
                    {/* The asterisk is decoration — an asterisk alone tells a
                        screen-reader user nothing, so the word goes in too. The
                        field itself carries `aria-required`. */}
                    <span aria-hidden="true" className="ml-0.5 text-red-600">
                        *
                    </span>
                    <span className="sr-only"> (required)</span>
                </>
            )}
        </label>
    );
}
