<?php

declare(strict_types=1);

namespace App\Services\ImageSeo;

use App\Services\Media\WebpReferences;
use App\Support\ImageRenameRedirect;
use Illuminate\Support\Facades\DB;

/**
 * Renames one product's pictures without a broken image anywhere. (Lane IR)
 *
 * ── THE ORDER OF OPERATIONS IS THE GUARANTEE ───────────────────────────────
 *
 *  1. LINK, DON'T MOVE. Every file — the picture, its WebP twin, every
 *     img-cache width and crop, the share image — gets a hard link (a copy
 *     where the filesystem refuses one) under its new name. Nothing has gone
 *     anywhere yet: the old name still serves the same bytes, so a shopper
 *     loading the page at this instant sees nothing happen. Each new file is
 *     checked to exist with the old file's exact size.
 *
 *  2. ONE TRANSACTION for the database: every allowlisted reference
 *     (WebpReferences — product image, gallery, alt-text keys, descriptions,
 *     variants, pages, posts, banners, settings, emails), the Media Library
 *     row, the WebP ledger, and this module's own ledger with its chains
 *     collapsed. INSIDE the same transaction, before it commits, two checks:
 *       - the same reference scan run again as a dry run must find NOTHING
 *         still naming the old file;
 *       - ImageRenameRedirect must already answer the old path with the new.
 *     Either failing throws, the transaction rolls back, the links from step
 *     1 are removed, and the product is exactly as it was. That picture is
 *     reported as rolled back and the others are tried again without it — the
 *     owner's "roll that image back automatically and report it".
 *
 *  3. ONLY THEN are the old names removed. From here a request for an old name
 *     misses the disk, reaches Laravel's 404 handler and gets a 301 to the new
 *     one in one hop.
 *
 * Atomic per picture, from the shop's point of view: at every instant each
 * address the shop prints answers with the picture.
 *
 * Callers hold ImageSeoJobs' lock; this class does not take one itself.
 */
final class ImageRenamer
{
    /**
     * @param  array<string, mixed>  $productPlan  one entry from ImageSeoPlanner::plan()
     * @param  array{job_id?: int|null, admin_id?: int|null, admin_name?: string|null, role?: string|null}  $context
     * @return list<array{rel: string, to: string|null, status: string, reason: string|null, refs: int, files: int}>
     */
    public function renameProduct(array $productPlan, array $context = []): array
    {
        $work = array_values(array_filter($productPlan['images'], static fn ($p) => in_array($p['action'], ['rename', 'repoint'], true)));
        $results = [];

        // Up to as many attempts as pictures: each failed check takes the
        // picture it names out and the rest go again.
        for ($attempt = 0; $work !== [] && $attempt <= count($productPlan['images']); $attempt++) {
            $outcome = $this->attempt((int) $productPlan['id'], $work, $context);

            if ($outcome['failed'] === null) {
                return array_merge($results, $outcome['done']);
            }

            $results[] = $outcome['failed'];
            $work = array_values(array_filter($work, static fn ($p) => $p['rel'] !== $outcome['failed']['rel']));
        }

        return $results;
    }

    /**
     * @return array{done: list<array>, failed: array|null}
     */
    private function attempt(int $productId, array $work, array $context): array
    {
        $links = [];      // new absolute files this attempt created
        $old = [];        // old absolute files to remove after commit
        $moves = [];      // per picture: [rel, to, map, files]

        try {
            foreach ($work as $image) {
                $rel = (string) $image['rel'];
                $to = (string) $image['proposed_rel'];

                if ($image['action'] === 'repoint') {
                    $moves[] = ['image' => $image, 'map' => [$rel => $to], 'files' => 0, 'siblings' => []];

                    continue;
                }

                $this->guard($rel, $to, ! empty($image['trusted']));

                $pairs = [['from' => $rel, 'to' => $to]];
                $siblings = ImageFiles::siblings($rel, $to);

                foreach ($siblings as $s) {
                    $this->guard($s['from'], $s['to'], ! empty($image['trusted']));
                    $pairs[] = ['from' => $s['from'], 'to' => $s['to']];
                }

                foreach (array_slice($pairs, 0) as $pair) {
                    foreach (ImageFiles::derived($pair['from'], $pair['to']) as $d) {
                        $pairs[] = $d + ['derived' => true];
                    }
                }

                foreach ($pairs as $pair) {
                    $from = public_path($pair['from']);
                    $dest = public_path($pair['to']);

                    if (! empty($pair['derived']) && is_file($dest)) {
                        // A stale cached copy under the new name: the cache is
                        // a function of the original, so it is replaced.
                        @unlink($dest);
                    }

                    $this->link($from, $dest, $pair['from']);
                    $links[] = $dest;
                    $old[] = $from;
                }

                $map = [$rel => $to];

                foreach ($siblings as $s) {
                    $map[$s['from']] = $s['to'];
                }

                $moves[] = ['image' => $image, 'map' => $map, 'files' => count($pairs), 'siblings' => $siblings];
            }

            $done = DB::transaction(function () use ($productId, $moves, $context): array {
                $map = [];

                foreach ($moves as $m) {
                    $map += $m['map'];
                }

                $applied = WebpReferences::apply($map, true);
                $done = [];

                foreach ($moves as $m) {
                    $rel = (string) $m['image']['rel'];
                    $to = (string) $m['image']['proposed_rel'];

                    foreach ($m['map'] as $from => $dest) {
                        $this->moveLibraryRow($from, $dest, $from === $rel);
                        $this->moveConversion($from, $dest);
                        $this->ledger($from, $dest, $productId, $m['image'], $context, $from === $rel ? $m['files'] : 0, (int) ($applied['paths'][$from] ?? 0), $from === $rel ? null : 'sibling');
                    }

                    $done[] = ['rel' => $rel, 'to' => $to, 'status' => $m['image']['action'] === 'repoint' ? 'repointed' : 'renamed', 'reason' => null,
                        'refs' => (int) ($applied['paths'][$rel] ?? 0), 'files' => $m['files']];
                }

                // The checks, before anything is committed.
                $left = WebpReferences::apply($map, false);

                foreach ($moves as $m) {
                    foreach ($m['map'] as $from => $dest) {
                        if (($left['paths'][$from] ?? 0) > 0) {
                            throw new RenameRefused($from, 'something still pointed at the old name after the update ('.implode(', ', array_keys($left['columns'])).')');
                        }

                        if (ImageRenameRedirect::resolvePath($from) !== $dest) {
                            throw new RenameRefused($from, 'the old address would not have redirected to the new one');
                        }
                    }
                }

                return $done;
            });
        } catch (RenameRefused $e) {
            $this->undoLinks($links);
            $image = $this->imageFor($work, $e->path);

            return ['done' => [], 'failed' => ['rel' => (string) $image['rel'], 'to' => $image['proposed_rel'], 'status' => 'rolled_back', 'reason' => $e->reason, 'refs' => 0, 'files' => 0]];
        } catch (\Throwable $e) {
            report($e);
            $this->undoLinks($links);
            $image = $work[0];

            foreach ($work as $candidate) {
                if (str_contains($e->getMessage(), (string) $candidate['rel'])) {
                    $image = $candidate;
                }
            }

            return ['done' => [], 'failed' => ['rel' => (string) $image['rel'], 'to' => $image['proposed_rel'], 'status' => 'rolled_back',
                'reason' => 'nothing was changed: '.mb_substr($e->getMessage(), 0, 160), 'refs' => 0, 'files' => 0]];
        }

        // Committed: the old names go. A file that will not unlink is left —
        // it still holds the right picture, so nothing breaks either way.
        foreach (array_unique($old) as $file) {
            if (! in_array($file, $links, true)) {
                @unlink($file);
            }
        }

        return ['done' => $done, 'failed' => null];
    }

    /**
     * Refuse anything that is not a plain rename inside one upload directory.
     * $trusted is Undo, whose target is the ledger's own record of the name
     * the file had (IMG_1234.JPG is not a slug, and is still the right answer).
     */
    private function guard(string $from, string $to, bool $trusted = false): void
    {
        $stem = (string) pathinfo($to, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($to, PATHINFO_EXTENSION));

        if (ImageFiles::local($from) !== $from || ImageFiles::local($to) !== $to) {
            throw new RenameRefused($from, 'not a picture in an upload folder of this shop');
        }

        if (\dirname($from) !== \dirname($to) || (! $trusted && ! ImageNamer::valid($stem)) || $extension !== strtolower(pathinfo($from, PATHINFO_EXTENSION))) {
            throw new RenameRefused($from, 'the new name is not a valid name in the same folder');
        }

        if (ImageFiles::absolute($from) === null) {
            throw new RenameRefused($from, 'the file is missing from the server');
        }

        if (file_exists(public_path($to))) {
            throw new RenameRefused($from, 'a file called '.basename($to).' is already there');
        }
    }

    private function link(string $from, string $dest, string $rel): void
    {
        $dir = \dirname($dest);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RenameRefused($rel, 'could not create '.basename($dir));
        }

        if (! @link($from, $dest) && ! @copy($from, $dest)) {
            throw new RenameRefused($rel, 'could not write the new file (disk full or no permission?)');
        }

        clearstatcache(true, $dest);

        if (! is_file($dest) || ! is_readable($dest) || filesize($dest) !== filesize($from)) {
            @unlink($dest);

            throw new RenameRefused($rel, 'the new file did not read back the same size');
        }
    }

    private function undoLinks(array $links): void
    {
        foreach (array_reverse($links) as $file) {
            @unlink($file);
        }
    }

    private function moveLibraryRow(string $from, string $to, bool $main): void
    {
        $update = ['path' => $to, 'filename' => basename($to), 'updated_at' => now()];

        if ($main && ImageSeoPlanner::hasScore()) {
            $update['seo_renamed_at'] = now();
        }

        // A row already at the new path (should not exist) is left alone
        // rather than duplicated.
        if (DB::table('media')->where('path', $to)->exists()) {
            return;
        }

        DB::table('media')->where('path', $from)->update($update);
    }

    private function moveConversion(string $from, string $to): void
    {
        if (! ImageFiles::hasTable('webp_conversions')) {
            return;
        }

        DB::table('webp_conversions')->where('from_path', $from)->update(['from_path' => $to, 'updated_at' => now()]);
        DB::table('webp_conversions')->where('to_path', $from)->update(['to_path' => $to, 'updated_at' => now()]);
    }

    /**
     * Write the ledger row and keep every redirect one hop:
     *   - every row that pointed AT $from now points at $to (A→B, B→C ⇒ A→C);
     *   - a row whose old name is $to is retired, because a real file is there now;
     *   - nothing ever points at itself.
     */
    private function ledger(string $from, string $to, int $productId, array $image, array $context, int $files, int $refs, ?string $role): void
    {
        $now = now();

        DB::table('image_renames')->where('status', 'done')->where('new_path', $from)->update(['new_path' => $to, 'updated_at' => $now]);
        DB::table('image_renames')->where('status', 'done')->where('old_path', $to)
            ->update(['status' => ($context['role'] ?? null) === 'undo' ? 'undone' : 'superseded', 'updated_at' => $now]);
        DB::table('image_renames')->where('status', 'done')->whereColumn('old_path', 'new_path')->update(['status' => 'superseded', 'updated_at' => $now]);

        if ($image['action'] === 'repoint' && DB::table('image_renames')->where('status', 'done')->where('old_path', $from)->where('new_path', $to)->exists()) {
            return;
        }

        DB::table('image_renames')->insert([
            'job_id' => $context['job_id'] ?? null,
            'product_id' => $productId ?: null,
            'media_id' => $image['media_id'] ?? null,
            'role' => $role ?? ($context['role'] ?? ($image['action'] === 'repoint' ? 'repoint' : (string) $image['role'])),
            'old_path' => $from,
            'new_path' => $to,
            'status' => 'done',
            'refs' => $refs,
            'files' => $files,
            'admin_id' => $context['admin_id'] ?? null,
            'admin_name' => isset($context['admin_name']) ? mb_substr((string) $context['admin_name'], 0, 120) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function imageFor(array $work, string $path): array
    {
        foreach ($work as $image) {
            if ($image['rel'] === $path) {
                return $image;
            }
        }

        // A sibling's path: the picture it belongs to.
        foreach ($work as $image) {
            if (\dirname((string) $image['rel']) === \dirname($path)
                && pathinfo((string) $image['rel'], PATHINFO_FILENAME) === pathinfo($path, PATHINFO_FILENAME)) {
                return $image;
            }
        }

        return $work[0];
    }
}
