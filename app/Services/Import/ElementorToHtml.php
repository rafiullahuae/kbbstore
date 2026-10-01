<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\RichText;

/**
 * An Elementor document, as plain native HTML this shop can style. (Lane PJ-B)
 *
 * ── WHAT THE OWNER SAW ──────────────────────────────────────────────────────
 *
 * The old shop ran the Rey theme. Many product descriptions open with
 * `[rey_global_section id="18159"]`, which on WordPress drew a block built in
 * Elementor: a heading, "Gentle Yet Effective Ingredients", and under it three
 * items in a row, each a square picture on the left and on the right a bold
 * uppercase title (QUERCETINOL, ANTI-SEBUM P, 0.5% BHA) with one sentence. The
 * new product page printed the shortcode as letters. The exporter now writes
 * those Global Sections to content_blocks.csv with their raw Elementor JSON,
 * and this turns that JSON into the block.
 *
 * ── WHY CONVERT, AND NOT KEEP ELEMENTOR'S OWN HTML ──────────────────────────
 *
 * Elementor's rendered markup is a dozen nested wrappers per widget, styled by
 * a per-post stylesheet this shop does not have and by inline `style`
 * attributes RichText::clean() removes on purpose. Printed through the
 * allowlist it collapses into an unstyled heap. So the TREE is read instead,
 * and each widget the owner's blocks actually use becomes one plain element
 * with a `kbb-eblock__*` class that resources/css/product.css styles.
 *
 * ── WHAT IT KNOWS, AND WHAT IT DOES WITH WHAT IT DOES NOT ───────────────────
 *
 *   heading      -> h2/h3/h4 (header_size kept inside that range, else h3)
 *   image-box    -> an item: picture, bold title, sentence
 *   image        -> a figure with its caption
 *   text-editor  -> its HTML, laid out the way wpautop() would
 *   icon-list    -> a list
 *   divider      -> a rule;  spacer -> nothing (it is only space)
 *   section / column / container -> a responsive row of columns, or a stack
 *
 * ANY OTHER WIDGET MAKES THE WHOLE BLOCK FALL BACK to the post's plain HTML
 * (`plain_html`, Elementor's own text fallback), cleaned. Half a block -- a
 * heading with the carousel under it silently missing -- would look finished
 * and be wrong, which is the one outcome worse than a plainer block. The
 * widget types that forced it are returned with counts so the import report
 * can name them, and the next lane can add them here.
 *
 * ── HIDDEN ON DESKTOP IS NOT DRAWN ──────────────────────────────────────────
 *
 * Elementor pages commonly carry a widget twice, one copy hidden on desktop
 * and one hidden on phones. This shop lays the block out responsively itself,
 * so one copy is drawn -- the desktop one -- and the phone duplicate is
 * skipped rather than printed twice on every product.
 *
 * ── NOTHING LEAVES WITHOUT THE ALLOWLIST ────────────────────────────────────
 *
 * Every string out of the JSON is attacker-shaped as far as this class is
 * concerned (it is post meta any plugin could have written), and the whole
 * result goes through RichText::clean() last. Attributes are escaped as they
 * are built and URLs are scheme-checked by clean(), so `javascript:` in an
 * image-box link is dropped exactly as it would be from a description.
 */
final class ElementorToHtml
{
    /** Widget types this converter draws. Anything else forces the fallback. */
    public const KNOWN_WIDGETS = ['heading', 'image-box', 'image', 'text-editor', 'icon-list', 'divider', 'spacer'];

    /** The element types that hold other elements. */
    private const CONTAINERS = ['section', 'column', 'container'];

    /** Rows wider than this are drawn this wide; the class set stops here. */
    private const MAX_COLUMNS = 6;

    /** A deeper tree than any real block, so a hostile one cannot recurse forever. */
    private const MAX_DEPTH = 24;

    /** The square an image-box picture is drawn in when the file says no size. */
    private const ITEM_IMAGE_SIZE = 300;

    /** @var array<string, int> */
    private array $unknown = [];

    /**
     * @return array{html: string, mode: 'elementor'|'plain'|'empty', unknown: array<string, int>, reason: string|null}
     */
    public function convert(?string $elementorJson, ?string $plainHtml): array
    {
        $this->unknown = [];

        $tree = $this->decode($elementorJson);

        if ($tree === null) {
            return $this->fallback($plainHtml, trim((string) $elementorJson) === ''
                ? 'no Elementor data'
                : 'the Elementor data is not readable JSON');
        }

        $html = $this->elements($tree, 0);

        if ($this->unknown !== []) {
            ksort($this->unknown);

            return $this->fallback($plainHtml, 'unknown Elementor widget(s)');
        }

        $html = RichText::clean('<div class="kbb-eblock">' . $html . '</div>');

        if (self::isEmptyBlock($html)) {
            return $this->fallback($plainHtml, 'the Elementor data draws nothing');
        }

        return ['html' => self::lines($html), 'mode' => 'elementor', 'unknown' => [], 'reason' => null];
    }

    /**
     * One structural element per line, so the block can be edited.
     *
     * The owner edits an imported section in Content -> HTML Blocks, in a plain
     * textarea; as clean() serialises it the whole section is ONE line of a
     * kilobyte and a half, and finding the second ingredient's sentence in it
     * is a search, not an edit. A newline before each of the converter's own
     * structural tags changes nothing on the page -- whitespace between block
     * and flex/grid children draws nothing -- and the block is printed after
     * wpautop(), so no newline here can become a <br>.
     */
    private static function lines(string $html): string
    {
        $html = (string) preg_replace(
            '/(?<!^)(<(?:div class="kbb-eblock__(?:row|col|item|copy)|h[234] class="kbb-eblock__heading|figure class="kbb-eblock__figure|ul class="kbb-eblock__list|hr class="kbb-eblock__rule)[^>]*>)/',
            "\n$1",
            $html,
        );

        // And the block's own closing tag on a line of its own.
        return (string) preg_replace('/<\/div>$/', "\n</div>", $html);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function decode(?string $json): ?array
    {
        $json = trim((string) $json);

        if ($json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        /*
         * `_elementor_data` is a JSON list of top-level elements. A single
         * element object is accepted as a list of one -- an exporter that
         * wrote the root rather than its children is still saying one thing.
         */
        if (is_array($decoded) && ! array_is_list($decoded) && isset($decoded['elType'])) {
            $decoded = [$decoded];
        }

        if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array{html: string, mode: 'plain'|'empty', unknown: array<string, int>, reason: string}
     */
    private function fallback(?string $plainHtml, string $reason): array
    {
        $plain = RichText::forDisplay((string) $plainHtml);

        if (RichText::isBlank($plain) && ! str_contains($plain, '<img')) {
            return ['html' => '', 'mode' => 'empty', 'unknown' => $this->unknown, 'reason' => $reason];
        }

        return [
            'html' => RichText::clean('<div class="kbb-eblock kbb-eblock--plain">' . $plain . '</div>'),
            'mode' => 'plain',
            'unknown' => $this->unknown,
            'reason' => $reason,
        ];
    }

    private static function isEmptyBlock(string $html): bool
    {
        return RichText::isBlank($html) && ! str_contains($html, '<img') && ! str_contains($html, '<hr');
    }

    /** @param list<mixed> $elements */
    private function elements(array $elements, int $depth): string
    {
        $out = '';

        foreach ($elements as $element) {
            $out .= $this->element($element, $depth);
        }

        return $out;
    }

    /** One element and everything under it, or '' when it draws nothing. */
    private function element(mixed $element, int $depth): string
    {
        if (! is_array($element) || $depth > self::MAX_DEPTH) {
            return '';
        }

        $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];

        if (self::hiddenOnDesktop($settings)) {
            return '';
        }

        $type = is_string($element['elType'] ?? null) ? $element['elType'] : '';

        if ($type === 'widget') {
            return $this->widget(is_string($element['widgetType'] ?? null) ? $element['widgetType'] : '', $settings);
        }

        if (! in_array($type, self::CONTAINERS, true)) {
            $this->unknown['elType:' . ($type === '' ? '(none)' : $type)] = ($this->unknown['elType:' . ($type === '' ? '(none)' : $type)] ?? 0) + 1;

            return '';
        }

        $children = [];

        foreach (is_array($element['elements'] ?? null) ? $element['elements'] : [] as $child) {
            $html = $this->element($child, $depth + 1);

            if (trim($html) !== '') {
                $children[] = $html;
            }
        }

        if ($children === []) {
            return '';
        }

        // A column is a stack; it is the ROW around it that lays columns out.
        if ($type === 'column' || count($children) === 1) {
            return implode('', $children);
        }

        if ($type === 'section' || self::isRowContainer($settings)) {
            $n = min(count($children), self::MAX_COLUMNS);

            return '<div class="kbb-eblock__row kbb-eblock__row--' . $n . '">'
                . implode('', array_map(static fn (string $c): string => '<div class="kbb-eblock__col">' . $c . '</div>', $children))
                . '</div>';
        }

        return implode('', $children);
    }

    /**
     * A flexbox container lays its children side by side when its direction is
     * a row, and a grid container always does. Elementor's default direction
     * for a container is a column, so an unset one is a stack.
     *
     * @param  array<string, mixed>  $settings
     */
    private static function isRowContainer(array $settings): bool
    {
        if (($settings['container_type'] ?? null) === 'grid') {
            return true;
        }

        $direction = $settings['flex_direction'] ?? null;

        return is_string($direction) && str_starts_with($direction, 'row');
    }

    /** @param array<string, mixed> $settings */
    private static function hiddenOnDesktop(array $settings): bool
    {
        $hide = $settings['hide_desktop'] ?? '';

        return is_string($hide) && $hide !== '';
    }

    /** @param array<string, mixed> $s */
    private function widget(string $type, array $s): string
    {
        return match ($type) {
            'heading' => $this->heading($s),
            'image-box' => $this->imageBox($s),
            'image' => $this->image($s),
            'text-editor' => $this->textEditor($s),
            'icon-list' => $this->iconList($s),
            'divider' => '<hr class="kbb-eblock__rule">',
            'spacer' => '',
            default => $this->unknownWidget($type),
        };
    }

    private function unknownWidget(string $type): string
    {
        $key = $type === '' ? '(none)' : $type;
        $this->unknown[$key] = ($this->unknown[$key] ?? 0) + 1;

        return '';
    }

    /** @param array<string, mixed> $s */
    private function heading(array $s): string
    {
        $title = self::html($s['title'] ?? '');

        if (RichText::isBlank($title)) {
            return '';
        }

        $size = is_string($s['header_size'] ?? null) ? strtolower($s['header_size']) : '';
        $tag = in_array($size, ['h2', 'h3', 'h4'], true) ? $size : 'h3';

        $title = self::linked($title, $s['link'] ?? null);

        return '<' . $tag . ' class="kbb-eblock__heading' . self::alignClass($s) . '">' . $title . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $s */
    private function imageBox(array $s): string
    {
        $title = self::html($s['title_text'] ?? '');
        $text = self::html($s['description_text'] ?? '');
        $url = self::imageUrl($s['image'] ?? null);

        if (RichText::isBlank($title) && RichText::isBlank($text) && $url === null) {
            return '';
        }

        $alt = self::imageAlt($s['image'] ?? null) ?? RichText::toText($title);
        [$w, $h] = self::dimensions($url, $s) ?? [self::ITEM_IMAGE_SIZE, self::ITEM_IMAGE_SIZE];

        $out = '<div class="kbb-eblock__item' . (($s['position'] ?? '') === 'top' ? ' kbb-eblock__item--top' : '') . '">';

        if ($url !== null) {
            $out .= self::linked(self::img($url, $alt, $w, $h, 'kbb-eblock__img'), $s['link'] ?? null);
        }

        $out .= '<div class="kbb-eblock__body">';

        if (! RichText::isBlank($title)) {
            $out .= '<h4 class="kbb-eblock__title">' . self::linked($title, $s['link'] ?? null) . '</h4>';
        }

        if (! RichText::isBlank($text)) {
            $out .= '<div class="kbb-eblock__text">' . $text . '</div>';
        }

        return $out . '</div></div>';
    }

    /** @param array<string, mixed> $s */
    private function image(array $s): string
    {
        $url = self::imageUrl($s['image'] ?? null);

        if ($url === null) {
            return '';
        }

        $caption = self::html(($s['caption_source'] ?? '') === 'custom' ? ($s['caption'] ?? '') : '');
        $alt = self::imageAlt($s['image'] ?? null) ?? RichText::toText($caption);
        $dims = self::dimensions($url, $s);

        $link = ($s['link_to'] ?? '') === 'file' ? ['url' => $url] : (($s['link_to'] ?? '') === 'custom' ? ($s['link'] ?? null) : null);

        return '<figure class="kbb-eblock__figure' . self::alignClass($s) . '">'
            . self::linked(self::img($url, $alt, $dims[0] ?? null, $dims[1] ?? null, 'kbb-eblock__pic'), $link)
            . (RichText::isBlank($caption) ? '' : '<figcaption>' . $caption . '</figcaption>')
            . '</figure>';
    }

    /** @param array<string, mixed> $s */
    private function textEditor(array $s): string
    {
        $html = RichText::forDisplay(is_string($s['editor'] ?? null) ? $s['editor'] : '');

        if (RichText::isBlank($html) && ! str_contains($html, '<img')) {
            return '';
        }

        return '<div class="kbb-eblock__copy' . self::alignClass($s) . '">' . $html . '</div>';
    }

    /** @param array<string, mixed> $s */
    private function iconList(array $s): string
    {
        $items = '';

        foreach (is_array($s['icon_list'] ?? null) ? $s['icon_list'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = self::html($item['text'] ?? '');

            if (! RichText::isBlank($text)) {
                $items .= '<li>' . self::linked($text, $item['link'] ?? null) . '</li>';
            }
        }

        return $items === '' ? '' : '<ul class="kbb-eblock__list">' . $items . '</ul>';
    }

    /* ------------------------------------------------------------ helpers */

    /**
     * A string setting as HTML. Elementor stores titles and descriptions as
     * HTML (a `<br>` in a heading is common), so it is NOT escaped here -- it
     * is cleaned, with the whole block, on the way out.
     */
    private static function html(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param array<string, mixed> $s */
    private static function alignClass(array $s): string
    {
        return ($s['align'] ?? null) === 'center' ? ' kbb-eblock--center' : '';
    }

    private static function imageUrl(mixed $image): ?string
    {
        $url = is_array($image) && is_string($image['url'] ?? null) ? trim($image['url']) : '';

        return $url === '' ? null : $url;
    }

    private static function imageAlt(mixed $image): ?string
    {
        $alt = is_array($image) && is_string($image['alt'] ?? null) ? trim($image['alt']) : '';

        return $alt === '' ? null : $alt;
    }

    /**
     * The picture's size, when anything says it.
     *
     * WordPress names every intermediate size `name-300x300.jpg`, and that is
     * the file Elementor points at when a size other than Full was chosen; an
     * explicit custom dimension wins over the name. Without either, null --
     * the caller decides, because an image-box draws a fixed square anyway and
     * a full-width picture does not.
     *
     * @param  array<string, mixed>  $s
     * @return array{0: int, 1: int}|null
     */
    private static function dimensions(?string $url, array $s): ?array
    {
        $custom = $s['image_custom_dimension'] ?? null;

        if (is_array($custom) && (int) ($custom['width'] ?? 0) > 0 && (int) ($custom['height'] ?? 0) > 0) {
            return [(int) $custom['width'], (int) $custom['height']];
        }

        if ($url !== null && preg_match('/-(\d{2,4})x(\d{2,4})\.(?:jpe?g|png|gif|webp|avif)(?:[?#].*)?$/i', $url, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        return null;
    }

    private static function img(string $url, string $alt, ?int $w, ?int $h, string $class): string
    {
        return '<img class="' . $class . '" src="' . e($url) . '" alt="' . e($alt) . '"'
            . ($w !== null && $h !== null ? ' width="' . $w . '" height="' . $h . '"' : '')
            . ' loading="lazy">';
    }

    /** Wrap in a link when the setting carries a URL; clean() scheme-checks it. */
    private static function linked(string $html, mixed $link): string
    {
        $url = is_array($link) && is_string($link['url'] ?? null) ? trim($link['url']) : '';

        if ($url === '') {
            return $html;
        }

        return '<a href="' . e($url) . '">' . $html . '</a>';
    }
}
