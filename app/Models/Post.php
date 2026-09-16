<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['seo' => 'array', 'published_at' => 'datetime'];
    }

    /**
     * How long this article takes to read, in whole minutes.
     *
     * Derived from `body` rather than stored: the home rail printed
     * `$post->read_minutes ?? 5` against a column that does not exist, so every
     * article on the page claimed five minutes whatever its length. See
     * App\Support\ReadingTime for why it is computed and not a new column.
     *
     * A method rather than an accessor, so that DemoContent's stand-in article
     * objects can answer the same question the same way and one Blade
     * expression serves both.
     */
    public function readMinutes(): int
    {
        return \App\Support\ReadingTime::minutes($this->body);
    }

}
