<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SpottedPost;
use App\Services\ModuleSchema;
use App\Services\SpottedSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → #KBeautyBliss Spotted.                               (Lane HB)
 *
 * The posts (add, edit, reorder, remove) and the section's settings. Every
 * route is in routes/spotted-admin.php, inside the guarded admin-api group, and
 * every one of them maps to `spotted.manage` in App\Support\AdminCapabilities —
 * an unmapped admin route is owner-only, so the map is what lets a manager or
 * an editor in, and nothing else does.
 *
 * ── WHAT IS CHECKED HERE, AND WHAT IS CHECKED AGAIN ON THE WAY OUT ──────────
 *
 * The Instagram address must be https://www.instagram.com/… or
 * https://instagram.com/… (SpottedPost::instagramUrl() parses the host rather
 * than matching a prefix, so instagram.com.evil.test and instagram.com@evil.test
 * are refused). The picture must be a path on this shop or an http(s) address.
 * The handle is Instagram's own alphabet. The heart count is a whole number or
 * nothing. SpottedPost::toCard() repeats every one of those checks when the
 * storefront draws the card, so a row that reaches the table some other way is
 * still not printed unchecked.
 */
class SpottedApiController extends Controller
{
    public function __construct(private SpottedSettings $settings) {}

    public function show(): JsonResponse
    {
        $posts = SpottedPost::query()->with('product:id,name,image,slug,status,is_visible')->ordered()->get();

        return response()->json([
            'ok' => true,
            'posts' => $posts->map(fn (SpottedPost $p): array => $this->row($p))->values(),
            'tabs' => ModuleSchema::tabs(SpottedSettings::SCHEMA, SpottedSettings::TABS, $this->settings->all(), SpottedSettings::POLICY),
            'page_url' => \App\Support\Url::to(SpottedSettings::URL),
        ]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(SpottedSettings::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $result = $this->settings->save($data['settings']);

        return response()->json(['ok' => $result['rejected'] === [], 'rejected' => $result['rejected']]);
    }

    public function store(Request $request): JsonResponse
    {
        $clean = $this->clean($request);

        if (is_string($clean)) {
            return response()->json(['ok' => false, 'error' => $clean], 422);
        }

        $clean['sort'] = (int) SpottedPost::query()->max('sort') + 1;
        $post = SpottedPost::create($clean);
        SpottedSettings::flush();

        return response()->json(['ok' => true, 'post' => $this->row($post->load('product:id,name,image,slug,status,is_visible'))]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $post = SpottedPost::query()->find($id);

        if ($post === null) {
            return response()->json(['ok' => false, 'error' => 'That post no longer exists.'], 404);
        }

        $clean = $this->clean($request);

        if (is_string($clean)) {
            return response()->json(['ok' => false, 'error' => $clean], 422);
        }

        $post->fill($clean)->save();
        SpottedSettings::flush();

        return response()->json(['ok' => true, 'post' => $this->row($post->load('product:id,name,image,slug,status,is_visible'))]);
    }

    public function destroy(int $id): JsonResponse
    {
        SpottedPost::query()->whereKey($id)->delete();
        SpottedSettings::flush();

        return response()->json(['ok' => true]);
    }

    /** The whole list's order, as the screen has it after a move. */
    public function order(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);

        foreach (array_values($data['ids']) as $i => $id) {
            SpottedPost::query()->whereKey((int) $id)->update(['sort' => $i + 1]);
        }

        SpottedSettings::flush();

        return response()->json(['ok' => true]);
    }

    /**
     * The product picker's search. An allowlist of five fields, nothing else —
     * the same LIKE-with-an-explicit-ESCAPE shape ProductTabsApiController uses.
     */
    public function products(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $query = Product::query()->select('id', 'name', 'slug', 'status', 'image');

        if ($term !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($term, 0, 80)).'%';
            $query->where(function ($q) use ($pattern): void {
                $q->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        return response()->json([
            'ok' => true,
            'products' => $query->orderByDesc('id')->limit(20)->get()->map(fn (Product $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'slug' => (string) $p->slug,
                'image' => $p->image,
                'status' => (string) $p->status,
            ])->values(),
        ]);
    }

    /**
     * The checked columns, or the sentence the screen shows for the first
     * thing wrong.
     *
     * @return array<string, mixed>|string
     */
    private function clean(Request $request): array|string
    {
        $in = $request->all();

        $image = SpottedPost::imageUrl(is_string($in['image'] ?? null) ? $in['image'] : null);
        if ($image === null) {
            return 'Choose the picture from the media library.';
        }

        $handle = SpottedPost::cleanHandle(is_string($in['handle'] ?? null) ? $in['handle'] : null);
        if ($handle === null) {
            return 'The Instagram handle is letters, numbers, dots and underscores — up to 30, like @sara.glows.';
        }

        $rawIg = trim(is_string($in['ig_url'] ?? null) ? $in['ig_url'] : '');
        $ig = SpottedPost::instagramUrl($rawIg);
        if ($rawIg !== '' && $ig === null) {
            return 'The Instagram link must start https://www.instagram.com/ or https://instagram.com/ and point at a post.';
        }

        $productId = null;
        if (($in['product_id'] ?? null) !== null && ($in['product_id'] ?? '') !== '') {
            if (! is_numeric($in['product_id']) || ! Product::query()->whereKey((int) $in['product_id'])->exists()) {
                return 'That product could not be found.';
            }
            $productId = (int) $in['product_id'];
        }

        if ($ig === null && $productId === null) {
            return 'Give the post somewhere to go: its Instagram link, a product, or both.';
        }

        $likes = null;
        $rawLikes = $in['likes'] ?? null;
        if ($rawLikes !== null && trim((string) $rawLikes) !== '') {
            if (! is_numeric($rawLikes) || (string) (int) $rawLikes !== trim((string) $rawLikes) || (int) $rawLikes < 0 || (int) $rawLikes > 100000000) {
                return 'The heart count is a whole number, or leave it empty to show none.';
            }
            $likes = (int) $rawLikes;
        }

        $linkTo = in_array($in['link_to'] ?? null, SpottedPost::LINKS, true) ? $in['link_to'] : 'instagram';

        $text = static fn (mixed $v, int $max): ?string => ($t = mb_substr(trim(strip_tags(is_string($v) ? $v : '')), 0, $max)) === '' ? null : $t;

        return [
            'image' => $image,
            'image_alt' => $text($in['image_alt'] ?? null, 200),
            'ig_url' => $ig,
            'handle' => $handle,
            'caption' => $text($in['caption'] ?? null, 160),
            'product_id' => $productId,
            'link_to' => $linkTo,
            'likes' => $likes,
            'on_home' => filter_var($in['on_home'] ?? true, FILTER_VALIDATE_BOOL),
            'on_page' => filter_var($in['on_page'] ?? true, FILTER_VALIDATE_BOOL),
        ];
    }

    /** @return array<string, mixed> */
    private function row(SpottedPost $p): array
    {
        $product = $p->product;

        return [
            'id' => (int) $p->id,
            'image' => (string) $p->image,
            'image_alt' => (string) $p->image_alt,
            'ig_url' => (string) $p->ig_url,
            'handle' => (string) $p->handle,
            'caption' => (string) $p->caption,
            'product' => $product === null ? null : ['id' => (int) $product->id, 'name' => (string) $product->name, 'image' => $product->image, 'status' => (string) $product->status],
            'link_to' => (string) $p->link_to,
            'likes' => $p->likes,
            'on_home' => (bool) $p->on_home,
            'on_page' => (bool) $p->on_page,
            'sort' => (int) $p->sort,
            // Whether the storefront can draw it, so the screen can say so.
            'drawable' => $p->toCard() !== null,
        ];
    }
}
