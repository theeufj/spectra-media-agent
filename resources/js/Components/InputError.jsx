/**
 * The id a field's error message is given, derived from the field's own id.
 * Both `InputError` and the field components use this, so the `id` on the
 * message and the `aria-describedby` on the input can never drift apart.
 */
export function inputErrorId(inputId) {
    return inputId ? `${inputId}-error` : undefined;
}

/**
 * Usage — the field and its message are tied together by the shared input id:
 *
 *   <InputLabel htmlFor="email" value="Email" required />
 *   <TextInput id="email" required error={errors.email} ... />
 *   <InputError inputId="email" message={errors.email} className="mt-2" />
 */
export default function InputError({
    message,
    inputId,
    id,
    className = '',
    ...props
}) {
    return message ? (
        <p
            {...props}
            id={id ?? inputErrorId(inputId)}
            // Announces on insert, so a screen-reader user learns the submit
            // failed instead of being left on a silently unchanged page.
            role="alert"
            className={
                'flex items-start gap-1.5 text-sm text-red-600 ' + className
            }
        >
            {/* Red text was the only signal that this line was an error. The
                icon carries it for anyone the colour channel does not reach. */}
            <svg
                className="mt-0.5 h-4 w-4 flex-shrink-0"
                viewBox="0 0 20 20"
                fill="currentColor"
                aria-hidden="true"
            >
                <path
                    fillRule="evenodd"
                    d="M18 10A8 8 0 11 2 10a8 8 0 0116 0zm-9-4a1 1 0 012 0v5a1 1 0 01-2 0V6zm1 9a1.25 1.25 0 100-2.5 1.25 1.25 0 000 2.5z"
                    clipRule="evenodd"
                />
            </svg>
            <span>{message}</span>
        </p>
    ) : null;
}
