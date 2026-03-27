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
                'mint-cream': '#f7fff7',
                'jet': '#343434',
                'delft-blue': '#2f3061',
                'naples-yellow': '#ffe66d',
                'air-superiority-blue': '#6ca6c1',
                'flame-orange': {
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
                'lime-moss': {
                    50: '#fbfee6',
                    100: '#f6fecd',
                    200: '#eefc9c',
                    300: '#e5fb6a',
                    400: '#ddfa38',
                    500: '#d4f906',
                    600: '#aac705',
                    700: '#7f9504',
                    800: '#556303',
                    900: '#2a3201',
                    950: '#1e2301',
                },
                'amber-gold': {
                    50: '#fff9e5',
                    100: '#fff3cc',
                    200: '#ffe799',
                    300: '#ffdb66',
                    400: '#ffcf33',
                    500: '#ffc300',
                    600: '#cc9c00',
                    700: '#997500',
                    800: '#664e00',
                    900: '#332700',
                    950: '#241b00',
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
