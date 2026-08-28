export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-gray-300 text-brand-dark shadow-sm focus:ring-brand-primary ' +
                className
            }
        />
    );
}
