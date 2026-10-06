<?php

declare(strict_types=1);

namespace App\Services\CategoryHierarchy;

use App\Support\CategoryPath;
use App\Support\CategoryTree;
use Illuminate\Support\Facades\DB;

/**
 * Which parent each of this shop's categories should have, by kbeautybliss.com,
 * and the write that gives it to them. (Lane CH)
 *
 * MATCHING. By slug (WordPress percent-encodes non-ASCII slugs, so both sides
 * are decoded and lower-cased), then by exact name when the name is unique on
 * both sides. Never by anything fuzzier: a wrong match puts a category under
 * the wrong parent, which is worse than leaving it where it is.
 *
 * WHAT IS CHANGED. `categories.parent_id` on matched rows, then the cached
 * depth and path. Nothing else -- not products, not orders, not slugs, not
 * `position`, not category_product (Lane SO's per-category order). Rows with
 * no match are left exactly where they are.
 *
 * ADDRESSES DO NOT MOVE. Before any parent is written, every category whose
 * address is its own slug today is marked short_url, so CategoryTree keeps it
 * at /collections/{slug}/ under its new parent. A row that was already nested
 * (short_url = 0) and moves gets a category_redirects row for its old address,
 * exactly as the Categories screen does when one is dragged.
 *
 * CONFLICTS ARE REFUSED, NOT GUESSED. A change that would close a loop with the
 * rest of the tree is dropped and listed; a parent the source names but this
 * shop does not have leaves the child where it is and is listed.
 */
final class HierarchyPlanner
{
    /**
     * @param  list<array{id:int, slug:string, name:string, parent:int}>  $nodes
     * @return array<string, mixed>
     */
    public function plan(array $nodes): array
    {
        $local = DB::table('categories')
            ->select('id', 'slug', 'name', 'parent_id', 'position', 'short_url', 'path')
            ->orderBy('position')->orderBy('name')->orderBy('id')
            ->get();

        $remoteById = [];
        foreach ($nodes as $n) {
            $remoteById[$n['id']] = $n;
        }

        // Local lookups.
        $localBySlug = [];
        $localNameCount = [];
        $localByName = [];
        foreach ($local as $row) {
            $localBySlug[HierarchySource::key((string) $row->slug)] = (int) $row->id;
            $nk = self::nameKey((string) $row->name);
            $localNameCount[$nk] = ($localNameCount[$nk] ?? 0) + 1;
            $localByName[$nk] = (int) $row->id;
        }
        $remoteNameCount = [];
        foreach ($nodes as $n) {
            $nk = self::nameKey($n['name']);
            $remoteNameCount[$nk] = ($remoteNameCount[$nk] ?? 0) + 1;
        }

        // remote id => local id
        $match = [];
        $matchedBy = [];
        $taken = [];
        foreach ($nodes as $n) {
            $lid = $localBySlug[HierarchySource::key($n['slug'])] ?? null;
            if ($lid !== null && ! isset($taken[$lid])) {
                $match[$n['id']] = $lid;
                $matchedBy[$lid] = 'slug';
                $taken[$lid] = true;
            }
        }
        foreach ($nodes as $n) {
            if (isset($match[$n['id']])) {
                continue;
            }
            $nk = self::nameKey($n['name']);
            if ($nk === '' || ($localNameCount[$nk] ?? 0) !== 1 || ($remoteNameCount[$nk] ?? 0) !== 1) {
                continue;
            }
            $lid = $localByName[$nk];
            if (! isset($taken[$lid])) {
                $match[$n['id']] = $lid;
                $matchedBy[$lid] = 'name';
                $taken[$lid] = true;
            }
        }

        $current = [];
        $byId = [];
        foreach ($local as $row) {
            $current[(int) $row->id] = $row->parent_id === null ? null : (int) $row->parent_id;
            $byId[(int) $row->id] = $row;
        }

        $desired = $current;
        $conflicts = [];
        $remoteOf = array_flip($match); // local id => remote id

        foreach ($match as $rid => $lid) {
            $rp = $remoteById[$rid]['parent'];

            if ($rp === 0) {
                $desired[$lid] = null;
                continue;
            }

            if ($rp === $rid) {
                $conflicts[] = ['id' => $lid, 'kind' => 'self', 'text' => 'lists itself as its own parent on the source; left where it is'];
                continue;
            }

            if (! isset($remoteById[$rp])) {
                $conflicts[] = ['id' => $lid, 'kind' => 'parent-missing', 'text' => 'its parent is not in the source list; left where it is'];
                continue;
            }

            if (! isset($match[$rp])) {
                $conflicts[] = ['id' => $lid, 'kind' => 'parent-not-here', 'text' => 'its parent "'.$remoteById[$rp]['name'].'" is not in this shop; left where it is'];
                continue;
            }

            $desired[$lid] = $match[$rp];
        }

        // Refuse every change that sits on a loop, until none is left. Each
        // pass reverts at least one changed row, so this ends.
        for ($pass = 0; $pass < 100; $pass++) {
            $loop = self::findCycle($desired);
            if ($loop === []) {
                break;
            }
            $reverted = false;
            foreach ($loop as $id) {
                if ($desired[$id] !== $current[$id]) {
                    $desired[$id] = $current[$id];
                    $conflicts[] = ['id' => $id, 'kind' => 'cycle', 'text' => 'would sit inside its own branch (a loop); left where it is'];
                    $reverted = true;
                }
            }
            if (! $reverted) {
                break; // a loop already in the data; nothing of ours to refuse
            }
        }

        $changes = [];
        $counts = ['get_parent' => 0, 'to_top' => 0, 'already_right' => 0, 'not_on_source' => 0,
            'not_here' => 0, 'conflicts' => 0, 'matched_by_name' => 0, 'total_here' => count($local), 'total_source' => count($nodes)];

        foreach ($current as $id => $parent) {
            if (! isset($remoteOf[$id])) {
                $counts['not_on_source']++;
                continue;
            }
            if (($matchedBy[$id] ?? '') === 'name') {
                $counts['matched_by_name']++;
            }
            if ($desired[$id] === $parent) {
                $counts['already_right']++;
                continue;
            }
            $changes[$id] = $desired[$id];
            $desired[$id] === null ? $counts['to_top']++ : $counts['get_parent']++;
        }

        $notHere = [];
        foreach ($nodes as $n) {
            if (! isset($match[$n['id']])) {
                $notHere[] = ['name' => $n['name'] !== '' ? $n['name'] : $n['slug'], 'slug' => $n['slug']];
            }
        }
        $counts['not_here'] = count($notHere);

        // De-duplicate conflict rows (a row can be refused once per reason).
        $seenConflict = [];
        $conflictRows = [];
        foreach ($conflicts as $c) {
            $k = $c['id'].'|'.$c['kind'];
            if (isset($seenConflict[$k])) {
                continue;
            }
            $seenConflict[$k] = true;
            $conflictRows[] = ['name' => (string) $byId[$c['id']]->name, 'slug' => (string) $byId[$c['id']]->slug, 'text' => $c['text']];
        }
        $counts['conflicts'] = count($conflictRows);

        return [
            'counts' => $counts,
            'changes' => $changes,
            'tree' => $this->treeRows($byId, $desired, $changes, $remoteOf),
            'not_here' => array_slice($notHere, 0, 500),
            'not_on_source' => array_slice(array_values(array_map(
                static fn ($row) => ['name' => (string) $row->name, 'slug' => (string) $row->slug],
                array_filter($byId, static fn ($row) => ! isset($remoteOf[(int) $row->id]))
            )), 0, 500),
            'conflicts' => array_slice($conflictRows, 0, 500),
        ];
    }

    /**
     * Write the plan. Idempotent: a second run finds every row already right
     * and writes nothing.
     *
     * @param  list<array{id:int, slug:string, name:string, parent:int}>  $nodes
     * @return array<string, mixed>
     */
    public function apply(array $nodes): array
    {
        $result = DB::transaction(function () use ($nodes) {
            $plan = $this->plan($nodes);
            $changes = $plan['changes'];

            if ($changes === []) {
                return ['plan' => $plan, 'moved' => 0, 'redirects' => 0, 'frozen' => 0];
            }

            // Freeze today's short addresses before any parent moves.
            $frozen = DB::table('categories')
                ->where('short_url', false)
                ->where(function ($q) {
                    $q->whereColumn('path', 'slug')
                        ->orWhere(fn ($q) => $q->whereNull('parent_id')->where(fn ($q) => $q->whereNull('path')->orWhere('path', '')));
                })
                ->update(['short_url' => true]);

            $before = DB::table('categories')->pluck('path', 'id')->map(fn ($p) => (string) $p)->all();

            foreach ($changes as $id => $parent) {
                DB::table('categories')->where('id', $id)->update(['parent_id' => $parent]);
            }

            CategoryTree::resync();

            $redirects = 0;
            foreach (DB::table('categories')->pluck('path', 'id') as $id => $path) {
                $old = $before[$id] ?? '';
                if ($old !== '' && $old !== (string) $path) {
                    CategoryPath::record($old, (int) $id, 'move');
                    $redirects++;
                }
            }

            return ['plan' => $plan, 'moved' => count($changes), 'redirects' => $redirects, 'frozen' => $frozen];
        });

        CategoryTree::flushCaches();

        return $result;
    }

    /**
     * The resulting tree, depth-first in position order, for the dry run.
     *
     * @param  array<int, object>  $byId
     * @param  array<int, int|null>  $parents
     * @param  array<int, int|null>  $changes
     * @param  array<int, int>  $remoteOf
     * @return list<array{id:int, name:string, slug:string, depth:int, state:string}>
     */
    private function treeRows(array $byId, array $parents, array $changes, array $remoteOf): array
    {
        $kids = [];
        foreach ($byId as $id => $row) {
            $p = $parents[$id];
            $kids[$p !== null && isset($byId[$p]) ? $p : 0][] = $id;
        }

        $out = [];
        $seen = [];
        $stack = array_reverse(array_map(static fn ($id) => [$id, 0], $kids[0] ?? []));

        while ($stack !== [] && count($out) < 3000) {
            [$id, $depth] = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'name' => (string) $byId[$id]->name,
                'slug' => (string) $byId[$id]->slug,
                'depth' => $depth,
                'state' => array_key_exists($id, $changes) ? 'moves' : (isset($remoteOf[$id]) ? 'right' : 'unmatched'),
            ];
            foreach (array_reverse($kids[$id] ?? []) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }

        // Rows on a loop already in the data never hang off a root.
        foreach ($byId as $id => $row) {
            if (! isset($seen[$id]) && count($out) < 3000) {
                $out[] = ['id' => $id, 'name' => (string) $row->name, 'slug' => (string) $row->slug, 'depth' => 0, 'state' => 'unmatched'];
            }
        }

        return $out;
    }

    /**
     * Ids on some loop in the parent map, or [] when there is none.
     *
     * @param  array<int, int|null>  $parents
     * @return list<int>
     */
    private static function findCycle(array $parents): array
    {
        $state = [];
        foreach (array_keys($parents) as $start) {
            if (isset($state[$start])) {
                continue;
            }
            $path = [];
            $onPath = [];
            $node = $start;
            while ($node !== null && array_key_exists($node, $parents)) {
                if (isset($onPath[$node])) {
                    return array_slice($path, $onPath[$node]);
                }
                if (isset($state[$node])) {
                    break;
                }
                $onPath[$node] = count($path);
                $path[] = $node;
                $node = $parents[$node];
            }
            foreach ($path as $p) {
                $state[$p] = true;
            }
        }

        return [];
    }

    private static function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', \App\Support\TermName::plain($name)) ?? ''));
    }
}
