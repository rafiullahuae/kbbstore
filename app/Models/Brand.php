<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use App\Support\Url;
use App\Support\UrlScheme;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use HasTranslations;

    protected $guarded = [];

    /**
     * @var list<string>
     *
     * `name` is on the list and that is a judgement, not an oversight. Korean
     * brand names are often already transliterations ("Anua", "Round Lab") and
     * an Arabic shopper may well want them left as they are — so the box exists
     * and is left BLANK, which under this design means "not translated" and
     * falls back to the English. Typing the same text in would mean "translated,
     * deliberately identical". The shop can tell those two apart.
     */
    protected array $translatable = ['name', 'description'];

    /**
     * `seo` and `banner` are json columns and were being handed to the views
     * as raw strings, because this model declared no casts at all while
     * Category did. Brand::$seo therefore came back as `{"title":"…"}` — a
     * string that is truthy, has no ->title, and would render as literal JSON
     * anywhere it was echoed. Same shape as Category now, so the two cannot
     * disagree about what a json column on a taxonomy row means.
     */
    protected function casts(): array
    {
        return ['position' => 'int', 'seo' => 'array', 'banner' => 'array', 'header_layout' => 'array'];
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The brand's own page — /brands/{slug}/.
     *
     * ── THIS USED TO RETURN THE FILTERED SHOP LISTING, AND THAT WAS THE BUG ─
     *
     * It read `Url::to('/shop/') . '?filter_brands=' . $this->slug`, under a
     * docblock saying URL Contract U-05 forbade "improving" it into a pretty
     * URL. U-05 is about the LISTING, and the listing has not moved — see
     * filterUrl() below, which is the same string and is what the mega menu and
     * the shop's own facets still use.
     *
     * What was wrong is that url() is the address this application publishes
     * when it means "this brand": the search suggestions link it, and
     * `RedirectMap` hands it to every brand permalink the old site served. A
     * query string on /shop/ is not an indexable page, so a brand archive
     * arriving from the old install was redirected onto a filtered listing that
     * canonicalises to /shop/ — which tells Google the brand page does not
     * exist. For a K-beauty shop "medicube uae" is exactly what people type,
     * and there was nothing to rank with.
     *
     * /brands/{slug}/ is a real page with the brand's own copy, logo and
     * product grid (Store\BrandController::show), it is in the sitemap, and it
     * links onward to filterUrl() for the filterable listing.
     */
    public function url(): string
    {
        return Url::to(UrlScheme::brand((string) $this->slug));
    }

    /**
     * The brand's filterable, sortable, paginated product listing — URL
     * Contract U-05, unchanged: /shop/?filter_brands={slug}.
     *
     * Kept as its own method rather than deleted because it is a different
     * thing from url() and both are wanted: the landing page is what a search
     * engine should hold, and this is what a shopper narrowing the shop is
     * actually looking at. Byte-for-byte what url() used to return, so every
     * caller that means "the listing" reads the same string it always did.
     */
    public function filterUrl(): string
    {
        return Url::to('/shop/') . '?filter_brands=' . $this->slug;
    }

}
