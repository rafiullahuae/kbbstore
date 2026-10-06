<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Lane MG — "Fit mega menus to the site width" (Appearance → Header →
 * Navigation).
 *
 * The owner, over a desktop screenshot of the "All Brands" panel running off
 * the right edge of the screen: "The mega menu columns must be adjusted auto as
 * per the site width, it should [not] go outside in any case, the font size or
 * columns need to be squeezed auto as per the site width, and if in the mega
 * menu there's are more than 4 columns, then it should must start from the most
 * left of the site width, even if the parent menu is anywhere at any position.
 * there should be a nice edge type to show that this mega menu is for this
 * parent item."
 *
 * WHAT WAS WRONG ON THE SHOP. A panel was `inset-inline-start:0` from its own
 * parent and `min(220px × columns, 92vw)` wide. A ten-column brand panel under a
 * mid-row "All Brands" is 2,200px wide asked for and 92vw granted, starting
 * half way along the row — so most of it lay past the window, where
 * `.mbar{overflow-x:clip}` (2.60.369, there so a CLOSED panel cannot widen the
 * page) cut it off. Nothing in the stylesheet knew about the site's width.
 *
 * WHAT THIS CLASS DECIDES, AND NOTHING ELSE: four class names and three
 * integers/px for the stylesheet, from the settings the bar already read
 * (HeaderSettings::all(), once, in partials/nav-bar.blade.php). Every
 * placement, squeeze and wrap is CSS, rendered once — no script measures
 * anything (the project forbids layout-measuring JavaScript by name).
 *
 *   mg-l   a mega panel with MORE columns than "Start from the left beyond":
 *          it starts at the left content edge of the site container, whatever
 *          the parent's position, and reaches at least to under its parent.
 *   mg-a   a 2-to-N-column panel: it stays with its parent and slides only as
 *          far as it must to stay inside the container.
 *   mg-s   a single-column dropdown: today's placement, held inside the
 *          container by the same slide.
 *   mg-p   the pointer — a short pink line with a small point on the
 *          panel's top edge, under the hovered item, so a panel that starts
 *          at the far left still says whose it is.
 *
 * With the switch OFF every method answers '' or null and the bar prints
 * exactly the bytes it printed before this lane (MegaMenuFitTest pins it).
 *
 * Every value that can reach a `style` attribute is an integer from a fixed
 * list, or one of four fixed px strings — never the stored string itself.
 */
final class MegaMenuFit
{
    /** The design column width today's panels use (kbb.css: 220px × columns). */
    public const COLUMN = 220;

    /** "Smallest column width", px. */
    public const COL_MIN = [100, 110, 120, 130, 140, 150, 160, 180];

    /** "Smallest text size", px — the only non-integers, and only these. */
    public const TEXT_MIN = ['10' => '10px', '10.5' => '10.5px', '11' => '11px', '11.5' => '11.5px', '12' => '12px', '12.5' => '12.5px', '13' => '13px'];

    /** "Start from the left beyond", columns. */
    public const LEFT_FROM = [2, 3, 4, 5, 6, 7, 8];

    private function __construct(
        private int $leftFrom,
        private int $colMin,
        private string $textMin,
        private bool $pointer,
    ) {}

    /**
     * Null when the switch is off — the partial then prints today's bar.
     *
     * @param  array<string, mixed>  $settings  HeaderSettings::all()
     */
    public static function from(array $settings): ?self
    {
        if (! ($settings['mega_fit'] ?? false)) {
            return null;
        }

        $left = (int) ($settings['mega_left_from'] ?? 4);
        $col = (int) ($settings['mega_col_min'] ?? 130);
        $text = (string) ($settings['mega_text_min'] ?? '11.5');

        return new self(
            in_array($left, self::LEFT_FROM, true) ? $left : 4,
            in_array($col, self::COL_MIN, true) ? $col : 130,
            self::TEXT_MIN[$text] ?? '11.5px',
            (bool) ($settings['mega_pointer'] ?? true),
        );
    }

    /**
     * How a parent's children are laid out: the column count that decides
     * whether it is a mega panel at all, and the chunks it is drawn in. The
     * rule partials/nav-bar.blade.php has always used, moved here unchanged so
     * the bar and itemClass() cannot disagree: a manual count wins outright;
     * otherwise roughly 10 rows per column, rounded up, minimum one column.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: int, 1: list<list<array<string, mixed>>>}
     */
    public static function columns(array $item): array
    {
        $children = array_values((array) ($item['children'] ?? []));
        $childCount = count($children);
        $columnCount = max(1, (int) ($item['columns'] ?? ceil($childCount / 10)));
        $chunkSize = (int) ceil($childCount / $columnCount);

        return [$columnCount, $childCount ? array_chunk($children, max(1, $chunkSize)) : []];
    }

    /**
     * Extra classes for `.navitem`, leading space included — '' for an item
     * with no panel, which keeps it `class="navitem"` exactly.
     *
     * @param  array<string, mixed>  $item
     */
    public function itemClass(array $item): string
    {
        if (empty($item['children'])) {
            return '';
        }

        [$columnCount, $chunks] = self::columns($item);
        $columns = $columnCount > 1 ? count($chunks) : 1;

        $kind = match (true) {
            $columns > $this->leftFrom => 'mg-l',
            $columns > 1 => 'mg-a',
            default => 'mg-s',
        };

        return ' '.$kind.($this->pointer ? ' mg-p' : '');
    }

    /**
     * The `style` for `.navitem` — '' for an item without a mega panel. The
     * column count sits on the ITEM, not only on its panel, because the item's
     * own hover bridges (kbb.css, ::before / ::after) are drawn from the same
     * panel width; the panel keeps its own `--mega-cols:N` exactly as before.
     *
     * @param  array<string, mixed>  $item
     */
    public function itemStyle(array $item): string
    {
        if (empty($item['children'])) {
            return '';
        }

        [$columnCount, $chunks] = self::columns($item);

        if ($columnCount <= 1) {
            return '';
        }

        return '--mega-cols:'.count($chunks).';--mg-min:'.$this->colMin.'px;--mg-fmin:'.$this->textMin;
    }
}
