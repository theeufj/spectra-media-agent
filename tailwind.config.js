import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                'brand': {
                    'primary': 'var(--color-brand-primary)',
                    'dark':    'var(--color-brand-dark)',
                    'darker':  'var(--color-brand-darker)',
                    'accent':  'var(--color-brand-accent)',
                },
                'mint-cream': '#f7fff7',
                'jet': '#343434',
                // Declared as flat hex strings, these emitted nothing for any
                // scale-suffixed class: 29 of them were written anyway, so the
                // Dashboard's Spending Projections panel rendered with no tinted
                // background, no border, and label and value in the same colour.
                // DEFAULT keeps the ~100 bare `text-delft-blue` uses working.
                'delft-blue': {
                    DEFAULT: '#2f3061',
                    50:  '#f0f0f5',
                    100: '#dcdce8',
                    200: '#b9b9d1',
                    300: '#9596b9',
                    400: '#65668f',
                    500: '#4a4b7d',
                    600: '#2f3061',
                    700: '#26274e',
                    800: '#1c1d3a',
                    900: '#131327',
                },
                'air-superiority-blue': {
                    DEFAULT: '#6ca6c1',
                    50:  '#f0f6f9',
                    100: '#dbeaf1',
                    200: '#b7d5e3',
                    300: '#93c0d5',
                    400: '#6ca6c1',
                    500: '#4a8ba9',
                    600: '#3a6f87',
                    700: '#2b5365',
                    800: '#1d3743',
                    900: '#0e1c22',
                },
                'naples-yellow': '#ffe66d',                'flame-orange': {
                    50: '#ffede5',
                    100: '#ffdbcc',
                    200: '#ffb899',
                    300: '#ff9466',
                    400: '#ff7033',
                    500: '#ff4d00',
                    600: '#cc3d00',
                    700: '#992e00',
                    800: '#661f00',
                    900: '#330f00',
                    950: '#240b00',
                },
                'golden-orange': {
                    50: '#fef6e6',
                    100: '#feeecd',
                    200: '#fddc9b',
                    300: '#fccb69',
                    400: '#fbba37',
                    500: '#faa805',
                    600: '#c88704',
                    700: '#966503',
                    800: '#644302',
                    900: '#322201',
                    950: '#231801',
                },
                'rusty-spice': {
                    50: '#ffece5',
                    100: '#ffd9cc',
                    200: '#ffb399',
                    300: '#ff8c66',
                    400: '#ff6633',
                    500: '#ff4000',
                    600: '#cc3300',
                    700: '#992600',
                    800: '#661a00',
                    900: '#330d00',
                    950: '#240900',
                },
            },
        },
    },

    plugins: [forms],
};
