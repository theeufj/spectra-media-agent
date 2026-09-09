export default function DangerButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                // hover darkens rather than lightens: white on red-500 is 3.76:1,
                // so the old hover:bg-red-500 dropped this 12px uppercase label
                // below AA at the exact moment the pointer was on it. red-600 is
                // 4.83:1 at rest and red-700 6.47:1 on hover/active.
                'inline-flex items-center rounded-md border border-transparent bg-red-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 active:bg-red-700 ' +
                // opacity-25 put the white label at 1.6:1 on the red fill, so a
                // destructive button mid-request looked empty. Matches
                // PrimaryButton/SecondaryButton: one disabled state, 5.13:1.
                'disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600 ' +
                className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
