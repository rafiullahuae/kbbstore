<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * An allowlist sanitiser for operator-authored product HTML.
 *
 * WHY THIS EXISTS, AND WHY THE SERVER IS THE ONE THAT DECIDES.
 *
 * resources/views/partials/product-tabs.blade.php renders the description with
 * `{!! $tab['body'] !!}` — twice, once for the desktop panel and once for the
 * mobile accordion. That is raw HTML on a public page, and it was already raw
 * before this file existed: the WooCommerce importer writes descriptions
 * straight from the export, and nothing in this application has ever filtered
 * them. Putting a rich-text editor in the admin without this file would not
 * have "introduced" the hole so much as aimed a convenient tool at it.
 *
 * The editor in the browser is a convenience. It is not a control: anything
 * that can reach the save endpoint can send any string at all, so the allowlist
 * runs on the way IN to the database, on the server, every time. Nothing is
 * trusted for having come from the editor's own toolbar.
 *
 * THE RULE IS AN ALLOWLIST, NEVER A DENYLIST. A denylist has to anticipate
 * `<script>`, `<ScRiPt>`, `<script/x>`, `onerror=`, `onpointerenter=`,
 * `javascript:`, `jAvAsCrIpT&colon;`, `data:text/html`, SVG `<use href>`,
 * `<style>@import`, and whatever the next one turns out to be. An allowlist has
 * to anticipate nothing: a tag that is not named below does not survive, and an
 * attribute that is not named below does not survive, including every `on*`
 * handler in one stroke rather than one at a time.
 *
 * THREE DISPOSALS, AND THE DIFFERENCES MATTER. A tag that is merely not on the
 * list — `<div>`, `<span>`, `<font>` — is UNWRAPPED: the tag goes, its text
 * stays, because an operator who pasted from Word should not silently lose a
 * paragraph. A tag from the hostile set — script, style, iframe, object, form,
 * svg, math, and friends — is DROPPED WHOLE, children included, because the
 * payload IS the child text: unwrapping `<script>alert(1)</script>` would leave
 * `alert(1)` as visible copy, and unwrapping `<style>` would leave CSS on the
 * page as prose.
 *
 * The third is DROP_TAG_KEEP_CHILDREN, and it is there because the parser is
 * HTML4: `source`, `track` and `embed` are HTML5 void elements libxml does not
 * know, so it files everything after one of them as its CHILD. Dropping such a
 * subtree whole deleted the rest of the article. Its own docblock has the
 * measurement and the argument.
 *
 * URLs ARE RE-PARSED, NOT PATTERN-MATCHED. An href is accepted only if, after
 * HTML entities are decoded and whitespace and control characters are removed,
 * its scheme is http, https or mailto, or it is root-relative. That decoding
 * step is the whole point: `java&Tab;script:` and `&#106;avascript:` are the
 * same string as `javascript:` by the time a browser acts on it, so the check
 * has to happen on the decoded form or it is checking something the browser
 * will never see.
 */
final class RichText
{
    /**
     * Tags that survive, and the attributes each may keep.
     *
     * Everything not named here is removed from every element — class, style,
     * id, data-*, and every `on*` handler — so no event handler needs naming.
     */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [], 'b' => [],
        'em' => [], 'i' => [],
        'u' => [], 's' => [], 'strike' => [],
        'sub' => [], 'sup' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'blockquote' => [],
        'hr' => [],
        'a' => ['href', 'title', 'target', 'rel', 'class'],
        'img' => ['src', 'alt', 'width', 'height', 'class', 'loading'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => [], 'td' => [],

        /*
         * Added because the owner asked to be able to paste real markup and
         * keep it — a description copied from a supplier sheet or a previous
         * shop arrives full of these, and unwrapping them turned a laid-out
         * description into one grey slab.
         *
         * Every one of them is inert: they carry no behaviour, no navigation
         * and no resource loading. That is the line, and it is why `script`,
         * `iframe` and friends stay in DROP_WHOLE below however much anyone
         * would like a video embed — an iframe is a page under someone else's
         * control rendered inside this one.
         */
        'div' => ['class'], 'span' => ['class'],
        'figure' => ['class'], 'figcaption' => ['class'],
        'small' => [], 'mark' => [], 'abbr' => ['title'],
        'code' => [], 'pre' => [], 'kbd' => [], 'samp' => [],
        'dl' => [], 'dt' => [], 'dd' => [],
        'caption' => [], 'colgroup' => [], 'col' => ['span'],
        'section' => ['class'], 'article' => ['class'], 'aside' => ['class'],
    ];

    /**
     * Attributes any allowed tag may keep, on top of its own list.
     *
     * `class` only, and deliberately not `style` or `id`. `class` can do
     * nothing on its own — it names a rule the storefront's own stylesheet
     * either has or does not. `style` is different in kind: an inline
     * `position:fixed` with a large `z-index` is an invisible layer over the
     * whole page, which is a clickjacking primitive rather than a formatting
     * choice, and `id` lets pasted markup silently collide with the theme's
     * own anchors and form labels.
     *
     * If the owner needs specific inline styling, the honest answer is a named
     * class backed by a real rule, not a hole here.
     */
    private const ALLOWED_ON_ANY = ['class'];

    /**
     * Tags removed with everything inside them.
     *
     * Note what is absent from BOTH lists: `h1`. It is not dangerous, it is an
     * SEO mistake — the product page already emits the product name as the
     * page's single h1, and a second one in the body copy competes with it. So
     * it falls through to the unwrap path: the editor offers h2 down to h6, and
     * an h1 pasted in from elsewhere loses its tag while keeping its words.
     */
    private const DROP_WHOLE = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object',
        'applet', 'form', 'input', 'button', 'select', 'option', 'textarea',
        'svg', 'math', 'link', 'meta', 'base', 'template', 'noscript',
        'audio', 'video', 'canvas', 'portal',
    ];

    /**
     * Unwanted tags that must NOT take their parsed children with them.
     *
     * THE PARSER IS HTML4 AND THESE THREE ARE HTML5 VOID ELEMENTS. libxml 2.9's
     * HTML parser knows exactly one void set — the HTML4 one: area, base, br,
     * col, frame, hr, img, input, link, meta, param. `source`, `track` and
     * `embed` are not in it, so an unclosed `<source …>` is opened as a
     * CONTAINER and everything that follows it, up to the close of its parent,
     * is parsed as its CHILD rather than as its sibling. Measured here, not
     * assumed: `<picture><source srcset=…><img src=…></picture>` parses to
     * picture > source > img.
     *
     * WHAT THAT COST ON THE SHOP. These three used to sit in DROP_WHOLE, and
     * DROP_WHOLE removes the subtree. `<source>` before `<img>` is the ONLY
     * valid ordering inside a `<picture>`, so every `<picture>` block in an
     * imported article was removed in full — the photograph with it — and the
     * article arrived with a hole where a picture had been. Nothing said so.
     * An `<embed>` or a `<track>` did the same to every paragraph that followed
     * it to the end of its parent.
     *
     * WHY UNWRAPPING IS THE RIGHT DISPOSAL AND NOT A WIDENED ALLOWLIST. None of
     * these three may legally hold children, so a child of one is never
     * content the author put inside it — it is the next sibling, misfiled by
     * the parser. Promoting it is what the document said. And the tag itself
     * still does not survive: it is in neither ALLOWED nor any exception here,
     * so the element goes and every attribute on it goes with it — `srcset`,
     * `type` and `src` included. Nothing new can be printed, which is the test
     * a change to a sanitiser has to pass. The alternative — rewriting the
     * markup with a regex before it reaches the parser — would mean pattern
     * matching untrusted HTML to decide what the parser then sees, which is the
     * denylist this file exists to refuse.
     *
     * DROP_WHOLE IS STILL RIGHT FOR THE REST. `<script>`, `<style>` and friends
     * are real containers whose child text IS the payload; unwrapping one would
     * print it as copy. The rule that separates the two lists is not "hostile
     * or not", it is: does this element legally have children? These three do
     * not, so there is nothing of theirs to drop.
     *
     * WHAT THIS BRANCH IS ACTUALLY FOR. Removing the three names from
     * DROP_WHOLE is what saves the pictures: with no entry on either list they
     * would fall through to the ordinary unwrap path and behave identically.
     * This list exists to be checked BEFORE DROP_WHOLE, so that putting one of
     * them back on it — the obvious tidy-up for someone who reads `source` as a
     * media tag — cannot quietly restore the loss. It is a guard on the
     * invariant rather than the mechanism, and the mutation notes in
     * tests/Feature/ImportJournalPictureTest.php say so with the measurements.
     */
    private const DROP_TAG_KEEP_CHILDREN = ['source', 'track', 'embed'];

    /** Schemes an href or src may carry once decoded. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto'];

    /**
     * Operator HTML in, publishable HTML out.
     *
     * Null and the empty string round-trip as the empty string rather than as
     * null: a description cleared in the editor is a description the operator
     * cleared, and the column is written either way.
     */
    public static function clean(?string $html): string
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        /*
         * Parsed as a fragment inside a wrapper whose own tag is discarded
         * afterwards. libxml would otherwise supply <html><body> itself, and
         * the wrapper makes the node to walk unambiguous.
         *
         * The meta charset line is how loadHTML is told this is UTF-8; without
         * it libxml assumes ISO-8859-1 and Arabic or an accented brand name
         * comes back as mojibake. Errors are collected rather than raised
         * because operator HTML is routinely malformed and a warning is not a
         * reason to refuse to save.
         */
        $document = new DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="kbb-richtext-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('kbb-richtext-root');

        if (! $root instanceof DOMElement) {
            // Nothing parsed into a walkable tree — fall back to the text.
            return trim(strip_tags($html));
        }

        self::walk($root);

        $out = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Is there anything here once the markup is taken away?
     *
     * Used to decide whether a tab has a body. `<p>&nbsp;</p>` is what an empty
     * contenteditable serialises to, and it is not content — the non-breaking
     * space is normalised to an ordinary one before the trim, or the string
     * would measure as non-empty forever.
     */
    public static function isBlank(?string $html): bool
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim($text) === '';
    }

    /**
     * Visible characters, for the editor's counter and for a meta-description
     * default.
     *
     * Block boundaries become a space BEFORE the tags are stripped. Without
     * that step `<p>Hello</p><p>world</p>` reads back as "Helloworld", and the
     * meta description generated from a product's own copy is a wall of run-on
     * words — which is what Google would then show under the result.
     */
    public static function toText(?string $html): string
    {
        /*
         * A `[rey_global_section id=N]` is a pointer at a block of pictures,
         * not words (Lane PJ-B). The quick view and the meta description read
         * product copy through here, and printed it as letters -- Google's
         * snippet for a product began "[rey_global_section id="18159"] This
         * foam…". Copy naming no section is untouched.
         */
        $text = GlobalSections::strip((string) $html);
        $text = (string) preg_replace('#</(p|div|li|h[1-6]|tr|td|th|blockquote)\s*>#i', ' ', $text);
        $text = (string) preg_replace('#<(br|hr)\s*/?>#i', ' ', $text);

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Imported or authored product copy, ready to print raw. (Lane PI-A)
     *
     * WHAT THE OWNER SAW. His WooCommerce descriptions arrived as one run-on
     * slab: "…other functions. Medicube – PDRN Pink Collagen Capsule Cream This
     * elasticity…", headings and paragraphs welded together, where the old
     * WordPress shop had shown a heading, a bold numbered line, spaced
     * paragraphs and a list. Nothing was lost on import. WooCommerce stores
     * classic-editor post_content with BARE NEWLINES and no <p> at all, and
     * WordPress builds the paragraphs at display time with wpautop(): a blank
     * line is a paragraph, a single newline is a <br>. This shop printed the
     * stored text without that step, and a browser renders a newline as a
     * space.
     *
     * So the step happens here, at render time, which is what fixes the
     * products already imported on the live shop without re-importing a row.
     *
     * TWO HALVES, AND THE ORDER IS THE CONTROL.
     *
     *   1. autop(), only when the copy has a newline that a browser would
     *      otherwise swallow (needsAutop()). Copy written in the admin editor
     *      already carries its own <p> tags, newlines only between blocks, and
     *      is passed through untouched by this half -- so a description
     *      nobody imported renders exactly as it did.
     *   2. clean(), ALWAYS, and LAST. This is printed with {!! !!}, so nothing
     *      reaches the page that has not just been through the allowlist --
     *      not the stored value (whose write path may not have cleaned it:
     *      AdminController's quick edit stores `description` as sent) and not
     *      the markup autop() itself produced. autop() is regex surgery over
     *      HTML and is trusted with nothing: its output is parsed and
     *      re-serialised like any other input.
     */
    public static function forDisplay(?string $html): string
    {
        $html = (string) $html;

        if (trim($html) === '') {
            return '';
        }

        if (! self::needsAutop($html)) {
            return self::clean($html);
        }

        /*
         * Cleaned BEFORE autop() as well as after it. A <script> in the raw
         * copy is a line of its own to autop(), which wraps it in a <p>; the
         * allowlist then drops the script and leaves `<p></p>` behind -- an
         * empty paragraph, a blank gap on the page. Taking the hostile markup
         * out first means autop() only ever lays out what will be printed.
         * The second clean() is still the one that guards the page.
         *
         * GlobalSections::isolate() between the two (Lane PJ-B): a
         * `[rey_global_section id=N]` is put on a paragraph of its own, so
         * GlobalSections::expand() can later replace that paragraph whole with
         * the block. Left inline, `[rey…]\nThis foam…` became
         * `<p>[rey…]<br />This foam…</p>` and the block landed inside a <p>
         * the parser then broke apart. Copy naming no section is untouched.
         */
        return self::clean(self::autop(GlobalSections::isolate(self::clean($html))));
    }

    /**
     * forDisplay(), plus the old shop's Global Sections drawn as their blocks.
     * (Lane PJ-B)
     *
     * For the storefront ONLY. forDisplay() stays free of the database because
     * ProductImporter calls it at import time to store a tab's body, and a
     * section expanded there would be frozen into the tab -- or, if its block
     * had not been imported yet, deleted from it for good. Here it is resolved
     * at render, so an edit in Content -> HTML Blocks reaches every product.
     */
    public static function forStorefront(?string $html): string
    {
        return GlobalSections::expand(self::forDisplay($html));
    }

    /**
     * forStorefront() for the SHORT DESCRIPTION: the same HTML, minus the empty
     * lines above and below the copy. (Lane PV)
     *
     * WHAT THE OWNER SAW. "The short description on mobile for this product is
     * not showing by default, and it shows after pressing Read more but with
     * upper space." The product was an imported WooCommerce row, and its
     * post_excerpt OPENED WITH EMPTY PARAGRAPHS -- `<p>&nbsp;</p>`, which is
     * what the classic editor saves for every blank line typed above the copy.
     * The blurb is capped at three line-boxes with a fade (see `.pdp .bb-desc`),
     * and two empty paragraphs at 13.5px with their .6em margins are 60px of
     * nothing: they filled the cap, the copy started at 82px inside an 86px box,
     * and the fade dissolved what little of it was left. Measured in Chromium on
     * a seeded twin of that row. "Read more" then lifted the cap and showed the
     * copy under the same blank band, which is the "upper space".
     *
     * It is not CSS's to fix: `:empty` does not match `<p>&nbsp;</p>`, and no
     * selector can tell a paragraph of non-breaking spaces from a paragraph of
     * words. It is not the importer's either -- the rows are already on the live
     * shop, and a render-time step fixes every one of them without a re-import,
     * the same argument forDisplay() makes for autop().
     *
     * ONLY THE EDGES. A blank line BETWEEN two paragraphs is the author's
     * spacing and is kept; only the run before the first word and after the
     * last one goes. And a blurb with nothing to trim comes back as the SAME
     * STRING, byte for byte -- not re-serialised -- so every other product page
     * in the shop is unchanged (StorefrontEnglishUnchangedTest).
     */
    public static function forShortDescription(?string $html): string
    {
        return self::trimBlankEdges(self::forStorefront($html));
    }

    /**
     * Remove what renders as empty space before the first visible thing and
     * after the last one: whitespace and `&nbsp;` text, `<br>`, and any element
     * with no text and nothing visible inside it (`<p>&nbsp;</p>`, `<div></div>`,
     * `<p><strong> </strong></p>`, `<h2><br></h2>`). An element that has content
     * is entered rather than removed, so `<p><br>&nbsp;Copy</p>` loses the
     * `<br>&nbsp;` and keeps its <p>.
     *
     * The input is HTML this class has ALREADY CLEANED, and this only ever
     * removes nodes or trims characters out of a text node -- it cannot add
     * anything, so the result is as safe to print as what came in.
     */
    public static function trimBlankEdges(string $html): string
    {
        return self::trimBlank($html, true);
    }

    /**
     * trimBlankEdges() for the TOP edge only: what renders as empty space
     * before the first visible thing. (Lane RG)
     *
     * WHAT THE OWNER SAW. "give controls of details tab heading and the
     * content between spacing as marked" -- on his laptop the Description tab
     * opened about 75px under the tab row and the Major Ingredients tab about
     * 10px under it, with the same setting behind both. An imported WooCommerce
     * description that opens with blank lines (`&nbsp;` and an empty line above
     * the copy, which wpautop() turns into `<p>&nbsp;</p>`) draws them as
     * paragraphs, each a line-box and a margin tall, so the tab-row spacing
     * control could only ever decide the SMALLER of the two gaps.
     *
     * Applied to the tab bodies at render time (Store\ProductController::
     * tabs()); nothing stored is altered. A blank line in the MIDDLE or at the
     * end of a description is the author's and stays. A body with nothing to
     * trim comes back as the same string, byte for byte.
     */
    public static function trimLeadingBlank(string $html): string
    {
        return self::trimBlank($html, false);
    }

    private static function trimBlank(string $html, bool $trailing): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="kbb-richtext-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('kbb-richtext-root');

        if (! $root instanceof DOMElement) {
            return $html;
        }

        $changed = self::trimEdge($root, true);
        $changed = ($trailing && self::trimEdge($root, false)) || $changed;

        if (! $changed) {
            return $html;
        }

        $out = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    /** Elements that are something to look at even with no text in them. */
    private const VISIBLE_EMPTY = ['img', 'hr', 'table', 'picture', 'video', 'iframe', 'svg', 'canvas', 'figure'];

    /** Whitespace a shopper cannot see: ASCII, no-break, zero-width, BOM. */
    private const BLANK = '[\s\x{00A0}\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]';

    /**
     * Trim one edge of $parent's children; true when anything a browser would
     * have drawn as space was removed (pure layout whitespace does not count,
     * so a blurb that only had a newline at an edge is not re-serialised).
     */
    private static function trimEdge(DOMNode $parent, bool $leading): bool
    {
        $changed = false;

        while (($node = $leading ? $parent->firstChild : $parent->lastChild) !== null) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = (string) $node->nodeValue;
                $kept = (string) preg_replace($leading ? '/^' . self::BLANK . '+/u' : '/' . self::BLANK . '+$/u', '', $text);

                if ($kept === $text) {
                    return $changed;
                }

                // Layout whitespace alone is not a change worth re-serialising for;
                // a no-break or zero-width space is something the page drew.
                $ascii = $leading ? ltrim($text, " \t\n\r\0\x0B") : rtrim($text, " \t\n\r\0\x0B");
                $changed = $changed || $kept !== $ascii;

                if ($kept === '') {
                    $parent->removeChild($node);

                    continue;
                }

                $node->nodeValue = $kept;

                return $changed;
            }

            if (! $node instanceof DOMElement) {
                $parent->removeChild($node);

                continue;
            }

            if (strtolower($node->nodeName) === 'br' || self::isBlankElement($node)) {
                $parent->removeChild($node);
                $changed = true;

                continue;
            }

            // Something visible is in here: go in and trim its own edge, then stop.
            return self::trimEdge($node, $leading) || $changed;
        }

        return $changed;
    }

    /** No visible text and nothing visible-without-text anywhere inside. */
    private static function isBlankElement(DOMElement $element): bool
    {
        if (in_array(strtolower($element->nodeName), self::VISIBLE_EMPTY, true)) {
            return false;
        }

        foreach (self::VISIBLE_EMPTY as $tag) {
            if ($element->getElementsByTagName($tag)->length > 0) {
                return false;
            }
        }

        return preg_replace('/' . self::BLANK . '+/u', '', (string) $element->textContent) === '';
    }

    /**
     * Does any block-level tag survive in this (already clean) HTML?
     *
     * The short description sits in a <p> today. A <p> cannot hold a <p>, a
     * <div> or a <ul> -- the parser closes it at the first one and the rest of
     * the blurb falls out of the clamp -- so the template asks this and picks
     * <div> only for a blurb that has blocks in it. A one-line blurb keeps its
     * <p>, byte for byte.
     */
    public static function hasBlocks(string $html): bool
    {
        return preg_match('#<' . self::AUTOP_BLOCKS . '[\s/>]#i', $html) === 1;
    }

    /**
     * Would a browser lose a line break the author typed?
     *
     * Whitespace next to a block tag is layout, not content: `<p>a</p>\n<p>b</p>`
     * renders the same with or without the newline, and that is the shape the
     * admin editor saves. Take that whitespace away, and any newline left is
     * one sitting between words or inline tags -- which a browser renders as a
     * single space, and which WordPress rendered as a paragraph or a <br>.
     */
    public static function needsAutop(string $html): bool
    {
        if (! str_contains($html, "\n") && ! str_contains($html, "\r")) {
            return false;
        }

        $probe = (string) preg_replace('#<pre[\s>].*?</pre>#is', '', $html);
        $probe = (string) preg_replace('#\s*(</?' . self::AUTOP_BLOCKS . '(?:[\s/][^>]*)?>)\s*#i', '$1', $probe);

        return str_contains(trim($probe), "\n") || str_contains(trim($probe), "\r");
    }

    /**
     * Block-level tags, as WordPress's wpautop() lists them.
     *
     * Kept to WordPress's own list, not this file's allowlist, because the job
     * is to reproduce how the old shop laid the same text out. Several of these
     * (form, style, map) never survive clean(); naming them here only decides
     * where autop() refuses to put a <p> or a <br>.
     */
    private const AUTOP_BLOCKS = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre'
        . '|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside'
        . '|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';

    /**
     * A port of WordPress's wpautop($text, $br = true), wp-includes/formatting.php.
     *
     * Ported rather than approximated, because the target is "looks the way
     * the old shop looked", and the old shop ran exactly this. Kept: <pre>
     * protection, the blank-line paragraph split, <br> for a single newline,
     * no <p> or <br> next to a block tag, and the <li>/<blockquote> fix-ups.
     * Dropped: the <option>, <object>, <source>/<track> and <!-- wpnl -->
     * branches -- every one of those tags is removed by clean() regardless, so
     * there is nothing for them to protect.
     *
     * NEVER PRINT THIS ON ITS OWN. It does not sanitise and its output is
     * not guaranteed well-formed; forDisplay() runs clean() over it.
     */
    public static function autop(string $text): string
    {
        if (trim($text) === '') {
            return '';
        }

        $blocks = self::AUTOP_BLOCKS;
        $preTags = [];
        $text .= "\n";

        // <pre> is set aside whole and put back at the end: its newlines are
        // already meaningful and must not become <br>.
        if (str_contains($text, '<pre')) {
            $parts = explode('</pre>', $text);
            $last = array_pop($parts);
            $text = '';

            foreach ($parts as $i => $part) {
                $start = strpos($part, '<pre');

                if ($start === false) {
                    $text .= $part;

                    continue;
                }

                $name = "<pre wp-pre-tag-{$i}></pre>";
                $preTags[$name] = substr($part, $start) . '</pre>';
                $text .= substr($part, 0, $start) . $name;
            }

            $text .= $last;
        }

        $text = (string) preg_replace('|<br\s*/?>\s*<br\s*/?>|', "\n\n", $text);
        $text = (string) preg_replace('!(<' . $blocks . '[\s/>])!', "\n\n$1", $text);
        $text = (string) preg_replace('!(</' . $blocks . '>)!', "$1\n\n", $text);
        $text = (string) preg_replace('!(<hr\s*?/?>)!', "$1\n\n", $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // A newline INSIDE a tag (between attributes) is not a line break.
        $text = (string) preg_replace_callback('/<[^>]*>/', static fn (array $m): string => str_replace("\n", ' ', $m[0]), $text);

        if (str_contains($text, '<figcaption')) {
            $text = (string) preg_replace('|\s*(<figcaption[^>]*>)|', '$1', $text);
            $text = (string) preg_replace('|</figcaption>\s*|', '</figcaption>', $text);
        }

        $text = (string) preg_replace("/\n\n+/", "\n\n", $text);
        $paragraphs = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $text = '';

        foreach ($paragraphs as $paragraph) {
            $text .= '<p>' . trim($paragraph, "\n") . "</p>\n";
        }

        $text = (string) preg_replace('|<p>\s*</p>|', '', $text);
        $text = (string) preg_replace('!<p>([^<]+)</(div|address|form)>!', '<p>$1</p></$2>', $text);
        $text = (string) preg_replace('!<p>\s*(</?' . $blocks . '[^>]*>)\s*</p>!', '$1', $text);
        $text = (string) preg_replace('|<p>(<li.+?)</p>|', '$1', $text);
        $text = (string) preg_replace('|<p><blockquote([^>]*)>|i', '<blockquote$1><p>', $text);
        $text = str_replace('</blockquote></p>', '</p></blockquote>', $text);
        $text = (string) preg_replace('!<p>\s*(</?' . $blocks . '[^>]*>)!', '$1', $text);
        $text = (string) preg_replace('!(</?' . $blocks . '[^>]*>)\s*</p>!', '$1', $text);

        $text = str_replace(['<br>', '<br/>'], '<br />', $text);
        $text = (string) preg_replace('|(?<!<br />)\s*\n|', "<br />\n", $text);

        $text = (string) preg_replace('!(</?' . $blocks . '[^>]*>)\s*<br />!', '$1', $text);
        $text = (string) preg_replace('!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!', '$1', $text);
        $text = (string) preg_replace("|\n</p>$|", '</p>', $text);

        if ($preTags !== []) {
            $text = str_replace(array_keys($preTags), array_values($preTags), $text);
        }

        return $text;
    }

    /**
     * Depth-first, and deliberately over a SNAPSHOT of the child list.
     *
     * The walk removes and replaces nodes as it goes, and DOMNodeList is live:
     * iterating it directly while mutating skips siblings, which in a sanitiser
     * means a `<script>` that happens to follow a removed node survives. Every
     * level is copied to a plain array with iterator_to_array first.
     */
    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                self::element($child);
                continue;
            }

            // Comments can carry markup that some parsers resurrect, and they
            // are never content. Anything that is not an element or text goes:
            // processing instructions and CDATA included.
            if ($child->nodeType !== XML_TEXT_NODE) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    private static function element(DOMElement $element): void
    {
        $tag = strtolower($element->nodeName);

        if (in_array($tag, self::DROP_TAG_KEEP_CHILDREN, true)) {
            // A void element the parser mis-read as a container. The tag and
            // every attribute on it go; what it "contains" is really what came
            // after it, and that stays. See the const's docblock.
            self::walk($element);
            self::unwrap($element);

            return;
        }

        if (in_array($tag, self::DROP_WHOLE, true)) {
            $element->parentNode?->removeChild($element);

            return;
        }

        if (! array_key_exists($tag, self::ALLOWED)) {
            // Not hostile, just not ours: keep the words, drop the tag. The
            // children are walked first so what gets promoted is already clean.
            self::walk($element);
            self::unwrap($element);

            return;
        }

        self::attributes($element, $tag);
        self::walk($element);
    }

    /** Strip every attribute the tag is not explicitly allowed to keep. */
    private static function attributes(DOMElement $element, string $tag): void
    {
        $allowed = array_merge(self::ALLOWED[$tag], self::ALLOWED_ON_ANY);

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            if ($name === 'href' || $name === 'src') {
                $url = self::url($attribute->nodeValue);

                if ($url === null) {
                    // An anchor with no destination is still readable text; an
                    // image with no source is a broken icon, so that one goes.
                    if ($tag === 'img') {
                        $element->parentNode?->removeChild($element);

                        return;
                    }

                    $element->removeAttribute($attribute->nodeName);

                    continue;
                }

                $element->setAttribute($name, $url);

                continue;
            }

            if ($name === 'width' || $name === 'height') {
                // Digits only. A dimension is the one numeric attribute here
                // and "100%;background:url(javascript:…)" is not a number.
                if (preg_match('/^\d{1,4}$/', (string) $attribute->nodeValue) !== 1) {
                    $element->removeAttribute($attribute->nodeName);
                }
            }
        }

        /*
         * An outbound link opens in a new tab, and a new tab gets rel. Set here
         * rather than trusted from the input: window.opener is a live handle to
         * this page unless noopener says otherwise.
         */
        if ($tag === 'a' && $element->hasAttribute('href')) {
            $href = $element->getAttribute('href');

            if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                $element->setAttribute('rel', 'noopener noreferrer');
                $element->setAttribute('target', '_blank');
            }
        }
    }

    /** Replace an element with its children, in order. */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * A URL the page may point at, or null.
     *
     * The decode-and-strip before the scheme test is the part that does the
     * work. A browser resolves `jav&#x09;ascript:alert(1)` to a javascript URL;
     * a naive `str_starts_with($url, 'javascript:')` sees a string starting
     * with "jav&#x09;" and waves it through. Entities are decoded first, then
     * every whitespace and C0/C1 control character is removed, and only then is
     * the scheme read — so the check runs on the same string the browser will.
     */
    private static function url(?string $raw): ?string
    {
        $url = trim((string) $raw);

        if ($url === '') {
            return null;
        }

        $probe = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = (string) preg_replace('/[\s\x00-\x1F\x7F-\x9F]+/u', '', $probe);
        $probe = strtolower($probe);

        // Relative and root-relative URLs carry no scheme and stay on this
        // origin. A protocol-relative `//evil.test` is not one of those.
        if (str_starts_with($probe, '//')) {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m) === 1) {
            return in_array($m[1], self::SAFE_SCHEMES, true) ? $url : null;
        }

        // No scheme at all: a path, a query or a fragment. Safe by construction.
        return $url;
    }
}
