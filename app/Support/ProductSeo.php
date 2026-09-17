<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The shape of `products.seo`, in one place.
 *
 * WHY THIS IS A CLASS AND NOT A LITERAL IN TWO FILES.
 *
 * Per-product SEO was broken in two independent ways at once, and the second
 * one is the reason this file exists. The first was the column: the admin wrote
 * `seo_json` and every reader in the application reads `seo`. The second was
 * the key names — the admin's panel collected Yoast-shaped names and the
 * storefront reads different ones:
 *
 *     admin sent            storefront reads
 *     -----------           ----------------
 *     seo_title             title
 *     meta_description      desc
 *
 * So even after the column was corrected, a save would still have published
 * nothing: Store\ProductController tests `!empty($override['title'])` and there
 * would have been no `title` key to find. Two places now have to agree about
 * that rename — the write path in Admin\AdminController and the data migration
 * that rescues what operators already typed — and two copies of a mapping is
 * how a rename half-happens. They both call this.
 *
 * THE PUBLISHED VOCABULARY. These are the keys the storefront actually acts on,
 * read out of Store\ProductController::show() and cross-checked against
 * Admin\SchemaInspectorApiController and Admin\CatalogueAuditApiController,
 * which read the same blob:
 *
 *     title      overrides the whole <title>, verbatim, no template applied
 *     desc       <meta name="description"> and the og/schema description
 *     og_image   the share image, falling back to the product's main image
 *     canonical  an absolute URL that replaces the computed canonical
 *     noindex    truthy adds <meta name="robots" content="noindex">
 *
 * Anything else in the array is carried through untouched rather than dropped.
 * The admin panel also collects a focus keyphrase and og_title/og_description
 * that nothing publishes yet; they are the operator's work, they cost a few
 * bytes in a json column, and a normaliser that silently deletes fields the
 * screen is still showing is a worse bug than an unused key.
 */
final class ProductSeo
{
    /** Legacy key => the key the storefront reads. */
    public const RENAME = [
        'seo_title' => 'title',
        'meta_description' => 'desc',
        // Two more spellings that have appeared in payloads from the older
        // panel. Harmless to map, and each is a silent no-op if absent.
        'meta_title' => 'title',
        'description' => 'desc',
    ];

    /** The keys the storefront reads. Documentation, and the editor's form. */
    public const PUBLISHED_KEYS = ['title', 'desc', 'og_image', 'canonical', 'noindex'];

    /**
     * The description the storefront will FEED to the SEO engine for this
     * product, before the engine's own fallbacks — or null when it has none.
     *
     * This is the chain Store\ProductController::show() has always used, moved
     * here so that exactly one place knows it. It used to be an inline
     * expression in that controller and nowhere else, which is why the admin's
     * snippet preview could not consult it and invented a sentence instead.
     *
     * AN EMPTY `short_description` IS NOT NULL, AND THE DIFFERENCE IS VISIBLE.
     * `??` falls through on null only, so a product whose short description is
     * the empty string — which is exactly what the product editor writes when
     * the operator clears that box — feeds '' to the engine, and App\Support\Seo
     * emits NO description tag at all rather than falling back to
     * `seo_default_description`. That is today's behaviour, preserved here
     * verbatim rather than quietly corrected: it is a real defect, it belongs
     * to the editor's write path as much as to this read path, and a lane that
     * changed it in passing would move every such product's search snippet
     * without anybody deciding to. It is reported, not patched.
     *
     * @param  bool  $ignoreOverride  answer as though the per-product SEO
     *   description box were empty — what the admin's snippet preview needs in
     *   order to show the operator what happens if they clear it.
     */
    public static function rawDescription(\App\Models\Product $product, bool $ignoreOverride = false): ?string
    {
        $override = is_array($product->seo) ? $product->seo : [];

        if (! $ignoreOverride && isset($override['desc'])) {
            return $override['desc'];
        }

        return $product->short_description ?? null;
    }

    /**
     * The `<meta name="description">` this product's page will actually publish.
     *
     * Resolved through App\Support\Seo::describe(), which is the same method
     * the page itself uses — so this cannot drift from the page by
     * construction. '' means the page publishes no description tag.
     *
     * The title is passed because it is a TOKEN: a description containing
     * `{title}` must resolve to the same words in the preview as in the page.
     */
    public static function metaDescription(\App\Models\Product $product, bool $ignoreOverride = false): string
    {
        $override = is_array($product->seo) ? $product->seo : [];

        return \App\Support\Seo::describe([
            'type' => 'product',
            'title' => empty($override['title'])
                ? ProductTitle::head($product->brand?->name, $product->name)
                : (string) $override['title'],
            'description' => self::rawDescription($product, $ignoreOverride),
        ]);
    }

    /**
     * A payload from any of the editors into the stored shape.
     *
     * Returns null rather than an empty array when nothing survives, so that
     * `is_array($product->seo)` — the test every reader uses — is false for a
     * product with no overrides instead of true-but-empty.
     */
    public static function normalise(mixed $seo): ?array
    {
        if (! is_array($seo)) {
            return null;
        }

        $out = [];

        foreach ($seo as $key => $value) {
            $key = self::RENAME[$key] ?? $key;

            if ($key === 'noindex') {
                // A checkbox arrives as true/false, "1"/"0", "on", or absent.
                // Stored as a real bool so `!empty($override['noindex'])` on
                // the storefront cannot be fooled by the string "false", which
                // is truthy in PHP and would silently deindex the page.
                $out['noindex'] = filter_var($value, FILTER_VALIDATE_BOOL);

                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
            }

            $out[$key] = $value;
        }

        // `noindex: false` is the default, not a value worth storing.
        if (array_key_exists('noindex', $out) && $out['noindex'] === false) {
            unset($out['noindex']);
        }

        $out = array_filter(
            $out,
            static fn ($v) => $v !== null && $v !== '' && $v !== []
        );

        return $out === [] ? null : $out;
    }
}
