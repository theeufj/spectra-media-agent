import { useEffect } from 'react';
import { useTenant } from '@/hooks/useTenant';

export default function TenantTheme() {
    const tenant = useTenant();

    useEffect(() => {
        const root = document.documentElement;
        const colors = tenant.colors ?? {};
        root.style.setProperty('--color-brand-primary', colors.primary ?? '#ff4d00');
        root.style.setProperty('--color-brand-dark', colors.dark ?? '#cc3d00');
        root.style.setProperty('--color-brand-darker', colors.darker ?? '#992e00');
        root.style.setProperty('--color-brand-accent', colors.accent ?? '#ffc300');
    }, [tenant]);

    return null;
}
