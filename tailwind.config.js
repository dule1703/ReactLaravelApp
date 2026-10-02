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
                sans: ['"Inter Variable"', ...defaultTheme.fontFamily.sans],
            },
            // Skoda-inspired design tokens. Change the palette here only.
            colors: {
                brand: {
                    DEFAULT: '#4BA82E',
                    dark: '#0E3A2F',
                    50: '#F1F9EE',
                    100: '#DDF0D6',
                    200: '#BCE1AE',
                    300: '#94CE80',
                    400: '#6DBB52',
                    500: '#4BA82E',
                    600: '#3B8624',
                    700: '#2F6A1D',
                    800: '#26531A',
                    900: '#1F4418',
                },
                ink: '#1B1F1D',
                surface: '#F4F6F5',
                danger: '#C62828',
            },
        },
    },

    plugins: [forms],
};
