<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use App\Support\Url;
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

    /** URL contract U-03: /product-category/{nested/path}/ with a trailing slash. */
    public function url(): string
    {
        return Url::to('/product-category/' . ($this->path ?: $this->buildPath()) . '/');
    }

}
