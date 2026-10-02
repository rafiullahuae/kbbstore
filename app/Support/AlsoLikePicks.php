<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One product's own "You may also like" picks.                    (Lane PS)
 *
 * Catalog → Products → (edit a product) → You may also like. The owner asked
 * for "manual selection also", beside the rule — so a product can name its own
 * companions, in his order, and say whether they come before the rule's
 * choices or replace them.
 *
 * ── STORED AS JSON ON `products.also_like`, NOT A PIVOT TABLE ───────────────
 *
 * `{"mode": "first", "ids": [12, 7, 31]}`. The argument:
 *
 *   - IT IS READ FOR FREE. The product page already loads the product's whole
 *     row; the picks arrive with it. A pivot would be one more statement on
 *     every product view — the page StorefrontQueryBudgetTest holds tightest.
 *   - IT IS AN ORDERED LIST, WRITTEN WHOLE. The editor posts the list he
 *     arranged and nothing ever edits one entry of it. A pivot needs a
 *     `position` column and a delete-and-reinsert on every save to say the same
 *     thing.
 *   - NOTHING ASKS THE REVERSE QUESTION. "Which products name product 7?" is
 *     the one thing a pivot is good at, and no screen asks it.
 *   - A DANGLING ID IS HARMLESS. A pick that is later deleted, hidden or sold
 *     out is simply not found by the storefront's own visible() query, the
 *     same way it leaves every other grid. The editor marks it so he can see
 *     why it is not on the page.
 *
 * ── VALIDATED ON THE WAY IN ─────────────────────────────────────────────────
 *
 * A mode is one of three words or the default. At most 24 ids — the
 * carousel's own ceiling. Every id must be a product the SHOP can show
 * (visible(): published, visible, its date arrived), and never the product
 * itself. A product carrying no picks and the default mode stores NULL, so the
 * column of an untouched catalogue stays empty.
 */
final class AlsoLikePicks
{
    public const MODE_RULE = 'rule';

    public const MODE_FIRST = 'first';

    public const MODE_ONLY = 'only';

    public const MODES = [
        self::MODE_RULE => 'Use the rule',
        self::MODE_FIRST => 'My picks first, then the rule',
        self::MODE_ONLY => 'Only my picks',
    ];

    public const MAX = 24;

    /** @return array{mode: string, ids: list<int>} */
    public static function read(Product $product): array
    {
        $raw = $product->getAttribute('also_like');
        $raw = is_array($raw) ? $raw : [];

        $mode = (string) ($raw['mode'] ?? self::MODE_RULE);
        $mode = array_key_exists($mode, self::MODES) ? $mode : self::MODE_RULE;

        $ids = [];

        foreach ((array) ($raw['ids'] ?? []) as $id) {
            $id = (int) $id;

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return ['mode' => $mode, 'ids' => array_slice($ids, 0, self::MAX)];
    }

    /**
     * The request's `also_like`, checked — or null when the request did not
     * carry one, which means "leave the picks alone".
     *
     * @return array{mode: string, ids: list<int>}|null
     *
     * @throws ValidationException
     */
    public static function fromRequest(Request $request, Product $product): ?array
    {
        if (! $request->has('also_like')) {
            return null;
        }

        $in = $request->input('also_like');

        if (! is_array($in)) {
            throw ValidationException::withMessages(['also_like' => ['“You may also like” could not be read.']]);
        }

        $mode = (string) ($in['mode'] ?? self::MODE_RULE);

        if (! array_key_exists($mode, self::MODES)) {
            throw ValidationException::withMessages(['also_like.mode' => ['Choose how this product’s picks are used.']]);
        }

        $ids = $in['ids'] ?? [];

        if (! is_array($ids)) {
            throw ValidationException::withMessages(['also_like.ids' => ['“You may also like” could not be read.']]);
        }

        $clean = [];

        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw ValidationException::withMessages(['also_like.ids' => ['“You may also like” could not be read.']]);
            }

            $id = (int) $id;

            if ($id === (int) $product->id) {
                throw ValidationException::withMessages(['also_like.ids' => ['A product cannot be its own “You may also like”.']]);
            }

            if ($id > 0 && ! in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        if (count($clean) > self::MAX) {
            throw ValidationException::withMessages(['also_like.ids' => ['“You may also like” holds at most '.self::MAX.' products.']]);
        }

        if ($clean !== []) {
            $visible = Product::query()->visible()->whereIn('id', $clean)->pluck('id')->map(fn ($i) => (int) $i)->all();
            $missing = array_values(array_diff($clean, $visible));

            if ($missing !== []) {
                throw ValidationException::withMessages(['also_like.ids' => [
                    count($missing) === 1
                        ? 'One of the picks is not on the shop (hidden, a draft or deleted). Remove it and save again.'
                        : count($missing).' of the picks are not on the shop (hidden, drafts or deleted). Remove them and save again.',
                ]]);
            }
        }

        return ['mode' => $mode, 'ids' => $clean];
    }

    /** Store the picks. NULL for "no picks, default mode". */
    public static function write(Product $product, array $picks): void
    {
        $value = ($picks['mode'] === self::MODE_RULE && $picks['ids'] === []) ? null : $picks;

        if ($product->getAttribute('also_like') == $value) {
            return;
        }

        $product->also_like = $value;
        $product->save();
    }

    /**
     * What the editor's panel draws: the mode, and each pick as a row.
     *
     * An explicit projection — the same habit ProductEditorApiController::
     * payload() keeps. NO QUERY for a product with no picks, which is every
     * product until he adds one.
     *
     * @return array{mode: string, items: list<array<string, mixed>>}
     */
    public static function editorPayload(Product $product): array
    {
        $picks = self::read($product);

        if ($picks['ids'] === []) {
            return ['mode' => $picks['mode'], 'items' => []];
        }

        $rows = Product::query()
            ->with('brand:id,name')
            ->whereIn('id', $picks['ids'])
            ->get(['id', 'name', 'image', 'brand_id', 'status', 'is_visible', 'published_at', 'stock_status'])
            ->keyBy(fn ($p) => (int) $p->id);

        $visible = Product::query()->visible()->whereIn('id', $picks['ids'])->pluck('id')->map(fn ($i) => (int) $i)->all();

        $items = [];

        foreach ($picks['ids'] as $id) {
            $p = $rows[$id] ?? null;

            $items[] = [
                'id' => $id,
                'name' => $p ? (string) $p->name : 'A deleted product',
                'brand' => $p?->brand?->name,
                'image' => $p?->image,
                'on_shop' => in_array($id, $visible, true),
                'sold_out' => $p !== null && $p->stock_status === 'outofstock',
            ];
        }

        return ['mode' => $picks['mode'], 'items' => $items];
    }

    /**
     * The picker's search: products the shop can show, by name or SKU, never
     * the product being edited. Allowlisted fields only.
     *
     * @return list<array<string, mixed>>
     */
    public static function search(string $term, int $exclude): array
    {
        $term = mb_substr(trim($term), 0, 100);

        $q = Product::query()
            ->visible()
            ->with('brand:id,name')
            ->where('id', '!=', $exclude)
            ->orderByDesc('total_sales')
            ->orderByDesc('id')
            ->limit(20);

        if ($term !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

            $q->where(function ($w) use ($pattern) {
                $w->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("sku LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        return $q->get(['id', 'name', 'image', 'brand_id', 'stock_status'])
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'brand' => $p->brand?->name,
                'image' => $p->image,
                'sold_out' => $p->stock_status === 'outofstock',
            ])->values()->all();
    }
}
