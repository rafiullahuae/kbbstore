<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\Page;
use App\Models\Post;
use App\Support\Shortcodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Content -> HTML Blocks. (Lane BC)
 *
 * The screen behind this was a NAV entry pointing at `kbb-admin-blocks.html`,
 * a standalone file this repo has never shipped, so every visit probed for it,
 * failed, and printed "HTML Blocks isn't installed yet".
 *
 * WHAT A BLOCK IS FOR. A snippet of HTML the owner writes once -- a shipping
 * note, a payment-logo strip, a seasonal banner -- and then places in as many
 * pages and posts as they like by writing its shortcode. Editing the block
 * changes every page that names it; that is the entire point, and it is why
 * "used in" below is a real query and not a decoration.
 *
 * WHERE THE PLACEMENT COMES FROM. Not invented here. App\Support\Shortcodes
 * already existed, already had a Blade directive registered for it in
 * AppServiceProvider, and that directive's own comment already named HTML
 * blocks as a thing it was for. This lane added [kbb_block] to that engine and
 * pointed store/page.blade.php and store/post.blade.php at the directive --
 * which no view was using, so the engine had never rendered anything on the
 * storefront at all.
 *
 * GUARD. There is no per-route authorisation in this class. It relies entirely
 * on being mounted inside the admin-api group in routes/web.php -- `web`,
 * `auth:admin`, NoStoreAdminApi -- which is the arrangement every sibling
 * Admin\*ApiController uses. `content` is HTML that is rendered into
 * storefront pages unescaped, so an unguarded write endpoint here is stored
 * XSS on every page that names the block. routes/html-blocks-admin.php says so
 * in its header and HtmlBlocksScreenTest drives every route it registers,
 * unauthenticated and as a customer, expecting a refusal.
 */
class BlocksApiController extends Controller
{
    /**
     * A block's HTML is trusted (it is authored by a signed-in admin and
     * rendered unescaped), but it is not unbounded. 200k characters is far
     * more than any snippet needs and still fits a longText with room to
     * spare; without a cap the limit is post_max_size, which answers with a
     * blank 413 the screen cannot explain.
     */
    private const MAX_CONTENT = 200000;

    /**
     * GET /admin-api/blocks
     *
     * Content is deliberately NOT in the list payload. Fifty blocks of markup
     * is a slow response for a table that shows none of it; the editor fetches
     * one block's content when it opens one.
     */
    public function index(): JsonResponse
    {
        $blocks = Block::query()
            ->orderBy('name')
            ->get(['id', 'slug', 'name', 'status', 'updated_at']);

        $usage = $this->usageCounts();

        return response()->json([
            'ok' => true,
            'blocks' => $blocks->map(fn (Block $b) => [
                'id' => $b->id,
                'slug' => $b->slug,
                'name' => $b->name,
                'status' => $b->status,
                'updated_at' => optional($b->updated_at)->toIso8601String(),
                'shortcode' => $b->shortcode(),
                'used_in' => count($usage[$b->slug] ?? []),
            ])->all(),
            'statuses' => Block::STATUSES,
            'max_content' => self::MAX_CONTENT,
        ]);
    }

    /** GET /admin-api/blocks/{block} — the editor's payload. */
    public function show(Block $block): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'block' => [
                'id' => $block->id,
                'slug' => $block->slug,
                'name' => $block->name,
                'status' => $block->status,
                'content' => (string) $block->content,
                'shortcode' => $block->shortcode(),
                'updated_at' => optional($block->updated_at)->toIso8601String(),
            ],
            'used_in' => $this->usageCounts()[$block->slug] ?? [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $block = Block::query()->create($this->validated($request, null));

        Shortcodes::flush();

        return response()->json(['ok' => true, 'block' => $block], 201);
    }

    public function update(Request $request, Block $block): JsonResponse
    {
        $block->update($this->validated($request, $block));

        // Every storefront render of this block is cached for ten minutes by
        // Shortcodes::block(). Without this the owner saves an edit, reloads
        // the page and sees the old markup -- the single most convincing way
        // to make a working screen look broken.
        Shortcodes::flush();

        return response()->json(['ok' => true, 'block' => $block->fresh()]);
    }

    /**
     * DELETE /admin-api/blocks/{block}
     *
     * A block that pages still name is refused unless `force=1`. Deleting it
     * does not break those pages -- an unresolved [kbb_block] renders as
     * nothing, by design -- but "nothing" is exactly what makes it invisible:
     * a section would quietly vanish from four pages and no screen anywhere
     * would say why. The same shape as BrandsApiController::destroy(), which
     * refuses a brand that products still point at.
     */
    public function destroy(Request $request, Block $block): JsonResponse
    {
        $used = $this->usageCounts()[$block->slug] ?? [];

        if ($used !== [] && ! $request->boolean('force')) {
            $n = count($used);

            return response()->json([
                'ok' => false,
                'error' => 'block_in_use',
                'used_in' => $used,
                'message' => $n . ' ' . Str::plural('page', $n) . ' or post still '
                    . ($n === 1 ? 'places' : 'place') . ' this block. Deleting it removes '
                    . ($n === 1 ? 'that section' : 'those sections') . ' from the storefront.',
            ], 422);
        }

        $block->delete();

        Shortcodes::flush();

        return response()->json(['ok' => true, 'freed' => count($used)]);
    }

    /**
     * Which pages and posts place which block, keyed by slug.
     *
     * PARSED IN PHP WITH THE RENDERER'S OWN PATTERN, not matched with a SQL
     * LIKE. A LIKE would have to guess at the quoting ([kbb_block slug="x"]
     * and [kbb_block slug='x'] are both valid to Shortcodes::attributes()) and
     * would need dialect-specific escaping for the brackets. Reading the same
     * shape the renderer reads is the only way the count on this screen cannot
     * disagree with what the storefront actually does.
     *
     * The LIKE that remains is only a coarse prefilter on the substring
     * '[kbb_block', which needs no escaping and is identical on MySQL and
     * SQLite, so the rows pulled into memory are the few that mention a block
     * at all rather than every page and post in the store.
     *
     * @return array<string, list<array{type:string,id:int,title:string,status:string}>>
     */
    private function usageCounts(): array
    {
        $out = [];

        $record = function (string $type, $row, string $content) use (&$out): void {
            preg_match_all('/\[kbb_block\b([^\]]*)\]/', $content, $matches);

            foreach ($matches[1] as $raw) {
                if (! preg_match('/\bslug\s*=\s*("([^"]*)"|\'([^\']*)\'|(\S+))/', $raw, $m)) {
                    continue;
                }

                $slug = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : ($m[4] ?? ''));

                if ($slug === '') {
                    continue;
                }

                // A page may name the same block twice; it is still one page.
                // Keyed by type:id while building so the second mention is a
                // no-op -- an early `return` here would have abandoned the
                // rest of the page's matches, losing every other block it
                // places after the first repeat.
                $out[$slug] ??= [];
                $key = $type . ':' . $row->id;

                if (isset($out[$slug][$key])) {
                    continue;
                }

                $out[$slug][$key] = [
                    'type' => $type,
                    'id' => (int) $row->id,
                    'title' => (string) $row->title,
                    'status' => (string) $row->status,
                ];
            }
        };

        Page::query()
            ->where('content', 'like', '%[kbb_block%')
            ->get(['id', 'title', 'status', 'content'])
            ->each(fn ($p) => $record('page', $p, (string) $p->content));

        Post::query()
            ->where('body', 'like', '%[kbb_block%')
            ->get(['id', 'title', 'status', 'body'])
            ->each(fn ($p) => $record('post', $p, (string) $p->body));

        // Drop the type:id keys used for de-duplication; callers count and
        // list these, and a JSON object keyed "page:3" would reach the screen
        // as an object where it expects an array.
        return array_map('array_values', $out);
    }

    /**
     * Shared rules for create and edit.
     *
     * The slug is derived from the name when it is left blank, and the derived
     * value goes through the same uniqueness rule -- the lesson
     * BrandsApiController records: a second block named the same thing would
     * otherwise skip validation entirely and surface as a QueryException, i.e.
     * a 500 on a duplicate name rather than a message.
     */
    private function validated(Request $request, ?Block $block): array
    {
        $slug = Str::slug((string) ($request->input('slug') ?: $request->input('name', '')));

        $data = $request->merge(['slug' => $slug])->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('blocks', 'slug')->ignore($block?->id),
            ],
            'content' => ['nullable', 'string', 'max:' . self::MAX_CONTENT],
            'status' => ['required', Rule::in(Block::STATUSES)],
        ], [
            'slug.regex' => 'The handle may use lowercase letters, numbers and single dashes only.',
            'slug.unique' => 'Another block already uses that handle.',
            'slug.required' => 'Give the block a name, or a handle of its own.',
        ]);

        $data['content'] = (string) ($data['content'] ?? '');

        return $data;
    }
}
