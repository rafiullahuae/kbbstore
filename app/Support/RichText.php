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
 * TWO DISPOSALS, AND THE DIFFERENCE MATTERS. A tag that is merely not on the
 * list — `<div>`, `<span>`, `<font>` — is UNWRAPPED: the tag goes, its text
 * stays, because an operator who pasted from Word should not silently lose a
 * paragraph. A tag from the hostile set — script, style, iframe, object, embed,
 * form, svg, math, and friends — is DROPPED WHOLE, children included, because
 * the payload IS the child text: unwrapping `<script>alert(1)</script>` would
 * leave `alert(1)` as visible copy, and unwrapping `<style>` would leave CSS
 * on the page as prose.
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
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'width', 'height'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => [], 'td' => [],
    ];

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
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed',
        'applet', 'form', 'input', 'button', 'select', 'option', 'textarea',
        'svg', 'math', 'link', 'meta', 'base', 'template', 'noscript',
        'audio', 'video', 'source', 'track', 'canvas', 'portal',
    ];

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
        $allowed = self::ALLOWED[$tag];

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
