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
            },
        },
    },

    plugins: [forms],
};
