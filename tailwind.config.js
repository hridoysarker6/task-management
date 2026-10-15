import forms from '@tailwindcss/forms';
import colors from 'tailwindcss/colors';
import defaultTheme from 'tailwindcss/defaultTheme';

/**
 * Task Pro design tokens. Every existing `gray-*` class now reads as a cool slate and every `indigo-*` class as the
 * Task Pro violet, so the whole app picks the new look up from here without each page being touched.
 *
 * @type {import('tailwindcss').Config}
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                gray: colors.slate,
                indigo: colors.violet,
                brand: colors.violet,
            },
            borderRadius: {
                lg: '0.625rem',
                xl: '0.875rem',
                '2xl': '1.125rem',
            },
            boxShadow: {
                sm: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.04)',
                DEFAULT: '0 1px 3px 0 rgb(15 23 42 / 0.06), 0 2px 6px -1px rgb(15 23 42 / 0.05)',
                md: '0 4px 12px -2px rgb(15 23 42 / 0.08), 0 2px 6px -2px rgb(15 23 42 / 0.05)',
                lg: '0 12px 28px -6px rgb(15 23 42 / 0.14), 0 4px 10px -4px rgb(15 23 42 / 0.06)',
                xl: '0 24px 48px -12px rgb(15 23 42 / 0.2)',
                glow: '0 8px 24px -6px rgb(124 58 237 / 0.45)',
            },
            keyframes: {
                'fade-up': { '0%': { opacity: '0', transform: 'translateY(8px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                'fade-in': { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                'pop-in': { '0%': { opacity: '0', transform: 'scale(0.96)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
                'slide-in': { '0%': { opacity: '0', transform: 'translateX(16px)' }, '100%': { opacity: '1', transform: 'translateX(0)' } },
                shrink: { '0%': { width: '100%' }, '100%': { width: '0%' } },
            },
            animation: {
                'fade-up': 'fade-up 0.35s cubic-bezier(0.22, 1, 0.36, 1) both',
                'fade-in': 'fade-in 0.25s ease-out both',
                'pop-in': 'pop-in 0.18s cubic-bezier(0.22, 1, 0.36, 1) both',
                'slide-in': 'slide-in 0.3s cubic-bezier(0.22, 1, 0.36, 1) both',
                shrink: 'shrink 5s linear forwards',
            },
        },
    },

    plugins: [forms],
};
