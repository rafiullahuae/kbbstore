<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Media;
use App\Models\Product;
use App\Services\ImageSeo\ImageFiles;
use App\Services\Mail\Kit\MailImage;
use App\Support\ImageVariants;
use App\Support\MediaRegistrar;
use App\Support\ShareImage;
use App\Support\SiteUrl;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A product picture the owner replaced or removed leaves the server — safely.
 * (Lane RPL)
 *
 * The owner: "if any product has 5 pictures, and 2 of them i replaced which has
 * more text on image ... the old pictures should get deleted from the server
 * ... the goal is to remove the texty images from the server."
 *
 * ── ONLY THROUGH A SAVE OF THAT PRODUCT ────────────────────────────────────
 * afterSave() is called by ProductEditorApiController::save() and nothing
 * else. Its candidates are the pictures THAT product held before the save and
 * does not hold after it — never a path a request names. undo() and the purge
 * act on rows afterSave() wrote, and nothing else can write them.
 *
 * ── NEVER A PICTURE ANYTHING STILL USES ────────────────────────────────────
 * usedBy() is WebpReferences' walk — the allowlist "Remove originals" already
 * trusts (products, variants, swatches, brands, categories, tabs, banners,
 * Spotted, menus, blocks, pages, the Journal, email and marketing templates,
 * settings, translations) plus its read-only tables (reviews, shoppable
 * video, Instagram, sent campaigns). Any hit and the picture is KEPT, and the
 * editor says by whom. The purge asks again before it deletes, and a picture
 * that picked up a user meanwhile goes back where it was instead.
 *
 * ── TRASH, NOT DELETE ──────────────────────────────────────────────────────
 *   save   the original (and its kept-JPEG twin) MOVE to
 *          storage/app/picture-trash/<id>/ — outside the web root, so the old
 *          address stops answering at once. Its img-cache widths, crops, share
 *          card and email JPEGs are deleted: they are caches, Undo remakes
 *          them. The Media Library row is stashed in the trash row.
 *   undo   moves it back, restores the row, remakes the copies, puts it back
 *          in its slot on the product with its description.
 *   +30d   purge: asks usedBy() again, then unlinks. Runs from the scheduler
 *          (kbb:picture-trash-purge, daily) and, for a shop whose cron is not
 *          set up, at most every six hours when the Media Library is opened.
 *
 * ── WHAT THE OLD ADDRESS ANSWERS ───────────────────────────────────────────
 *   replaced  301 to the picture that took its slot, from the moment of the
 *             save: an image_renames row (role `replace`), which
 *             LegacyImageRedirect already follows — the same one hop the
 *             Image SEO rename uses, for the original and every img-cache
 *             copy of it, so Google Images carries the ranking across.
 *   removed   404 while Undo is possible, 410 Gone once purged (respond()).
 *             An email JPEG of it goes to the product's current main picture,
 *             so an old order email still shows the product.
 *
 * ── PATHS ──────────────────────────────────────────────────────────────────
 * Every path is ImageFiles::local() (an upload root, a picture extension, not a
 * customer's folder, no "..") and ImageFiles::absolute() (realpath inside the
 * web root) before it is moved; trash files are named <id>/<basename> and
 * realpath-checked inside the trash root before they are unlinked. Copies go
 * through ImageVariants::forget()/ShareImage::forget(), which never leave
 * img-cache/.
 */
final class PictureTrash
{
    public const DAYS = 30;

    public const TABLE = 'picture_trash';

    /** Under storage_path(): never the web root. */
    public const DIR = 'app/picture-trash';

    /** The ledger role Image SEO's own undo and filters never select. */
    public const LEDGER_ROLE = 'replace';

    private const LAZY_KEY = 'kbb.picture-trash.lazy';

    /** In the trash root: the earliest unix time anything in it is due. */
    private const NEXT_FILE = 'next-due';

    /** What a table is called on the editor's "kept: still used by …" line. */
    private const WORDS = [
        'products' => 'product', 'product_variants' => 'product variant', 'attribute_values' => 'swatch',
        'brands' => 'brand', 'categories' => 'category', 'product_tabs' => 'product tab',
        'banner_cards' => 'banner', 'banner_sets' => 'banner', 'spotted_posts' => 'Spotted post',
        'menu_items' => 'menu', 'blocks' => 'content block', 'pages' => 'page', 'posts' => 'blog post',
        'email_templates' => 'email template', 'mkt_templates' => 'marketing template',
        'mkt_campaigns' => 'marketing email', 'settings' => 'site setting', 'module_settings' => 'site setting',
        'translations' => 'translation', 'reviews' => 'customer review', 'ugc_videos' => 'shoppable video',
        'instagram_posts' => 'Instagram post',
    ];

    /** The column that names a row, where a table has one worth printing. */
    private const NAMES = [
        'products' => 'name', 'brands' => 'name', 'categories' => 'name', 'pages' => 'title',
        'posts' => 'title', 'menu_items' => 'label', 'banner_sets' => 'name', 'mkt_campaigns' => 'name',
        'mkt_templates' => 'name', 'email_templates' => 'name',
    ];

    private const COLUMN_WORDS = [
        'image' => 'main image', 'images' => 'gallery', 'seo' => 'share image', 'description' => 'description',
        'short_description' => 'short description', 'logo' => 'logo', 'cover' => 'cover', 'body' => 'text',
        'content' => 'content', 'blocks' => 'blocks', 'value' => 'setting', 'header_image' => 'header picture',
    ];

    public static function ready(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The product's pictures in slot order: the main image, then the gallery.
     *
     * @return list<array{url: string, slot: string, position: int, alt: string|null}>
     */
    public static function slots(Product $product): array
    {
        $alts = is_array($product->image_alts) ? $product->image_alts : [];
        $out = [];
        $main = trim((string) ($product->image ?? ''));

        if ($main !== '') {
            $out[] = ['url' => $main, 'slot' => 'main', 'position' => 0, 'alt' => self::altOf($alts, $main)];
        }

        foreach (array_values((array) ($product->images ?? [])) as $i => $url) {
            $url = trim((string) $url);

            if ($url !== '') {
                $out[] = ['url' => $url, 'slot' => 'gallery', 'position' => $i, 'alt' => self::altOf($alts, $url)];
            }
        }

        return $out;
    }

    /**
     * After a save: every picture the product held before and not now.
     *
     * @param  list<array{url: string, slot: string, position: int, alt: string|null}>  $before  slots() before the save
     * @param  array<string, string>  $hints  old url => the url that took its slot, from the editor
     * @return array{trashed: list<array<string, mixed>>, kept: list<array<string, mixed>>}
     */
    public static function afterSave(Product $product, array $before, array $hints, ?object $admin = null): array
    {
        $out = ['trashed' => [], 'kept' => []];

        if (! self::ready() || $before === []) {
            return $out;
        }

        $after = self::slots($product);
        $afterRels = [];

        foreach ($after as $s) {
            $rel = ImageFiles::local($s['url']);

            if ($rel !== null && ! isset($afterRels[$rel])) {
                $afterRels[$rel] = $s['url'];
            }
        }

        $beforeRels = [];

        foreach ($before as $s) {
            $rel = ImageFiles::local($s['url']);

            if ($rel !== null && ! isset($beforeRels[$rel])) {
                $beforeRels[$rel] = $s;
            }
        }

        /*
         * What took each slot. The editor says (it knows which thumbnail was
         * clicked); the server only believes a hint whose old picture really
         * left this product and whose new one really is on it. A main image
         * swapped any other way — a drop, the Upload button — is a replacement
         * too: the slot is the same slot.
         */
        $took = [];

        foreach ($hints as $old => $new) {
            $oldRel = ImageFiles::local((string) $old);
            $newRel = ImageFiles::local((string) $new);

            if ($oldRel !== null && $newRel !== null && isset($beforeRels[$oldRel]) && ! isset($afterRels[$oldRel]) && isset($afterRels[$newRel])) {
                $took[$oldRel] = [$newRel, $afterRels[$newRel]];
            }
        }

        $oldMain = ($before[0]['slot'] ?? '') === 'main' ? ImageFiles::local($before[0]['url']) : null;
        $newMain = ($after[0]['slot'] ?? '') === 'main' ? ImageFiles::local($after[0]['url']) : null;

        if ($oldMain !== null && $newMain !== null && ! isset($took[$oldMain]) && ! isset($afterRels[$oldMain]) && ! isset($beforeRels[$newMain])) {
            $took[$oldMain] = [$newMain, $afterRels[$newMain]];
        }

        foreach ($beforeRels as $rel => $slot) {
            if (isset($afterRels[$rel])) {
                continue;
            }

            $result = self::trashOne($product, $slot, $rel, $took[$rel] ?? null, $afterRels, $admin);

            if ($result !== null) {
                $out[$result['state']][] = $result;
            }
        }

        return $out;
    }

    /**
     * "kept: still used by …" — who still writes this picture down, in words.
     *
     * @return list<string>
     */
    public static function usedBy(string $rel): array
    {
        $labels = [];

        foreach (WebpReferences::usedBy($rel, 6) as $hit) {
            $word = self::WORDS[$hit['table']] ?? str_replace('_', ' ', $hit['table']);
            $name = self::nameOf($hit['table'], $hit['id']);
            $column = self::COLUMN_WORDS[$hit['column']] ?? str_replace('_', ' ', $hit['column']);
            $label = $word.($name !== null ? ' “'.$name.'”' : ' #'.$hit['id']).' ('.$column.')';

            if (! in_array($label, $labels, true)) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * The rows still waiting for their 30 days, for one product's editor.
     *
     * @return list<array<string, mixed>>
     */
    public static function pendingFor(int $productId): array
    {
        if (! self::ready()) {
            return [];
        }

        return DB::table(self::TABLE)->where('product_id', $productId)->where('status', 'trashed')
            ->orderByDesc('id')->limit(24)
            ->get(['id', 'slot', 'position', 'old_path', 'replacement_url', 'purge_after', 'created_at'])
            ->map(static fn ($r) => self::view($r))->all();
    }

    /**
     * Undo: the picture goes back on the server and back in its slot.
     *
     * @return array{ok: bool, message: string}
     */
    public static function undo(int $id, Product $product): array
    {
        $row = self::ready() ? DB::table(self::TABLE)->where('id', $id)->first() : null;

        if ($row === null || (int) $row->product_id !== (int) $product->id) {
            return ['ok' => false, 'message' => 'That picture is not in this product’s trash.'];
        }

        if ($row->status !== 'trashed') {
            return ['ok' => false, 'message' => $row->status === 'purged'
                ? 'That picture was removed from the server for good after 30 days.'
                : 'That picture is already back.'];
        }

        $why = self::putBack($row);

        if ($why !== null) {
            return ['ok' => false, 'message' => $why];
        }

        // Back in its slot, with its description.
        $url = (string) $row->old_url;
        $replacement = (string) ($row->replacement_url ?? '');
        $main = trim((string) ($product->image ?? ''));
        $images = array_values(array_filter(array_map('strval', (array) ($product->images ?? []))));
        $onProduct = $main === $url || in_array($url, $images, true);

        if (! $onProduct) {
            if ($row->slot === 'main') {
                if ($main === '' || ($replacement !== '' && $main === $replacement)) {
                    $seo = is_array($product->seo) ? $product->seo : [];

                    // The share image was following the main image: it follows it back.
                    if ($main !== '' && ($seo['og_image'] ?? null) === $main) {
                        $seo['og_image'] = $url;
                        $product->seo = $seo;
                    }

                    $product->image = $url;
                } else {
                    array_unshift($images, $url);
                }
            } else {
                $at = $replacement !== '' ? array_search($replacement, $images, true) : false;

                if ($at !== false) {
                    $images[$at] = $url;
                } else {
                    array_splice($images, min((int) $row->position, count($images)), 0, [$url]);
                }
            }

            $product->images = array_values(array_filter($images, static fn ($u) => $u !== (string) ($product->image ?? '')));
        }

        if (trim((string) ($row->alt ?? '')) !== '') {
            $alts = is_array($product->image_alts) ? $product->image_alts : [];
            $alts[$url] = (string) $row->alt;
            $product->image_alts = $alts;
        }

        $product->save();

        DB::table(self::TABLE)->where('id', $row->id)->update(['status' => 'restored', 'note' => 'undone in the product editor', 'updated_at' => now()]);

        return ['ok' => true, 'message' => basename((string) $row->old_path).' is back on the server and on this product.'];
    }

    /**
     * Every row whose 30 days are up: deleted for good, or — if something
     * started using the picture meanwhile — put back where it was.
     *
     * @return array{purged: int, restored: int}
     */
    public static function purgeDue(int $limit = 100): array
    {
        $out = ['purged' => 0, 'restored' => 0];

        // One query when there is nothing to do (the Media Library's first
        // load pays it): no separate schema check -- a missing table is the
        // same "nothing due" as an empty one.
        try {
            $rows = DB::table(self::TABLE)->where('status', 'trashed')->where('purge_after', '<=', now())
                ->orderBy('id')->limit($limit)->get();
        } catch (\Throwable) {
            return $out;
        }

        foreach ($rows as $row) {
            try {
                $users = [];

                foreach (self::filesOf($row) as $f) {
                    $users = array_merge($users, self::usedBy($f['rel']));
                }

                if ($users !== []) {
                    // A user appeared while it was in the trash: never delete it.
                    if (self::putBack($row) === null) {
                        DB::table(self::TABLE)->where('id', $row->id)->update([
                            'status' => 'restored', 'note' => mb_substr('put back at purge: still used by '.implode(', ', $users), 0, 250), 'updated_at' => now(),
                        ]);
                        $out['restored']++;
                    }

                    continue;
                }

                foreach (self::filesOf($row) as $f) {
                    $file = self::trashFile((string) $f['trash']);

                    if ($file !== null) {
                        @unlink($file);
                    }
                }

                @rmdir(self::root().'/'.(int) $row->id);

                DB::table(self::TABLE)->where('id', $row->id)->update(['status' => 'purged', 'media_rows' => null, 'updated_at' => now()]);
                $out['purged']++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        self::noteDue(null);

        return $out;
    }

    /**
     * Keep the trash folder's "next due" file true: the earliest purge_after
     * still waiting, or no file when nothing waits. $at (a new row's due time)
     * only ever moves it earlier, without a query.
     */
    private static function noteDue(?int $at): void
    {
        $file = self::root().'/'.self::NEXT_FILE;

        try {
            if ($at !== null) {
                $now = @file_get_contents($file);

                if (! is_string($now) || ! ctype_digit(trim($now)) || (int) $now > $at) {
                    @mkdir(self::root(), 0755, true);
                    @file_put_contents($file, (string) $at, LOCK_EX);
                }

                return;
            }

            $next = DB::table(self::TABLE)->where('status', 'trashed')->min('purge_after');

            if ($next === null) {
                @unlink($file);
            } else {
                @file_put_contents($file, (string) \Illuminate\Support\Carbon::parse((string) $next)->getTimestamp(), LOCK_EX);
            }
        } catch (\Throwable) {
            // The scheduler purges regardless; the file only lets the Library help.
        }
    }

    /**
     * The purge for a shop whose cron line is not set up: when someone opens
     * the Media Library, at most once every six hours, and only once something
     * is actually due. Costs NO query until then -- the earliest due time is a
     * file in the trash folder (see noteDue()), so the Library's own query
     * count is what it was. Never throws.
     */
    public static function purgeLazily(): void
    {
        try {
            $due = @file_get_contents(self::root().'/'.self::NEXT_FILE);

            if (! is_string($due) || ! ctype_digit(trim($due)) || (int) $due > now()->getTimestamp()) {
                return;
            }

            if (! Cache::add(self::LAZY_KEY, 1, now()->addHours(6))) {
                return;
            }

            self::purgeDue();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * The 404 handler's answer for a picture this class took off the server
     * with nothing in its slot (a replaced one is LegacyImageRedirect's 301,
     * which runs first): 410 Gone once purged; an email JPEG of it goes to the
     * product's current picture, so an old order email still shows one.
     * Null for everything else. One indexed query, on a 404 under an upload
     * root or img-cache only.
     */
    public static function respond(Request $request): ?Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return null;
        }

        $path = $request->decodedPath();
        $copy = str_starts_with($path, ImageVariants::DIR.'/');

        if (! $copy && ! self::underRoot($path)) {
            return null;
        }

        if (strlen($path) > 600 || str_contains($path, '..') || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return null;
        }

        $candidates = $copy ? self::originalsOf($path) : [$path];

        if ($candidates === [] || ! self::ready()) {
            return null;
        }

        try {
            $row = DB::table(self::TABLE)
                ->where(fn ($q) => $q->whereIn('old_path', $candidates)->orWhereIn('twin_path', $candidates))
                ->whereIn('status', ['trashed', 'purged'])
                ->whereNull('replacement_path')
                ->orderByDesc('id')
                ->first(['id', 'product_id', 'status']);
        } catch (\Throwable) {
            return null;
        }

        if ($row === null) {
            return null;
        }

        if (str_starts_with($path, ImageVariants::DIR.'/'.MailImage::DIR.'/')) {
            $now = self::currentPictureOf((int) $row->product_id);

            if ($now !== null) {
                $encoded = implode('/', array_map('rawurlencode', explode('/', $now)));

                return new RedirectResponse(SiteUrl::origin($request).Url::raw('/'.$encoded), 302);
            }
        }

        if ($row->status !== 'purged') {
            return null;
        }

        return new Response('Gone', 410, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param  array{url: string, slot: string, position: int, alt: string|null}  $slot
     * @param  array{0: string, 1: string}|null  $took  [rel, url] of the picture that took the slot
     * @param  array<string, string>  $afterRels
     * @return array<string, mixed>|null
     */
    private static function trashOne(Product $product, array $slot, string $rel, ?array $took, array $afterRels, ?object $admin): ?array
    {
        if (ImageFiles::local($rel) !== $rel || ImageFiles::absolute($rel) === null) {
            return null;   // nothing of ours on disk to take away
        }

        $twin = null;

        foreach (ImageFiles::siblings($rel, $rel) as $s) {
            if (! isset($afterRels[$s['from']]) && ImageFiles::local($s['from']) === $s['from']) {
                $twin = $s['from'];

                break;
            }
        }

        $base = [
            'old' => basename($rel), 'old_url' => $slot['url'], 'slot' => $slot['slot'], 'position' => $slot['position'],
            'replacement' => $took[1] ?? null,
        ];

        $users = self::usedBy($rel);

        if ($twin !== null) {
            $users = array_values(array_unique(array_merge($users, self::usedBy($twin))));
        }

        if ($users !== []) {
            return $base + ['state' => 'kept', 'by' => $users];
        }

        $now = now();
        $id = (int) DB::table(self::TABLE)->insertGetId([
            'product_id' => (int) $product->id, 'slot' => $slot['slot'], 'position' => (int) $slot['position'],
            'old_path' => $rel, 'twin_path' => $twin, 'old_url' => mb_substr($slot['url'], 0, 500),
            'alt' => $slot['alt'] !== null ? mb_substr($slot['alt'], 0, 250) : null,
            'replacement_path' => $took[0] ?? null, 'replacement_url' => isset($took[1]) ? mb_substr($took[1], 0, 500) : null,
            'files' => '[]', 'status' => 'moving', 'purge_after' => $now->copy()->addDays(self::DAYS),
            'admin_id' => $admin->id ?? null, 'admin_name' => isset($admin->name) ? mb_substr((string) $admin->name, 0, 120) : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $dir = self::root().'/'.$id;
        $moved = [];

        try {
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new \RuntimeException('could not create the trash folder');
            }

            foreach (array_filter([$rel, $twin]) as $r) {
                $from = ImageFiles::absolute($r);

                if ($from === null) {
                    continue;
                }

                $name = basename($from);
                self::move($from, $dir.'/'.$name);
                $moved[] = ['rel' => $r, 'trash' => $id.'/'.$name];
            }
        } catch (\Throwable $e) {
            foreach ($moved as $m) {
                $file = self::trashFile($m['trash']);

                if ($file !== null) {
                    try {
                        self::move($file, public_path($m['rel']));
                    } catch (\Throwable) {
                    }
                }
            }

            @rmdir($dir);
            DB::table(self::TABLE)->where('id', $id)->delete();
            report($e);

            return $base + ['state' => 'kept', 'by' => ['nothing — the server would not move the file ('.$e->getMessage().')']];
        }

        // The Library rows, stashed for Undo. Eloquent delete: the usage index forgets them.
        $stash = [];

        foreach (Media::query()->whereIn('path', array_column($moved, 'rel'))->get() as $media) {
            $stash[] = $media->getAttributes();
            $media->delete();
        }

        // Every cached copy: phone widths, crops, the share card, the email JPEGs.
        foreach ($moved as $m) {
            ImageVariants::forget('/'.$m['rel']);
            ShareImage::forget('/'.$m['rel']);
            self::forgetMailCopies($m['rel']);
        }

        // The old address 301s to the picture that took its slot.
        $ledger = [];

        if ($took !== null && ImageFiles::hasTable('image_renames')) {
            foreach ($moved as $m) {
                $ledger[] = (int) DB::table('image_renames')->insertGetId([
                    'job_id' => null, 'product_id' => null, 'media_id' => null, 'role' => self::LEDGER_ROLE,
                    'old_path' => $m['rel'], 'new_path' => $took[0], 'status' => 'done', 'refs' => 0, 'files' => 1,
                    'admin_id' => $admin->id ?? null, 'admin_name' => isset($admin->name) ? mb_substr((string) $admin->name, 0, 120) : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        DB::table(self::TABLE)->where('id', $id)->update([
            'files' => json_encode($moved, JSON_UNESCAPED_SLASHES), 'media_rows' => $stash === [] ? null : json_encode($stash, JSON_UNESCAPED_SLASHES),
            'ledger_ids' => $ledger === [] ? null : implode(',', $ledger), 'status' => 'trashed', 'updated_at' => now(),
        ]);

        self::noteDue($now->copy()->addDays(self::DAYS)->getTimestamp());

        return $base + ['state' => 'trashed', 'id' => $id, 'purge_after' => $now->copy()->addDays(self::DAYS)->toDateString()];
    }

    /** Files back into the web root, rows back into the Library, the redirect retired. Null on success. */
    private static function putBack(object $row): ?string
    {
        $files = self::filesOf($row);

        foreach ($files as $f) {
            if (ImageFiles::local((string) $f['rel']) !== $f['rel'] || self::trashFile((string) $f['trash']) === null) {
                return 'The trashed file is missing, so it cannot be put back.';
            }

            if (file_exists(public_path($f['rel']))) {
                return 'Another file now has the name '.basename((string) $f['rel']).', so the old one cannot be put back.';
            }
        }

        foreach ($files as $f) {
            $to = public_path($f['rel']);
            $dir = \dirname($to);

            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            self::move((string) self::trashFile((string) $f['trash']), $to);
        }

        @rmdir(self::root().'/'.(int) $row->id);

        foreach (json_decode((string) ($row->media_rows ?? ''), true) ?: [] as $attributes) {
            if (! is_array($attributes) || ! isset($attributes['path']) || Media::query()->where('path', $attributes['path'])->exists()) {
                continue;
            }

            if (isset($attributes['id']) && Media::query()->whereKey($attributes['id'])->exists()) {
                unset($attributes['id']);
            }

            $media = new Media();
            $media->setRawAttributes($attributes);
            $media->save();
        }

        $ledger = array_filter(array_map('intval', explode(',', (string) ($row->ledger_ids ?? ''))));

        if ($ledger !== []) {
            DB::table('image_renames')->whereIn('id', $ledger)->where('role', self::LEDGER_ROLE)
                ->update(['status' => 'undone', 'updated_at' => now()]);
        }

        foreach ($files as $f) {
            try {
                ImageVariants::generate('/'.$f['rel']);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return null;
    }

    /** @return list<array{rel: string, trash: string}> */
    private static function filesOf(object $row): array
    {
        $files = json_decode((string) ($row->files ?? '[]'), true);

        return array_values(array_filter(is_array($files) ? $files : [], static fn ($f) => is_array($f) && isset($f['rel'], $f['trash'])));
    }

    private static function root(): string
    {
        return rtrim(storage_path(self::DIR), '/');
    }

    /** A trash file by its stored "<id>/<name>", only when it really is inside the trash root. */
    private static function trashFile(string $stored): ?string
    {
        if (preg_match('#^\d+/[^/\\\\\x00]+$#', $stored) !== 1 || str_contains($stored, '..')) {
            return null;
        }

        $root = realpath(self::root());
        $file = realpath(self::root().'/'.$stored);

        if ($root === false || $file === false || ! is_file($file)) {
            return null;
        }

        return str_starts_with($file, $root.'/') ? $file : null;
    }

    /** rename(), or copy-and-verify across devices (storage and the web root can differ). */
    private static function move(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }

        $size = @filesize($from);

        if (! @copy($from, $to) || @filesize($to) !== $size) {
            @unlink($to);

            throw new \RuntimeException('could not move '.basename($from));
        }

        @unlink($from);
    }

    /** img-cache/mail/<w>/<rel>.jpg|.png — MailImage's copies, which ImageVariants::forget() does not walk. */
    private static function forgetMailCopies(string $rel): void
    {
        $root = realpath(public_path(ImageVariants::DIR.'/'.MailImage::DIR));

        if ($root === false) {
            return;
        }

        foreach (MailImage::WIDTHS as $w) {
            foreach (['.jpg', '.png'] as $suffix) {
                $file = realpath($root.'/'.$w.'/'.$rel.$suffix);

                if ($file !== false && is_file($file) && str_starts_with($file, $root.'/')) {
                    @unlink($file);
                }
            }
        }
    }

    /** The originals an img-cache address can be a copy of (widths, crops, share and email copies). @return list<string> */
    private static function originalsOf(string $path): array
    {
        $segments = explode('/', $path);
        $out = [];

        foreach ([2, 3] as $depth) {
            if (count($segments) <= $depth) {
                continue;
            }

            $inner = implode('/', array_slice($segments, $depth));
            $out[] = $inner;

            if (preg_match('/\.(jpg|png)$/', $inner) === 1 && in_array($segments[1], ['share', 'share-sq', MailImage::DIR], true)) {
                $out[] = substr($inner, 0, -4);
            }
        }

        return array_values(array_filter(array_unique($out), static fn ($p) => self::underRoot($p)));
    }

    private static function underRoot(string $path): bool
    {
        foreach (MediaRegistrar::ROOTS as $root) {
            if (str_starts_with($path, $root)) {
                return true;
            }
        }

        return false;
    }

    /** The product's main picture now, as a web-root-relative path on disk (its email copy when there is one). */
    private static function currentPictureOf(int $productId): ?string
    {
        $image = $productId > 0 ? Product::query()->whereKey($productId)->value('image') : null;
        $rel = ImageFiles::local(is_string($image) ? $image : null);

        if ($rel === null || ! ImageFiles::exists($rel)) {
            return null;
        }

        foreach (MailImage::WIDTHS as $w) {
            $copy = ImageVariants::DIR.'/'.MailImage::DIR.'/'.$w.'/'.$rel.'.jpg';

            if (is_file(public_path($copy))) {
                return $copy;
            }
        }

        return $rel;
    }

    private static function nameOf(string $table, string $id): ?string
    {
        if ($table === 'settings') {
            return $id;
        }

        $column = self::NAMES[$table] ?? null;

        if ($column === null) {
            return null;
        }

        try {
            $name = DB::table($table)->where('id', $id)->value($column);
        } catch (\Throwable) {
            return null;
        }

        $name = trim(strip_tags((string) $name));

        return $name === '' ? null : mb_substr($name, 0, 60);
    }

    private static function altOf(array $alts, string $url): ?string
    {
        $alt = trim((string) ($alts[$url] ?? ''));

        return $alt === '' ? null : $alt;
    }

    /** @return array<string, mixed> */
    private static function view(object $r): array
    {
        return [
            'id' => (int) $r->id, 'slot' => (string) $r->slot, 'position' => (int) $r->position,
            'old' => basename((string) $r->old_path), 'replacement' => $r->replacement_url,
            'purge_after' => substr((string) $r->purge_after, 0, 10), 'at' => (string) $r->created_at,
        ];
    }
}
