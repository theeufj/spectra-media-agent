export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-gray-300 text-flame-orange-600 shadow-sm focus:ring-flame-orange-500 ' +
                className
            }
        />
    );
}
