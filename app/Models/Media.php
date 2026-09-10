<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Url;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{

    protected $table = 'media';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sizes' => 'array'];
    }

    /** D-45: media stays under /wp-content/uploads/ so no shared image URL breaks. */
    public function url(): string
    {
        return Url::media($this->path);
    }

}
