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
