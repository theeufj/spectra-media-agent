import { inputErrorId } from '@/Components/InputError';

export default function Checkbox({ className = '', error = null, ...props }) {
    const hasError = Boolean(error);

    // Same contract as TextInput: pass `error` and the box is marked invalid and
    // pointed at its own <InputError message={...} inputId={...} />.
    const describedBy =
        [props['aria-describedby'], hasError ? inputErrorId(props.id) : null]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <input
            {...props}
            type="checkbox"
            aria-describedby={describedBy}
            aria-invalid={
                props['aria-invalid'] !== undefined
                    ? props['aria-invalid']
                    : hasError || undefined
            }
            aria-required={
                props['aria-required'] !== undefined
                    ? props['aria-required']
                    : props.required || undefined
            }
            className={
                'rounded text-brand-dark shadow-sm ' +
                (hasError
                    ? // An unticked "I agree" box with only red text beside it
                      // reads as decoration; the red box is the affordance.
                      'border-red-500 focus:ring-red-600 '
                    : 'border-gray-300 focus:ring-brand-primary ') +
                className
            }
        />
    );
}
