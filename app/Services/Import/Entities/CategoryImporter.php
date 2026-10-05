<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Category;
use App\Services\Import\ImportContext;
use App\Services\Import\OldSiteLinks;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\Import\SlugGuard;
use App\Support\TitleHeader;
use Illuminate\Support\Str;

/**
 * WooCommerce `product_cat` terms.
 *
 * Matched on `source_term_id`, which is unique. Never on the slug: slugs get
 * edited in wp-admin between the full import and the cutover delta, and an
 * importer matching on one would file the edited category as a brand new row
 * and orphan every product pointing at the old one.
 *
 * TWO PASSES, BECAUSE OF depth AND path. Production nests product_cat four
 * levels deep, and this schema CACHES the resulting URL path in
 * `categories.path` rather than computing it — so a category imported without
 * it has no URL, and the four-level nesting simply does not render. The path of
 * a child cannot be known until its parent exists, and a CSV export is in term
 * order, not tree order, so a child very often arrives first.
 *
 * Handled by doing the obvious thing in the obvious order: the row pass writes
 * every category with its parent's TERM id resolved where it can be and null
 * where it cannot, and finalise() then walks the whole table once, links any
 * parent that arrived late, and recomputes depth and path from the tree that is
 * now complete. finalise() is idempotent and cheap — a few hundred rows — so an
 * interrupted run that resumes and finishes still gets a correct tree.
 *
 * ▲ THAT LAST SENTENCE WAS NOT TRUE UNTIL alreadyCommitted() EXISTED. In
 * practice the row pass writes NO parent at all -- every link waits in
 * $pendingParents for finalise() -- and that list is process memory. A
 * process killed mid-file took the links of every category it had committed
 * with it, and the resumed process, which skips those rows, never knew them.
 * Lane KR measured it with kill -9: docs/KR-KILL-AND-RESUME.md.
 *
 * A parent term id that never appears in the file is NOT an error that stops
 * the category being imported: a partial export of one branch is a legitimate
 * thing to run. It is a note in the report, and the category lands at the root
 * where the owner will see it.
 */
final class CategoryImporter extends EntityImporter
{
    /** @var array<int, int> source_term_id => parent source_term_id, filled during the row pass */
    private array $pendingParents = [];

    /** The old-site link ledger, replayed onto the description (Lane PT). */
    private ?OldSiteLinks $links = null;

    private function links(): OldSiteLinks
    {
        return $this->links ??= new OldSiteLinks;
    }

    public function name(): string
    {
        return 'categories';
    }

    public function conventionalFile(): string
    {
        return 'categories.csv';
    }

    /** Categories the export supplied, demo-catalogue placeholders excluded by the same rule as brands. */
    public function countImported(): ?int
    {
        return Category::query()->whereNotNull('source_term_id')->count();
    }

    public function import(Row $row, ImportContext $context): void
    {
        [$termId, $name, $slug, $parentTermId] = $this->identity($row);

        $category = Category::query()->where('source_term_id', $termId)->first();

        if ($category === null) {
            $adopt = SlugGuard::resolve(
                Category::query(), 'categories', 'source_term_id', $slug, $termId, $context, $this->name(),
            );

            $category = $adopt === null ? new Category : Category::query()->findOrFail($adopt);
        }

        $outcome = $context->apply($category, [
            'source_term_id' => $termId,
            'slug' => $slug,
            'name' => $name,
            'description' => $this->links()->replay('categories', $category->id, 'description', TitleHeader::importDescription($row->text('description'))),
            'image' => $row->text('image', 'thumbnail'),
            'position' => $row->int((int) ($category->position ?? 0), 'position', 'menu_order', 'order'),
        ] + TitleHeader::importColumns($row));

        $context->record($this->name(), $outcome);
        $context->remember($this->name(), $termId, (int) $category->id);

        if ($parentTermId !== null) {
            // Resolved in finalise(), because the parent may be later in the file.
            $this->pendingParents[$termId] = $parentTermId;
        } elseif ($category->parent_id !== null) {
            // The export says this term is now top-level; honour that rather
            // than leaving a stale link from a previous pass.
            $category->parent_id = null;
            $category->save();
        }
    }

    /**
     * A category an earlier, killed process committed: put its parent link back
     * on the list finalise() walks.
     *
     * Every link is deferred to finalise() -- even one whose parent is already
     * in -- so a category committed by a process that died before finalise()
     * had NO parent until this existed, and the resumed process never re-reads
     * it. Measured at full volume: killed after 10 of 59 rows, resumed, 9
     * categories at the top level with the wrong depth and path, and the menu
     * items built from those paths pointing at the wrong addresses. See
     * EntityImporter::alreadyCommitted().
     *
     * The same three checks import() makes before it writes anything, through
     * the same method, so a row refused by the earlier pass is refused here too
     * (the runner ignores the refusal) and is not linked by the resume when an
     * uninterrupted run would not have linked it. A NEW category that passes
     * them and is still refused further on (SlugGuard) has no row, and
     * finalise() skips a term id it cannot resolve.
     */
    public function alreadyCommitted(Row $row, ImportContext $context): void
    {
        [$termId, , , $parentTermId] = $this->identity($row);

        if ($parentTermId !== null) {
            $this->pendingParents[$termId] = $parentTermId;
        }
    }

    /**
     * @return array{0: int, 1: string, 2: string, 3: int|null} term id, name, slug, parent term id
     *
     * @throws RowRejected
     */
    private function identity(Row $row): array
    {
        $termId = $row->requireId('term_id', 'term_id', 'id', 'category_id');
        $name = $row->requireText('name', 'name', 'title');

        $slug = $row->text('slug') ?? Str::slug($name);

        if ($slug === '') {
            throw RowRejected::because("name '".$name."' does not reduce to a usable slug");
        }

        // Stored as the text a shopper reads: WordPress hands the name over
        // HTML-encoded ("Hydration &amp; Glow"). The slug above is still taken
        // from what came, so no URL moves. See App\Support\TermName. (Lane FP)
        return [$termId, \App\Support\TermName::plain($name), $slug, $row->id('parent', 'parent', 'parent_id', 'parent_term_id')];
    }

    /**
     * Link the parents, then recompute the cached depth and path for every row.
     *
     * Deliberately over the WHOLE table and not just the rows this run touched.
     * Moving one category re-parents its entire subtree, and a path left stale
     * on a descendant is a 404 on a live URL — the cheapest possible bug to
     * avoid and an expensive one to find, because the category page itself looks
     * fine and only the link into it is wrong.
     */
    public function finalise(ImportContext $context): void
    {
        foreach ($this->pendingParents as $termId => $parentTermId) {
            $childId = $context->localId('categories', $termId);
            $parentId = $context->localId('categories', $parentTermId);

            if ($childId === null) {
                continue;
            }

            if ($parentId === null) {
                $context->report->for($this->name())->note(
                    'parent term '.$parentTermId.' is not in this export; the child was imported at the top level'
                );

                continue;
            }

            if ($childId === $parentId) {
                $context->report->for($this->name())->note(
                    'term '.$termId.' lists itself as its own parent; imported at the top level'
                );

                continue;
            }

            Category::query()->whereKey($childId)->update(['parent_id' => $parentId]);
        }

        $this->pendingParents = [];

        $this->recomputeTree($context);
    }

    /**
     * Recompute `depth` and `path` breadth-first from the roots.
     *
     * Breadth-first rather than recursive-with-a-guard so a cycle — which a
     * hand-edited export can contain — cannot produce infinite recursion. Any
     * node not reached from a root is in a cycle by definition; it is left with
     * its own slug as its path and reported, rather than hanging the import.
     */
    private function recomputeTree(ImportContext $context): void
    {
        /** @var array<int, array{id: int, slug: string, parent_id: int|null, depth: int, path: string|null}> $nodes */
        $nodes = [];
        /** @var array<int, list<int>> $children */
        $children = [];

        foreach (Category::query()->select(['id', 'slug', 'parent_id', 'depth', 'path'])->cursor() as $node) {
            $id = (int) $node->id;
            $nodes[$id] = [
                'id' => $id,
                'slug' => (string) $node->slug,
                'parent_id' => $node->parent_id === null ? null : (int) $node->parent_id,
                'depth' => (int) $node->depth,
                'path' => $node->path === null ? null : (string) $node->path,
            ];
            $children[$node->parent_id === null ? 0 : (int) $node->parent_id][] = $id;
        }

        $queue = [];

        foreach ($children[0] ?? [] as $rootId) {
            $queue[] = [$rootId, 0, ''];
        }

        $seen = [];

        while ($queue !== []) {
            [$id, $depth, $prefix] = array_shift($queue);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            $path = $prefix === '' ? $nodes[$id]['slug'] : $prefix.'/'.$nodes[$id]['slug'];

            if ($nodes[$id]['depth'] !== $depth || $nodes[$id]['path'] !== $path) {
                Category::query()->whereKey($id)->update(['depth' => $depth, 'path' => $path]);
            }

            foreach ($children[$id] ?? [] as $childId) {
                $queue[] = [$childId, $depth + 1, $path];
            }
        }

        $stranded = array_diff(array_keys($nodes), array_keys($seen));

        if ($stranded !== []) {
            $context->report->for($this->name())->note(
                count($stranded).' categories are in a parent cycle and were left without a computed path — '
                .'ids '.implode(', ', array_slice($stranded, 0, 10))
            );
        }
    }

}
