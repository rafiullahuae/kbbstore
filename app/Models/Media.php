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
        return self::urlFor((string) $this->path);
    }

    /**
     * The URL a stored path is served from.
     *
     * TWO ROOTS, AND THEY ARE NOT INTERCHANGEABLE.
     *
     * The table was created for the WooCommerce import, whose paths live under
     * /wp-content/uploads/ so that no image URL anybody has ever shared breaks
     * (D-45). Url::media() adds that prefix, and for those rows it is right.
     *
     * Admin uploads do not live there. MediaUploadController writes them into
     * `public/uploads/<folder>/` — straight into the real public web root,
     * because this host has no reliable `artisan storage:link`; see that class's
     * comment. Handing such a path to Url::media() would produce
     * /wp-content/uploads/uploads/products/x.png, which 404s.
     *
     * So the two are told apart by the one thing that distinguishes them: an
     * admin upload's stored path begins `uploads/`. The base is built by hand
     * from site_url rather than through Url::to(), for the same reason
     * MediaUploadController builds it by hand — site_url ALREADY carries any
     * subfolder the app lives under (/kbb-upgrade), and Url::to() would add it a
     * second time. That double-prefix bug has been fixed twice in this
     * repository already, in the IndexNow hook and then in the upload endpoint;
     * this is the one place it is expressed, and the upload endpoint now calls
     * here rather than keeping its own copy of it.
     */
    public static function urlFor(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        // Already absolute, or protocol-relative: nothing to prefix.
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $path)) {
            return $path;
        }

        $clean = ltrim($path, '/');

        if (str_starts_with($clean, 'uploads/')) {
            $base = rtrim((string) (Setting::map()['site_url'] ?? config('app.url')), '/');

            return $base.'/'.$clean;
        }

        return Url::media($path);
    }

}
