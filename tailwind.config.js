/**
 * Tailwind build config — penny-track
 *
 * WHY THIS FILE EXISTS (GUIDING-LIGHT §3.5):
 * The app used to load `https://cdn.tailwindcss.com`, which ships roughly 120KB
 * of JavaScript that compiles CSS *in the browser*. On a phone on a cellular
 * connection that was the single worst thing in this portfolio, and it is
 * documented by Tailwind themselves as not-for-production. The CDN is also a
 * supply-chain dependency: whoever controls that host controls script execution
 * on a page that holds the user's API key in localStorage.
 *
 * So Tailwind is PRECOMPILED here to `assets/styles/tailwind.css`, which is
 * committed and served from the app's own origin through AssetMapper. No build
 * step is needed at deploy time and there is no runtime CDN dependency.
 *
 * REGENERATING after changing a template:
 *
 *   npx tailwindcss@3 -c tailwind.config.js -i assets/styles/tailwind.input.css \
 *       -o assets/styles/tailwind.css --minify
 *
 * The `primary` palette below is deliberately identical to the one that used to
 * live in the inline `tailwind.config` <script> in base.html.twig. It is not a
 * Tailwind default, so dropping it would silently unstyled every primary-*
 * class in the templates.
 */

/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        './templates/**/*.html.twig',
        './assets/**/*.js',
        './src/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                primary: {
                    50: '#ecfdf5',
                    100: '#d1fae5',
                    200: '#a7f3d0',
                    300: '#6ee7b7',
                    400: '#34d399',
                    500: '#10b981',
                    600: '#059669',
                    700: '#047857',
                    800: '#065f46',
                    900: '#064e3b',
                },
            },
        },
    },
    plugins: [],
};
