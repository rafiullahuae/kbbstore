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
        $text = (string) preg_replace('#</(p|div|li|h[1-6]|tr|td|th|blockquote)\s*>#i', ' ', (string) $html);
        $text = (string) preg_replace('#<(br|hr)\s*/?>#i', ' ', $text);

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
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
