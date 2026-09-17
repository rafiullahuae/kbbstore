<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class MenuItem extends Model
{
    use HasTranslations;

    protected $guarded = [];

    /**
     * @var list<string>
     *
     * The label only. `url` is deliberately absent: a menu item points at a
     * page, and the /ar prefix is what makes that page Arabic. A translated URL
     * would be a second address to keep in step by hand.
     */
    protected array $translatable = ['label'];

    public function menu()
    {
        return $this->belongsTo(Menu::class);
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

}
