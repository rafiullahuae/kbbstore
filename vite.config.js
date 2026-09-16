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
                'resources/js/kbb/app.js',
            ],
            refresh: true,
        }),
    ],
});
