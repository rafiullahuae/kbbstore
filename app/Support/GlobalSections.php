<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Block;
use Illuminate\Database\QueryException;

/**
 * `[rey_global_section id="18159"]` in a product's copy, drawn as the block it
 * names. (Lane PJ-B)
 *
 * ── WHAT THE OWNER SAW ──────────────────────────────────────────────────────
 *
 * The old shop ran the Rey theme, and many product descriptions open with that
 * shortcode. On WordPress it drew a Global Section -- "Gentle Yet Effective
 * Ingredients" and three ingredients in a row. The new product page printed it
 * as letters at the top of the Description tab, because nothing here knew what
 * it meant. ContentBlockImporter now brings the sections across as HTML Blocks
 * keyed by `blocks.wc_id`, and this is the half that draws them.
 *
 * ── THE RULES ───────────────────────────────────────────────────────────────
 *
 *  - ANY SPELLING the old editor could have saved: id="N", id='N', id=N,
 *    WordPress's curly quotes, and other attributes in any order around it.
 *  - A MISSING OR DRAFT BLOCK DRAWS NOTHING. Never the shortcode's text: a
 *    shopper must not read `[rey_global_section ...]`, and an error is not
 *    the storefront's to print. The same rule Shortcodes::block() follows,
 *    and draft is the same off switch.
 *  - ONE QUERY FOR EVERY SECTION A PAGE NAMES, and none at all for copy that
 *    names none -- which is every product in StorefrontQueryBudgetTest. The
 *    rows are kept for the rest of the REQUEST in the container (not in a
 *    static: CLAUDE.md's Setting::map() landmine is a static memo seeing a
 *    stale table in a long-lived process), so the Description tab and the
 *    short description on one page share the lookup.
 *  - THE BLOCK IS CLEANED AGAIN HERE. An imported block was cleaned on the way
 *    in, but Content -> HTML Blocks stores what the owner types, and this
 *    prints into a product description with {!! !!}. RichText::clean() is the
 *    same allowlist the description itself goes through.
 *
 * ── AND THE ORDER AGAINST wpautop() ─────────────────────────────────────────
 *
 * RichText::forDisplay() lays bare-newline copy out with a port of wpautop(),
 * and that regex surgery would wrap the block's picture in a <p> and break its
 * row apart. So the block is never inside the text forDisplay() lays out:
 * forDisplay() lifts each shortcode onto a paragraph of its own (see
 * isolate()), and expand() runs AFTER it, replacing that paragraph whole with
 * the block's own HTML. The block arrives byte for byte as it was cleaned.
 */
final class GlobalSections
{
    /**
     * Shortcodes that name a WordPress post holding a section, by id.
     *
     * `elementor-template` is Elementor's own spelling of the same idea
     * (Elementor -> Templates -> Saved Templates), and the importer stores
     * whichever the export names in `blocks.source`.
     */
    public const SHORTCODES = [Block::SOURCE_REY, 'elementor-template'];

    /** The container key the request's rows are kept under. */
    private const MEMO = 'kbb.global_sections';

    /**
     * One shortcode, any attributes. Group 1 is the attribute string.
     *
     * NEVER INSIDE A TAG. The lookahead refuses a match whose next `>` comes
     * before any `<` -- i.e. one sitting in an attribute, `<a title="[rey…]">`.
     * Expanded there, the block's own `class="…"` would close the attribute and
     * spill markup into the tag. Copy between tags is unaffected.
     */
    public static function pattern(): string
    {
        return '/\[(?:' . implode('|', array_map(static fn (string $s): string => preg_quote($s, '/'), self::SHORTCODES))
            . ')\b([^\]\[]*)\](?![^<>]*>)/i';
    }

    /** Is there anything here for expand() to do? Cheap; no query. */
    public static function present(?string $html): bool
    {
        if ($html === null || $html === '') {
            return false;
        }

        foreach (self::SHORTCODES as $shortcode) {
            if (stripos($html, '[' . $shortcode) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Put each shortcode on a paragraph of its own, for forDisplay() to call
     * BEFORE autop(). Two blank lines either side are what wpautop() reads as
     * a paragraph break, so `[rey_global_section id=1]\nThis foam...` becomes
     * `<p>[rey…]</p><p>This foam...</p>` and not `<p>[rey…]<br />This foam`.
     */
    public static function isolate(string $text): string
    {
        if (! self::present($text)) {
            return $text;
        }

        return (string) preg_replace_callback(self::pattern(), static fn (array $m): string => "\n\n" . $m[0] . "\n\n", $text);
    }

    /**
     * Every shortcode in already-clean HTML replaced by its block, or by
     * nothing.
     */
    public static function expand(?string $html): string
    {
        $html = (string) $html;

        if (! self::present($html)) {
            return $html;
        }

        $blocks = self::load(self::ids($html));

        /*
         * A shortcode alone in its paragraph takes the paragraph with it --
         * `<p><div class="kbb-eblock">` is not HTML, the parser would close
         * the <p> early and leave an empty one either side of the block.
         * Stray <br>s and whitespace around it go too, for the same reason.
         */
        $edge = '(?:\s|&nbsp;|<br\s*\/?>)*';
        $shortcode = substr(self::pattern(), 1, -2);

        $html = (string) preg_replace_callback(
            '/<p(?:\s[^>]*)?>' . $edge . '(' . $shortcode . ')' . $edge . '<\/p>(\r?\n)?/i',
            static function (array $m) use ($blocks): string {
                $block = self::render($m[1], $blocks);

                // A section that draws nothing takes its line with it, so the
                // copy under it starts where the panel starts.
                return $block === '' ? '' : $block . ($m[3] ?? '');
            },
            $html,
        );

        return (string) preg_replace_callback(
            self::pattern(),
            static fn (array $m): string => self::render($m[0], $blocks),
            $html,
        );
    }

    /**
     * Look up every section several pieces of copy name, in one query, so the
     * expand() calls that follow find them already held. ProductTabs calls it
     * with the description AND the short description: the tab is built in the
     * controller and the blurb in the view, and without this they were two
     * lookups for one page. Measured in GlobalSectionRenderTest.
     */
    public static function prime(?string ...$copies): void
    {
        $ids = [];

        foreach ($copies as $copy) {
            if (self::present($copy)) {
                $ids = [...$ids, ...self::ids((string) $copy)];
            }
        }

        if ($ids !== []) {
            self::load(array_values(array_unique($ids)));
        }
    }

    /**
     * The same copy as TEXT, with every shortcode gone -- for the places that
     * print a description as words (the quick view, the meta description), where
     * a block of pictures has no business and the shortcode's letters even less.
     */
    public static function strip(string $text): string
    {
        if (! self::present($text)) {
            return $text;
        }

        return (string) preg_replace(self::pattern(), ' ', $text);
    }

    /** The WordPress ids a run of copy names, in order, once each. @return list<int> */
    public static function ids(string $html): array
    {
        preg_match_all(self::pattern(), $html, $matches);

        $ids = [];

        foreach ($matches[1] as $attributes) {
            $id = self::idOf($attributes);

            if ($id !== null && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The id attribute out of a shortcode's attribute string, however it was
     * quoted, or null. `id` as a whole word only -- `product_id="5"` and
     * `data-id="5"` are other attributes and must not be read as this one.
     */
    public static function idOf(string $attributes): ?int
    {
        $attributes = html_entity_decode($attributes, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match('/(?<![\w-])id\s*=\s*["\'\x{201C}\x{201D}\x{2033}\x{2032}\x{2018}\x{2019}]?\s*(\d{1,19})/iu', $attributes, $m) !== 1) {
            return null;
        }

        $id = (int) $m[1];

        return $id > 0 ? $id : null;
    }

    /** @param array<int, string> $blocks */
    private static function render(string $shortcode, array $blocks): string
    {
        if (preg_match(self::pattern(), $shortcode, $m) !== 1) {
            return '';
        }

        $id = self::idOf($m[1]);

        return $id === null ? '' : ($blocks[$id] ?? '');
    }

    /**
     * Published blocks by WordPress id, cleaned, for the ids not already held
     * for this request -- in ONE query.
     *
     * @param  list<int>  $ids
     * @return array<int, string> id => clean HTML ('' for a missing or draft one)
     */
    private static function load(array $ids): array
    {
        $memo = app()->bound(self::MEMO) ? (array) app(self::MEMO) : [];
        $missing = array_values(array_filter($ids, static fn (int $id): bool => ! array_key_exists($id, $memo)));

        if ($missing !== []) {
            /*
             * Before the migration has run -- a package copied but not yet
             * applied -- `blocks.wc_id` is not there, and a product page must
             * not 500 for it. It draws nothing in the shortcode's place, which
             * is what it draws for a block not imported yet either.
             */
            try {
                $rows = Block::query()
                    ->published()
                    ->whereIn('wc_id', $missing)
                    ->get(['wc_id', 'content']);
            } catch (QueryException) {
                $rows = [];
            }

            foreach ($rows as $row) {
                $memo[(int) $row->wc_id] = RichText::clean((string) $row->content);
            }
        }

        foreach ($missing as $id) {
            $memo[$id] ??= '';
        }

        app()->instance(self::MEMO, $memo);

        return $memo;
    }
}
