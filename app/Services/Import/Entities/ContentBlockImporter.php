<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Block;
use App\Services\Import\DocumentMediaRewrite;
use App\Services\Import\ElementorToHtml;
use App\Services\Import\EntityReport;
use App\Services\Import\ImportContext;
use App\Services\Import\OldSiteLinks;
use App\Services\Import\MediaRewrite;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Support\GlobalSections;
use App\Support\Shortcodes;
use Illuminate\Support\Str;

/**
 * The old shop's Rey Global Sections, out of `content_blocks.csv` and into
 * Content -> HTML Blocks. (Lane PJ-B)
 *
 * ── WHAT THE OWNER SAW ──────────────────────────────────────────────────────
 *
 * /product/anua-heartleaf-quercetinol-pore-deep-cleansing-foam-150ml/ opened
 * its Description tab with the literal text `[rey_global_section id="18159"]`.
 * On the old site that spot drew a block: "Gentle Yet Effective Ingredients",
 * then three items in a row, each a square picture with a bold uppercase title
 * and a sentence. "He has a lot of products with these blocks." A Global
 * Section is a separate WordPress post built in Elementor; the product only
 * holds a pointer to it, and nothing in this import carried what it pointed at.
 *
 * ── WHAT THIS DOES ──────────────────────────────────────────────────────────
 *
 * One row per section, written to `blocks` and matched on `blocks.wc_id` -- the
 * WordPress post id the shortcode names -- so a re-import updates the block it
 * wrote and can never write a second copy. The Elementor JSON is turned into
 * plain, styled HTML by App\Services\Import\ElementorToHtml; a widget that
 * converter does not know makes the block fall back to the post's plain HTML,
 * and the widget types are named in this report with how often they forced it.
 * Everything stored has been through RichText::clean(), because the block is
 * printed raw inside every product description that names it.
 *
 * The storefront resolves the shortcode at render time
 * (App\Support\GlobalSections), so the products already imported change the
 * moment this runs, without re-importing a single product -- and an edit the
 * owner makes in Content -> HTML Blocks changes every product using it.
 *
 * ── AN EDIT THE OWNER MADE IS KEPT ──────────────────────────────────────────
 *
 * `source_hash` is what the last import wrote. When the row no longer matches
 * it, the owner has changed the block here -- its words, its name, or its
 * status (draft is the off switch) -- and a re-import leaves the row alone and
 * says so, the promise SeoImporter makes about a title he typed.
 *
 * ── PICTURES ────────────────────────────────────────────────────────────────
 *
 * An image-box's picture is a full URL on the old site, exactly as a product
 * description's is. `blocks.content` is in DocumentMediaRewrite::DOCUMENTS and
 * in MediaAudit, so the picture pass on Store -> Import -> Addresses & pictures
 * counts, fetches and re-points these with everything else.
 */
final class ContentBlockImporter extends EntityImporter
{
    /** The old-site link ledger, replayed onto imported copy (Lane PT). */
    private ?OldSiteLinks $links = null;

    /** The longest name the HTML Blocks screen accepts (BlocksApiController). */
    private const NAME_MAX = 120;

    /** Elementor widget types that forced a fallback in THIS run, with counts. */
    private array $unknownWidgets = [];

    /** Rows whose block fell back to plain HTML in this run. */
    private int $fellBack = 0;

    public function name(): string
    {
        return 'content-blocks';
    }

    public function conventionalFile(): string
    {
        return 'content_blocks.csv';
    }

    /** Blocks that came from WordPress; a block the owner wrote here has no wc_id. */
    public function countImported(): ?int
    {
        return Block::query()->whereNotNull('wc_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        $id = $row->requireId('id', 'id', 'post_id', 'ID');
        $report = $context->report->for($this->name());

        $title = $row->text('title', 'post_title');
        $name = Str::limit(trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8')))), self::NAME_MAX, '');

        if ($name === '') {
            $name = 'Global section #' . $id;
        }

        $source = $this->settleSource($row, $report);
        $status = in_array(mb_strtolower($row->text('status', 'post_status') ?? 'publish'), ['publish', 'published'], true)
            ? 'published'
            : 'draft';

        $converted = (new ElementorToHtml)->convert($row->raw('elementor_data'), $row->raw('plain_html', 'post_content'));

        $this->reportConversion($converted, $row, $report);

        $postType = $row->text('post_type');
        $usedBy = $row->list('|', 'referenced_by');

        $block = Block::query()->where('wc_id', $id)->first();

        if ($block !== null && $block->source_hash !== null
            && ! hash_equals($block->source_hash, self::hash((string) $block->name, (string) $block->status, (string) $block->content))) {
            /*
             * KEPT, AND COUNTED AS UNCHANGED -- which is exactly true: this run
             * changed nothing on the row. The discard says what the export
             * carried that is not in the database, so the owner can decide.
             */
            $report->discarded(
                'a block you have edited in Content -> HTML Blocks since it was imported -- your edit is kept and '
                . 'the export\'s version of it is not written',
                $row->line,
                $this->identify($row),
                'content',
                $name . ' -- ' . mb_strlen($converted['html']) . ' characters in the export',
                'your edit, kept',
            );

            $context->record($this->name(), 'unchanged');
            $context->remember($this->name(), $id, (int) $block->id);

            return;
        }

        // The old-site links already re-pointed in this block, re-pointed the
        // same way, so an unchanged export reads unchanged and the fingerprint
        // below matches what OldSiteLinks left behind. (Lane PT)
        $converted['html'] = (string) ($this->links ??= new OldSiteLinks)->replay('blocks', $block?->id, 'content', $converted['html']);

        $block ??= new Block;

        $attributes = [
            'wc_id' => $id,
            'source' => $source,
            'name' => $name,
            'status' => $status,
            'content' => $converted['html'],
            'source_hash' => self::hash($name, $status, $converted['html']),
            'source_modified_at' => $this->modifiedAt($row, $report),
        ];

        if (! $block->exists) {
            $attributes['slug'] = $this->freeSlug($row->text('slug', 'post_name'), $name, $id);
        }

        $outcome = $context->apply($block, $attributes);

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $id, (int) $block->id);

        if ($status === 'draft' && $usedBy !== []) {
            $report->adjusted(
                'a section that is not published on WordPress -- imported as a draft, so the products that name '
                . 'it show nothing in its place until you publish it in Content -> HTML Blocks',
                $row->line,
                $this->identify($row),
                'status',
                (string) ($row->text('status', 'post_status') ?? '(none)') . ($postType !== null ? ' (' . $postType . ')' : ''),
                'draft',
            );
        }
    }

    /**
     * One sentence for the whole run naming every Elementor widget this shop
     * cannot draw yet, with how often it forced a fallback -- the list the
     * next lane extends ElementorToHtml from. And the [kbb_block] render
     * cache is dropped, because an imported block can be placed by slug too.
     */
    public function finalise(ImportContext $context): void
    {
        $report = $context->report->for($this->name());

        if ($this->unknownWidgets !== []) {
            ksort($this->unknownWidgets);

            $named = [];

            foreach ($this->unknownWidgets as $type => $count) {
                $named[] = $type . ' x' . $count;
            }

            $report->note(
                'Elementor widgets this shop cannot draw yet, so ' . $this->fellBack . ' block'
                . ($this->fellBack === 1 ? '' : 's') . ' used the section\'s plain text instead: '
                . implode(', ', $named)
            );
        }

        Shortcodes::flush();
    }

    /**
     * When WordPress last changed the section: `post_modified_gmt`, which is
     * UTC, unlike the other dates in the export (Lane PJ-A). A date this
     * cannot read is named and left empty -- it is a note about the source,
     * and a section must not be refused over it.
     */
    private function modifiedAt(Row $row, EntityReport $report): ?\Carbon\CarbonImmutable
    {
        try {
            return $row->date('modified', 'UTC', 'modified', 'post_modified_gmt');
        } catch (RowRejected $e) {
            $report->discarded(
                'a last-modified date this import cannot read -- the section is imported without it',
                $row->line,
                $this->identify($row),
                'modified',
                (string) $row->raw('modified', 'post_modified_gmt'),
            );

            return null;
        }
    }

    /**
     * Which shortcode places this section on the old site. Only the ones the
     * storefront resolves are stored; anything else is named, and the block is
     * still imported (it can be placed with its [kbb_block] handle).
     */
    private function settleSource(Row $row, EntityReport $report): ?string
    {
        $raw = mb_strtolower((string) ($row->text('shortcode') ?? Block::SOURCE_REY));

        if (in_array($raw, GlobalSections::SHORTCODES, true)) {
            return $raw;
        }

        $report->adjusted(
            'a section placed by a shortcode this shop does not resolve -- the block is imported, and the '
            . 'products that name it keep printing nothing in its place until it is placed with its own handle',
            $row->line,
            $this->identify($row),
            'shortcode',
            Str::limit($raw, 60),
            '(none)',
        );

        return null;
    }

    /**
     * @param  array{html: string, mode: string, unknown: array<string, int>, reason: string|null}  $converted
     */
    private function reportConversion(array $converted, Row $row, EntityReport $report): void
    {
        if ($converted['mode'] === 'elementor') {
            return;
        }

        if ($converted['unknown'] !== []) {
            $this->fellBack++;

            foreach ($converted['unknown'] as $type => $count) {
                $this->unknownWidgets[$type] = ($this->unknownWidgets[$type] ?? 0) + $count;
            }
        }

        $named = implode(', ', array_map(
            static fn (string $type, int $count): string => $type . ' x' . $count,
            array_keys($converted['unknown']),
            array_values($converted['unknown']),
        ));

        $report->adjusted(
            $converted['mode'] === 'empty'
                ? 'a section with nothing this shop can draw -- imported empty, so the products that name it show '
                    . 'nothing in its place'
                : 'a section drawn from its plain text rather than its Elementor layout -- the words and pictures '
                    . 'are there, the columns are not',
            $row->line,
            $this->identify($row),
            'elementor_data',
            (string) $converted['reason'] . ($named !== '' ? ': ' . $named : ''),
            $converted['mode'] === 'empty' ? '(empty)' : 'plain_html',
        );
    }

    /**
     * A handle no other block holds. The export's slug when it is free, so the
     * owner recognises it; with the WordPress id appended when an admin-written
     * block already has it -- never taking a handle his pages already place.
     */
    private function freeSlug(?string $given, string $name, int $id): string
    {
        $base = Str::limit(Str::slug((string) ($given ?: $name)), 100, '');
        $base = trim($base, '-');

        if ($base === '') {
            $base = 'global-section';
        }

        foreach ([$base, $base . '-' . $id] as $candidate) {
            if (! Block::query()->where('slug', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'global-section-' . $id . '-' . Str::lower(Str::random(4));
    }

    /**
     * What the last import wrote, as a fingerprint the picture pass cannot move.
     *
     * EVERY PICTURE ADDRESS IS FOLDED TO ITS UPLOADS PATH FIRST. The picture
     * pass (DocumentMediaRewrite) re-points `https://kbeautybliss.com/wp-content/
     * uploads/x.jpg` at this shop's copy of the same file, which changes the
     * content -- and a fingerprint over the raw bytes would read that as the
     * owner editing the block, so every block whose pictures had been moved
     * would be frozen at its first import for ever. Both spellings of one file
     * fold to `wp-content/uploads/x.jpg`, so only a change a person made moves
     * the fingerprint.
     */
    public static function hash(string $name, string $status, string $content): string
    {
        $map = [];

        foreach (DocumentMediaRewrite::sources($content) as $url) {
            $relative = MediaRewrite::uploadsRelativeTo($url);

            if ($relative !== null && $relative !== $url) {
                $map[$url] = $relative;
            }
        }

        $folded = $map === [] ? $content : DocumentMediaRewrite::replace($content, $map);

        return hash('sha256', $name . "\0" . $status . "\0" . $folded);
    }
}
