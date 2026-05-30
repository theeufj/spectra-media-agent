import { usePage } from '@inertiajs/react';

export function useTenant() {
    return usePage().props.tenant ?? {
        key: 'sitetospend',
        name: 'Site to Spend',
        tagline: 'Your AI Marketing Team',
        vertical: null,
        locked_vertical: false,
        colors: {
            primary: '#ff4d00',
            dark: '#cc3d00',
            darker: '#992e00',
            accent: '#ffc300',
        },
        logo_text: 'sitetospend',
        logo_url: null,
    };
}
