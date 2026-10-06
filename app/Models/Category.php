<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use App\Support\Url;
use App\Support\UrlScheme;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasTranslations;

    protected $guarded = [];

    /** @var list<string> Not `slug` — one slug per row. See HasTranslations. */
    protected array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return ['depth' => 'int', 'position' => 'int', 'short_url' => 'bool', 'seo' => 'array', 'banner' => 'array', 'header_style' => 'array'];
    }

    /**
     * A category tab targets a whole branch, so moving a branch has to evict.
     * (Lane PT)
     *
     * App\Support\ProductTabs caches `id => parent_id` for the whole tree so
     * that a global tab targeted at "Skincare" costs a product page NO query to
     * match -- and a tab on a parent shows on everything under it, which means
     * RE-PARENTING a category changes which products a tab appears on.
     *
     * The eviction is here rather than in the Categories screen for the reason
     * TranslationStore gives for putting flush() on a model hook: every writer
     * then evicts, including the importer, a console command and a writer
     * added next month that has never heard of product tabs. flush() clears
     * both layers and cannot throw -- it is reached from inside migrations.
     */
    protected static function booted(): void
    {
        static::saved(static fn () => \App\Support\ProductTabs::flush());
        static::deleted(static fn () => \App\Support\ProductTabs::flush());
        // "Buy these together" reads every category's name to pair them, from
        // a ten-minute cache: a renamed or new shelf is paired at once. (Lane RB)
        static::saved(static fn () => \App\Services\BuyTogetherPairs::forget());
        static::deleted(static fn () => \App\Services\BuyTogetherPairs::forget());
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    public function products()
    {
        return $this->belongsToMany(Product::class);
    }

    /**
     * The address path when the cached `path` is missing, by the same rule as
     * App\Support\CategoryTree: a short-address category (short_url) is its own
     * slug under any parent; otherwise the parent chain, which stops at the
     * first short ancestor because that ancestor's address is its slug.
     */
    public function buildPath(): string
    {
        if ($this->short_url) {
            return (string) $this->slug;
        }
        $segments = [$this->slug];
        $node = $this->parent;
        $guard = 0;
        while ($node && $guard++ < 10) {
            array_unshift($segments, $node->slug);
            if ($node->short_url) {
                break;
            }
            $node = $node->parent;
        }

        return implode('/', $segments);
    }

    /**
     * URL contract U-03, as the scheme now states it:
     * /collections/{nested/path}/ with a trailing slash.
     *
     * The shape lives in App\Support\UrlScheme rather than in a literal here.
     * A category archive is a LISTING page, so the address is plural, and the
     * old /product-category/ form 301s onto this one in a single hop.
     */
    public function url(): string
    {
        return Url::to(UrlScheme::collection((string) ($this->path ?: $this->buildPath())));
    }

}
