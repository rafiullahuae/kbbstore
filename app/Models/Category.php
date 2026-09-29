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
        return ['depth' => 'int', 'position' => 'int', 'seo' => 'array', 'banner' => 'array'];
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

    /** Full nested path, e.g. skincare/face-cleansers/makeup-removers. Production nests four deep. */
    public function buildPath(): string
    {
        $segments = [$this->slug];
        $node = $this->parent;
        $guard = 0;
        while ($node && $guard++ < 10) {
            array_unshift($segments, $node->slug);
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
