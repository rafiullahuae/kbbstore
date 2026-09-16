<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Keeps editor-authored body copy from producing a second <h1>.
 *
 * RichText::clean() already documents the rule and enforces it — h1 is absent
 * from both of its allowlists precisely because "the page template owns the
 * page's single h1, and a second one in the body copy competes with it". But
 * RichText::clean() is called from exactly one place, the product editor's
 * write path (Admin\ProductEditorApiController), and it is the only writer in
 * the application that goes through it.
 *
 * Article bodies and CMS page content do not. Both are rendered with
 * @shortcodes($post->body) / @shortcodes($page->content), which expands
 * shortcodes and returns the stored HTML otherwise untouched, and both
 * templates already emit their own <h1> for the title just above. Verified
 * against the rendered page rather than the template: an article whose body
 * contains an <h1> serves two of them, the title's and the body's.
 *
 * That HTML arrives from the WordPress import and from an admin pasting
 * formatted copy, so it is not hypothetical — WordPress's own editor offers
 * "Heading 1" in its block menu.
 *
 * WHY DEMOTE RATHER THAN STRIP. Stripping the tag, which is what RichText does
 * on the product write path, is right there: it happens once, at save time, on
 * content an editor is actively working on. Here it would happen on every
 * render of content nobody is editing, and an h1 in an imported article is
 * almost always a real section heading that was tagged too strongly. Turning
 * it into an h2 keeps it a heading, keeps the document outline sensible, and
 * leaves the page with exactly one h1 — whereas stripping it would silently
 * flatten a section heading into a paragraph on a page the owner has not
 * touched.
 *
 * Applied at render rather than on save for the same reason: the content is
 * already in the database, and a save-time fix would only ever reach rows
 * somebody happens to edit afterwards.
 */
final class BodyHeadings
{
    /**
     * Rewrite every <h1> in a block of body HTML as an <h2>, attributes and
     * inner markup intact.
     *
     * Deliberately narrow. It matches the tag name only, so an attribute whose
     * value contains "h1" is untouched, and it does not try to renumber h2..h6
     * below it: a body that goes h1, h2, h3 becomes h2, h2, h3, which is a flat
     * pair rather than a skipped level, and nothing in the outline is invented.
     */
    public static function demoteH1(?string $html): string
    {
        $html = (string) $html;

        if ($html === '' || stripos($html, '<h1') === false) {
            return $html;
        }

        return (string) preg_replace(
            ['/<h1(\s[^>]*)?>/i', '/<\/h1\s*>/i'],
            ['<h2$1>', '</h2>'],
            $html
        );
    }
}
