<?php

declare(strict_types=1);

namespace App\Services\Import\Entities;

use App\Models\Category;
use App\Services\Import\ImportContext;
use App\Services\Import\Row;
use App\Services\Import\RowRejected;
use App\Services\Import\SlugGuard;
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
 * A parent term id that never appears in the file is NOT an error that stops
 * the category being imported: a partial export of one branch is a legitimate
 * thing to run. It is a note in the report, and the category lands at the root
 * where the owner will see it.
 */
final class CategoryImporter extends EntityImporter
{
    /** @var array<int, int> source_term_id => parent source_term_id, filled during the row pass */
    private array $pendingParents = [];

    public function name(): string
    {
        return 'categories';
    }

    public function conventionalFile(): string
    {
        return 'categories.csv';
    }

    public function import(Row $row, ImportContext $context): void
    {
        $termId = $row->requireId('term_id', 'term_id', 'id', 'category_id');
        $name = $row->requireText('name', 'name', 'title');

        $slug = $row->text('slug') ?? Str::slug($name);

        if ($slug === '') {
            throw RowRejected::because("name '".$name."' does not reduce to a usable slug");
        }

        $parentTermId = $row->id('parent', 'parent', 'parent_id', 'parent_term_id');

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
            'description' => $row->text('description'),
            'image' => $row->text('image', 'thumbnail'),
            'position' => $row->int((int) ($category->position ?? 0), 'position', 'menu_order', 'order'),
        ]);

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
