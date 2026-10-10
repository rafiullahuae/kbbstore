<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Where a shopper's review photograph is written — the one place, for both
 * forms that take one (the product page's review sheet, Store\ReviewController,
 * and the review request page, Lane RV).
 *
 * Moved here unchanged from Store\ReviewController::submit(), whose comments
 * give the reasons at length: written under public/ rather than through a
 * storage symlink (the updater cannot be trusted to have made one); a random
 * name, never the uploaded filename (visitor-controlled text never touches the
 * filesystem path); and the media library row written at once, not at the next
 * rescan (MediaRegistrar, which cannot fail the submission).
 *
 * The caller validates first (image, jpg/jpeg/png/webp, 5 MB, the count
 * ReviewSettings allows). This only stores what already passed.
 */
final class ReviewPhotos
{
    /** The stored path ("/uploads/reviews/<uuid>.<ext>"), or null for an upload that is not valid. */
    public static function store(UploadedFile $photo): ?string
    {
        if (! $photo->isValid()) {
            return null;
        }

        $name = Str::uuid() . '.' . $photo->extension();
        $dir = public_path('uploads/reviews');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $photo->move($dir, $name);
        $stored = '/uploads/reviews/' . $name;

        MediaRegistrar::record($stored);

        return $stored;
    }
}
