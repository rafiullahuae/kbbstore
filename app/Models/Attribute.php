<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class Attribute extends Model
{
    use HasTranslations;

    protected $guarded = [];

    /**
     * (Lane VPA) The name is shop copy: "Shade" / "Size" above the picker and
     * "#13 Bright Beige" on the swatch, in the cart and on the order. Read
     * from TranslationStore's cached map, so a translated picker costs no
     * query; edited on Translation -> Content like every other catalogue name.
     */
    protected array $translatable = ['name'];

    /** (Lane VPA) A renamed term or a new swatch colour is a new card. */
    protected static function booted(): void
    {
        static::saved(static fn () => \App\Services\VariantSwatches::invalidate());
        static::deleted(static fn () => \App\Services\VariantSwatches::invalidate());
    }

    protected function casts(): array
    {
        return ['is_variation_axis' => 'bool', 'is_filterable' => 'bool'];
    }

    public function values()
    {
        return $this->hasMany(AttributeValue::class)->orderBy('position');
    }

    /** The live query var, e.g. filter_color. Preserved verbatim from WooCommerce. */
    public function queryVar(): string
    {
        return $this->query_var ?: 'filter_' . $this->slug;
    }

}
