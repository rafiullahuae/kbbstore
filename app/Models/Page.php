<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasTranslations;

    protected $guarded = [];

    /** @var list<string> Not `slug`: one slug per page, language in the prefix. */
    protected array $translatable = ['title', 'content'];

    protected function casts(): array
    {
        return ['seo' => 'array'];
    }

}
