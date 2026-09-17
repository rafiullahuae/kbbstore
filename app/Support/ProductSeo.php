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
     * AN EMPTY `short_description` IS ABSENT, NOT PRESENT-AND-BLANK — FIXED
     * HERE (Lane EM, 2.60.199), on the read path rather than the write path.
     *
     * It used to be preserved verbatim and reported: `??` falls through on null
     * only, so a product whose short description was the empty string — exactly
     * what the product editor writes when the operator clears that box — fed ''
     * to the engine, and App\Support\Seo::describe()'s `?? ... ?? ''` chain
     * stopped dead on it. The page emitted NO `<meta name="description">` at
     * all instead of falling back to `seo_default_description`, so clearing a
     * box in the admin silently deleted that product's Google snippet with
     * nothing on any screen saying so.
     *
     * WHY THE READ PATH AND NOT THE EDITOR'S WRITE PATH. Normalising '' to null
     * on write fixes the next save and leaves every product already carrying ''
     * broken until somebody re-saves it, so it would need a data migration over
     * `products` to finish the job — and it would still leave this method
     * answering "yes, there is a description, it is empty" to anything that
     * asked. The question this method exists to answer is "does this product
     * supply a description?", and '' is not a description. Fixing it here fixes
     * every affected product on the next request, needs no migration, and
     * cannot drift from the page, because the page and the admin's snippet
     * preview both reach the engine through this one method.
     *
     * EMPTINESS IS MEASURED THE WAY describe() MEASURES IT — strip_tags, then
     * collapse whitespace, then trim. A WooCommerce import's `<p></p>` or a
     * lone `&nbsp;` is a non-empty string that describe() reduces to '' anyway,
     * so testing the raw string would have fixed the cleared box and left the
     * imported empty paragraph still deleting snippets. The two now agree by
     * construction: if there is nothing the engine could print, this says so.
     *
     * NOTHING SUPPRESSES A DESCRIPTION DELIBERATELY TODAY, which is why this is
     * safe to read as an accident rather than an intention. normalise() filters
     * '' out of the stored `seo` array, so a cleared per-product SEO
     * description box is never persisted as '' in the first place, and there is
     * no control anywhere that means "publish no description for this product".
     * If one is ever wanted it needs its own explicit flag, not an empty string
     * that four other things read as a typo.
     *
     * @param  bool  $ignoreOverride  answer as though the per-product SEO
     *   description box were empty — what the admin's snippet preview needs in
     *   order to show the operator what happens if they clear it.
     */
    public static function rawDescription(\App\Models\Product $product, bool $ignoreOverride = false): ?string
    {
        $override = is_array($product->seo) ? $product->seo : [];

        if (! $ignoreOverride && self::hasText($override['desc'] ?? null)) {
            return $override['desc'];
        }

        return self::hasText($product->short_description) ? $product->short_description : null;
    }

    /**
     * Would the SEO engine get any words out of this?
     *
     * The same reduction App\Support\Seo::describe() applies before it decides
     * whether to emit a tag, asked as a question. Kept beside rawDescription()
     * because the two have to agree: anything this calls empty is something
     * describe() would have rendered as '' and published as no tag at all.
     */
    private static function hasText(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($value))) !== '';
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
     * The `<title>` this product's page will actually publish.
     *
     * The title's counterpart to metaDescription(), and it exists for the same
     * reason: the editor's preview was drawing the operator's own box rather
     * than the tag, and the two are not the same string. Resolved through
     * App\Support\Seo::titleFor(), which is the method render() itself uses,
     * so it cannot drift from the page.
     *
     * WHAT THE OPERATOR'S BOX ACTUALLY DOES. Filled, it becomes the WHOLE title
     * verbatim — Store\ProductController::show() sets `title_is_final`, so the
     * site name is NOT appended and only `%%`-tokens are resolved. Empty, the
     * head falls to ProductTitle::head() (brand + name) and THAT goes through
     * `seo_title_template`, which on this store appends " | K-Beauty Bliss".
     *
     * So the two cases produce visibly different lengths from the same typing,
     * which is exactly why a preview that shows the box cannot be trusted and a
     * character counter run against the box is wrong in the empty case by the
     * whole length of the site name.
     *
     * @param  bool  $ignoreOverride  answer as though the per-product SEO title
     *   box were empty — what the preview needs to show the operator what
     *   happens if they clear it.
     */
    public static function metaTitle(\App\Models\Product $product, bool $ignoreOverride = false): string
    {
        $override = is_array($product->seo) ? $product->seo : [];
        $custom = $ignoreOverride ? '' : trim((string) ($override['title'] ?? ''));

        return \App\Support\Seo::titleFor([
            'type' => 'product',
            'title' => $custom !== ''
                ? $custom
                : ProductTitle::head($product->brand?->name, $product->name),
            // Set only when there IS a custom title, exactly as
            // Store\ProductController::show() sets it.
            'title_is_final' => $custom !== '',
        ]);
    }

    /**
     * One editor screen's SEO boxes, merged onto what the column already holds.
     *
     * WHY THIS IS NOT `array_filter([...the boxes...])`, WHICH IS WHAT IT
     * REPLACED. Admin\BrandsApiController and Admin\CategoriesApiController
     * both ended their payload builder by REBUILDING `seo` out of the two boxes
     * their screen draws:
     *
     *     $seo = array_filter([
     *         'title'       => trim(... ['seo']['title'] ...),
     *         'description' => trim(... ['seo']['description'] ...),
     *     ], fn ($v) => $v !== '');
     *     $data['seo'] = $seo === [] ? null : $seo;
     *
     * Every other key in the column was dropped on the floor by that — not left
     * alone, DELETED. And the column is not a two-key column: it is read
     * through normalise() by Store\BrandController::seoCtx() and
     * Store\ShopController on the full published vocabulary, and
     * Store\SeoFilesController::isNoindex() reads `noindex` out of both tables
     * to decide whether the sitemap may advertise the URL at all.
     *
     * So a `noindex` — set by the Yoast importer, by a migration, or by hand —
     * survived precisely until the next time somebody opened that brand in the
     * admin and pressed Save. The page silently became indexable again and the
     * sitemap silently began advertising it, with nothing on any screen saying
     * so, because the screen did not know the key existed. That is the same
     * write-path/read-path vocabulary split this class's header was written
     * for, committed a second time against two different tables.
     *
     * THE RULE, AND IT IS THE WHOLE POINT: a key is authoritative when the
     * request ACTUALLY CARRIES IT, and then it is authoritative even blank —
     * blank means the operator cleared the box and the key goes. A key the
     * request does not mention is none of its business and is carried through
     * untouched. Preservation must not become "nothing can ever be removed",
     * which is why a sent-but-empty value still deletes.
     *
     * PRESENCE, NOT THE $draws LIST, IS WHAT DECIDES THAT — and the difference
     * is not academic. Keying it off "everything this screen draws" means any
     * client that posts a SHORTER body than the current screen silently deletes
     * the difference: a cached copy of yesterday's admin JavaScript, a script,
     * the reorder endpoint, a future screen that splits this form in two. That
     * is the same "wrote nothing about it, therefore destroy it" mistake this
     * method exists to undo, merely moved one level up. A request that never
     * mentions `noindex` is not a request to start indexing the page.
     *
     * $draws REMAINS, as an ALLOWLIST rather than as the authority: only a key
     * this screen is known to render may be written through here at all, so a
     * hand-crafted payload cannot stuff arbitrary keys into a json column that
     * five readers pull apart. Both halves are needed — the allowlist says what
     * MAY be written, presence says what IS being written.
     *
     * A BODY WITH NO `seo` KEY AT ALL leaves the column entirely alone. The
     * brands screen's own source comments that a body without `seo` "would wipe
     * the SEO overrides"; now it does not, so a partial save from a script, a
     * future screen, or a reorder endpoint cannot cost the owner their
     * overrides.
     *
     * @param  mixed  $stored  the column as it is now
     * @param  mixed  $sent    the `seo` bag out of the validated request, if any
     * @param  list<string>  $draws  the keys this screen actually renders
     * @return array<string, mixed>|null
     */
    public static function mergeFromForm(mixed $stored, mixed $sent, array $draws): ?array
    {
        $merged = is_array($stored) ? $stored : [];

        // No `seo` in the body: this request is not speaking about SEO.
        if (! is_array($sent)) {
            return self::normalise($merged, rename: false);
        }

        foreach ($draws as $key) {
            // Not mentioned by this request, so not this request's business.
            if (! array_key_exists($key, $sent)) {
                continue;
            }

            if ($key === 'noindex') {
                // A checkbox is not posted when it is unchecked, so the SCREEN
                // sends `noindex: false` explicitly rather than omitting it —
                // otherwise there would be no way to turn one off. normalise()
                // then drops the false, which is how the key disappears.
                $merged['noindex'] = filter_var($sent['noindex'], FILTER_VALIDATE_BOOL);

                continue;
            }

            $merged[$key] = trim((string) $sent[$key]);
        }

        // rename: false — these two tables store `description`, not `desc`, and
        // their readers already normalise on the way out. Renaming on write
        // would leave the tables holding both spellings at once.
        return self::normalise($merged, rename: false);
    }

    /**
     * A payload from any of the editors into the stored shape.
     *
     * Returns null rather than an empty array when nothing survives, so that
     * `is_array($product->seo)` — the test every reader uses — is false for a
     * product with no overrides instead of true-but-empty.
     */
    /**
     * @param  bool  $rename  apply RENAME. True for a payload arriving from an
     *   editor or an import, which is every caller that existed when this was
     *   written. FALSE for a bag that is already in the stored vocabulary and
     *   is only being tidied — see mergeFromForm(), which needs the trimming,
     *   the real-bool `noindex` and the blank-dropping WITHOUT turning the
     *   `description` that `brands.seo` and `categories.seo` are full of into a
     *   `desc`. Renaming there would leave those two tables carrying both
     *   spellings at once, and a row holding `description` AND `desc` resolves
     *   to whichever this loop reaches last.
     */
    public static function normalise(mixed $seo, bool $rename = true): ?array
    {
        if (! is_array($seo)) {
            return null;
        }

        $out = [];

        foreach ($seo as $key => $value) {
            $key = $rename ? (self::RENAME[$key] ?? $key) : $key;

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
