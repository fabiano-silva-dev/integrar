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
        'lg:pl-24',
        'w-24',
        'group/sidebar',
        'group-hover/sidebar:opacity-100',
        'group-focus-within/sidebar:opacity-100',
        'min-h-[2rem]',
        'text-[10px]',
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
