export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}) {
    return (
        <button
            {...props}
            className={
                // Rest is brand-dark, not brand-primary. White on the sitetospend
                // primary (#ff4d00) is 3.33:1 — below the 4.5:1 AA floor for this
                // 12px uppercase label, on every primary action in the product.
                // brand-dark is 4.96:1 and brand-darker 7.65:1; the navy skin runs
                // 14.3:1 / 17.1:1, so the same two tokens hold on both tenants.
                'inline-flex items-center rounded-md border border-transparent bg-brand-dark px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-brand-darker focus:bg-brand-darker focus:outline-none focus:ring-2 focus:ring-brand-primary focus:ring-offset-2 active:bg-brand-darker ' +
                // The disabled state used to be opacity-25, which composited the
                // white label to 1.37:1 — the wizard's Continue button read as a
                // blank orange slab. A real greyed fill keeps the label at 5.13:1
                // while still reading inert, and the cursor says why nothing
                // happens on click. Tailwind emits `disabled:` after `hover:` and
                // `focus:`, so these win without an `enabled:` guard.
                'disabled:cursor-not-allowed disabled:bg-gray-300 disabled:text-gray-600 ' +
                className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
