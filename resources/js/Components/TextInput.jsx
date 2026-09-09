import { inputErrorId } from '@/Components/InputError';
import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react';

export default forwardRef(function TextInput(
    { type = 'text', className = '', isFocused = false, error = null, ...props },
    ref,
) {
    const localRef = useRef(null);

    useImperativeHandle(ref, () => ({
        focus: () => localRef.current?.focus(),
    }));

    useEffect(() => {
        if (isFocused) {
            localRef.current?.focus();
        }
    }, [isFocused]);

    const hasError = Boolean(error);

    // Appended, not assigned: a field may already point at a hint, and losing
    // the hint to gain the error is not a trade worth making.
    const describedBy =
        [props['aria-describedby'], hasError ? inputErrorId(props.id) : null]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <input
            {...props}
            type={type}
            ref={localRef}
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
                'rounded-md shadow-sm ' +
                (hasError
                    ? // A red message below the field was the only mark of an
                      // invalid field. The border puts the mark on the thing the
                      // user has to go back and fix. red-500 is 3.76:1 on white
                      // and 3.60:1 on gray-50 — both clear the 3:1 that a UI
                      // boundary needs, on either skin (the colour is fixed).
                      'border-red-500 focus:border-red-600 focus:ring-red-600 '
                    : 'border-gray-300 focus:border-brand-primary focus:ring-brand-primary ') +
                className
            }
        />
    );
});
