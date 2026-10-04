import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { readdirSync } from 'node:fs';

/*
 * The font library (Lane FS, App\Support\FontLibrary): every woff2 under
 * resources/fonts/lib/, as INPUTS for the reason the Outfit note below gives —
 * `npx vite build` empties public/build, so a font copied there by hand
 * survives exactly until the next build. Read from the directory rather than
 * listed, so a family added by tools/fs-fetch-fonts.py is built without a
 * second list to forget; FontLibraryTest holds the table to the manifest.
 */
const fontLibrary = readdirSync('resources/fonts/lib', { recursive: true })
    .filter((f) => String(f).endsWith('.woff2'))
    .sort()
    .map((f) => 'resources/fonts/lib/' + String(f).split('\\').join('/'));

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
                // The old shop's category title header (Lane PT), loaded only
                // by a category or brand page that draws one.
                'resources/css/kbb/kbb-title-header.css',
                'resources/css/kbb/kbb-product.css',
                'resources/css/kbb/kbb-cart.css',
                'resources/css/kbb/kbb-checkout.css',
                'resources/css/kbb/kbb-account.css',
                // Ships with the reviews port — the plugin's own stylesheet.
                'resources/css/kbb/sorina-reviews.css',
                // 28 product-grid card templates, selectable from the admin.
                'resources/css/kbb/kbb-grid-skins.css',
                // Lane PW: the product page's delivery box, authenticity
                // line and share bar. Loaded only on a page that draws one
                // of them — see partials/product/trust-share-assets.
                'resources/css/kbb/kbb-pdp-trust.css',
                'resources/js/kbb/pdp-trust.js',
                /*
                 * OUTFIT, SELF-HOSTED — Lane PERF's arrangement, Lane PLC's
                 * typeface. The owner asked for Outfit site-wide; it is served
                 * from this origin exactly as Poppins was, so no third-party
                 * font host comes back.
                 *
                 * They are INPUTS and not files dropped into public/build,
                 * because `npx vite build` empties that directory: a woff2
                 * copied there by hand survives exactly until the next asset
                 * build, and the shop is then serving a 404 for its own brand
                 * face. As inputs they are hashed, listed in the manifest and
                 * resolved by App\Support\WebFonts through Vite::asset().
                 *
                 * TWO FILES, NOT FIFTEEN. Outfit is a variable font: css2
                 * returns ten @font-face rules and one file per subset, every
                 * weight. Poppins needed five files per subset and shipped a
                 * devanagari subset besides; Outfit publishes none, and this
                 * shop has no Devanagari text for it to carry — see WebFonts.
                 */
                'resources/fonts/outfit/outfit-latin.woff2',
                'resources/fonts/outfit/outfit-latin-ext.woff2',
                'resources/fonts/cairo/cairo-arabic.woff2',
                'resources/fonts/cairo/cairo-latin.woff2',
                'resources/fonts/cairo/cairo-latin-ext.woff2',
                'resources/js/kbb/app.js',
                ...fontLibrary,
            ],
            refresh: true,
        }),
    ],
});
