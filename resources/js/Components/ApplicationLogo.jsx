import { useTenant } from '@/hooks/useTenant';

export default function ApplicationLogo({ className = '' }) {
    const tenant = useTenant();
    return (
        <span className={`text-3xl font-bold text-brand-primary ${className}`}>
            {tenant.logo_text}
        </span>
    );
}
