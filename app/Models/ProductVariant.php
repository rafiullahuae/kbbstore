<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['manage_stock' => 'bool', 'price' => 'int', 'sale_price' => 'int'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function attributeValues()
    {
        return $this->belongsToMany(AttributeValue::class);
    }

    public function effectivePrice(): int
    {
        return (int) ($this->sale_price ?? $this->price ?? $this->product?->effectivePrice() ?? 0);
    }

    public function inStock(): bool
    {
        return $this->stock_status === 'instock';
    }

    /** e.g. "50ml / Rose" — built from the variant's attribute values. */
    public function label(): string
    {
        return $this->attributeValues->pluck('name')->implode(' / ');
    }

}
