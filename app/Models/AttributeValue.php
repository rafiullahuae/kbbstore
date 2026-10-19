<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class AttributeValue extends Model
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

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }

}
