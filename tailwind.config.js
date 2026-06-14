import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    safelist: [
        'bg-slate-900',
        'bg-slate-800',
        'text-slate-300',
        'text-slate-400',
        'bg-indigo-600',
        'bg-violet-700',
        'from-indigo-600',
        'to-violet-700',
        'bg-gradient-to-br',
        'lg:pl-64',
        'lg:pl-[4.5rem]',
        'w-64',
        'w-[4.5rem]',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
