import type { Config } from 'tailwindcss';
import defaultTheme from 'tailwindcss/defaultTheme';

const config: Config = {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.ts',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            boxShadow: {
                'equal-sm': '0 0 4px rgba(0, 0, 0, 0.1)',
                equal: '0 0 8px rgba(0, 0, 0, 0.15)',
                'equal-md': '0 0 12px rgba(0, 0, 0, 0.2)',
                'equal-lg': '0 0 16px rgba(0, 0, 0, 0.25)',
                'equal-xl': '0 0 24px rgba(0, 0, 0, 0.3)',
            },
            animation: {
                pulse: 'pulse 2s infinite',
                'pulse-bg': 'pulse-bg 1.5s cubic-bezier(0.4, 0, 0.6, 1) infinite',
            },
            keyframes: {
                bounce: {
                    '0%, 100%': {
                        transform: 'translateY(-50%)',
                    },
                    '50%': {
                        transform: 'translateY(0)',
                    },
                },
                pulse: {
                    '0%, 100%': { opacity: '1' },
                    '50%': { opacity: '0.5' },
                },
            },
        },
    },
    darkMode: 'class',
    plugins: [],
};

export default config;