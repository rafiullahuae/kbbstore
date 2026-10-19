<?php

declare(strict_types=1);

namespace App\Support;

/**
 * "Shade: #13 Bright Beige" for each basket variant, worked out once per
 * request. (Lane VPA)
 *
 * The basket page and the checkout load their lines with the option values;
 * the mini cart (CartDrawerComposer) reloads the same lines for itself on the
 * same request. Without this, naming the shade in the mini cart would read
 * `attribute_values` a second time on /cart and /checkout -- the page getting
 * dearer for nothing, which CartLineEagerLoadTest forbids. The page's own load
 * fills this; the drawer reads it and loads values only for a variant nobody
 * has named yet (every page but those two).
 *
 * Scoped (AppServiceProvider), so it is per request and a queue worker or a
 * test never reads a label from before a rename.
 */
final class VariantLabels
{
    /** @var array<int, string> variant id => text */
    private array $text = [];

    public function put(int $variantId, string $text): void
    {
        $this->text[$variantId] = $text;
    }

    public function has(int $variantId): bool
    {
        return array_key_exists($variantId, $this->text);
    }

    public function get(int $variantId): ?string
    {
        return $this->text[$variantId] ?? null;
    }

    /** Name every loaded line's variant now, while its values are in hand. */
    public function fill(iterable $items): void
    {
        foreach ($items as $item) {
            $v = $item->relationLoaded('variant') ? $item->getRelation('variant') : null;

            if ($v !== null && $v->relationLoaded('attributeValues')) {
                $this->put((int) $v->id, VariantText::forVariant($v));
            }
        }
    }
}
