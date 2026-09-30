import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

/**
 * Assets are built off-server and the compiled output uploaded, because shared
 * hosting has no Node. Vite is a build-time tool only — nothing here runs in
 * production. (D-44)
 *
 * Page stylesheets are separate entries rather than one bundle, matching the
 * theme's conditional loading: a shopper on the home page never downloads the
 * checkout's 40KB of CSS.
 */
export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/kbb/kbb.css',
                'resources/css/kbb/kbb-shop.css',
                // The category/brand page banner. Its own entry because two
                // pages that share no other stylesheet both draw it: the
                // archive (kbb-shop.css) and the brand landing page (which
                // has its own inline block and loads none of the shop's).
                'resources/css/kbb/kbb-banner.css',
                'resources/css/kbb/kbb-product.css',
                'resources/css/kbb/kbb-cart.css',
                'resources/css/kbb/kbb-checkout.css',
                'resources/css/kbb/kbb-account.css',
                // Ships with the reviews port — the plugin's own stylesheet.
                'resources/css/kbb/sorina-reviews.css',
                // 28 product-grid card templates, selectable from the admin.
                'resources/css/kbb/kbb-grid-skins.css',
                /*
                 * POPPINS, SELF-HOSTED — Lane PERF.
                 *
                 * They are INPUTS and not files dropped into public/build,
                 * because `npx vite build` empties that directory: a woff2
                 * copied there by hand survives exactly until the next asset
                 * build, and the shop is then serving a 404 for its own brand
                 * face. As inputs they are hashed, listed in the manifest and
                 * resolved by App\Support\WebFonts through Vite::asset().
                 *
                 * All FIFTEEN Poppins faces css2 returns, devanagari included
                 * -- 500 joined them when Lane BG found that eight rules in
                 * kbb.css ask for it and the browser was answering with 400 --
                 * and Cairo's three (its four weights are one variable file
                 * per subset). A
                 * browser fetches a face only when a codepoint in its
                 * unicode-range is on the page, so the four this shop has no
                 * text for cost a shopper nothing and are what makes
                 * "the same font, byte for byte" true without an exception.
                 */
                'resources/fonts/poppins/poppins-devanagari-400.woff2',
                'resources/fonts/poppins/poppins-latin-400.woff2',
                'resources/fonts/poppins/poppins-latin-ext-400.woff2',
                'resources/fonts/poppins/poppins-devanagari-500.woff2',
                'resources/fonts/poppins/poppins-latin-500.woff2',
                'resources/fonts/poppins/poppins-latin-ext-500.woff2',
                'resources/fonts/poppins/poppins-devanagari-600.woff2',
                'resources/fonts/poppins/poppins-latin-600.woff2',
                'resources/fonts/poppins/poppins-latin-ext-600.woff2',
                'resources/fonts/poppins/poppins-devanagari-700.woff2',
                'resources/fonts/poppins/poppins-latin-700.woff2',
                'resources/fonts/poppins/poppins-latin-ext-700.woff2',
                'resources/fonts/poppins/poppins-devanagari-800.woff2',
                'resources/fonts/poppins/poppins-latin-800.woff2',
                'resources/fonts/poppins/poppins-latin-ext-800.woff2',
                'resources/fonts/cairo/cairo-arabic.woff2',
                'resources/fonts/cairo/cairo-latin.woff2',
                'resources/fonts/cairo/cairo-latin-ext.woff2',
                'resources/js/kbb/app.js',
            ],
            refresh: true,
        }),
    ],
});
