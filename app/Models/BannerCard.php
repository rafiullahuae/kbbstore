<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One card in a set: a picture, one or two lines under it, and a small button.
 *
 * There is deliberately no `toApi()` here and no public endpoint that returns
 * one. CLAUDE.md: "/api/* is unauthenticated ... Use an explicit allowlist."
 * The safest allowlist for a model nothing public needs is the empty one — the
 * storefront reads these rows server-side inside a Blade template, so no column
 * of this table is ever serialised to a shopper. If a later round does need a
 * public endpoint, it adds a `toApi()` here that names its columns one by one,
 * the way `Product::toApi()` does.
 */
class BannerCard extends Model
{
    protected $fillable = [
        'banner_set_id', 'image', 'alt', 'heading', 'body',
        'button_label', 'button_url', 'image_w', 'image_h', 'position', 'status',
        // Lane SEC. The phone picture and its measured size, mirroring the
        // three above. A slider draws it below 768px; a cards banner has one
        // frame shape and ignores it.
        'image_m', 'image_m_w', 'image_m_h',
        // Lane HB. The words on a slider picture -- App\Support\BannerTextBox.
        'box_on', 'box_pos', 'eyebrow', 'sticker', 'sticker_ring',
        'eyebrow_ar', 'heading_ar', 'body_ar', 'button_label_ar', 'sticker_ar', 'sticker_ring_ar',
    ];

    protected $casts = [
        'banner_set_id' => 'int',
        'image_w' => 'int',
        'image_h' => 'int',
        'position' => 'int',
        'image_m_w' => 'int',
        'image_m_h' => 'int',
        'box_on' => 'bool',
    ];

    public const STATUSES = ['publish' => 'Published', 'draft' => 'Draft'];

    public function set(): BelongsTo
    {
        return $this->belongsTo(BannerSet::class, 'banner_set_id');
    }

    /**
     * Is there anything to draw?
     *
     * A card with no picture is an empty box the width of its neighbours, which
     * is worse than one card fewer. The homepage filters on this rather than
     * drawing a placeholder.
     */
    public function drawable(): bool
    {
        return trim((string) $this->image) !== '';
    }

    /**
     * Is there a SEPARATE picture for phones on this slide?             (SEC)
     *
     * The owner gave two sizes — "for desktop the size should be 1920 x 550 and
     * in mobile 500 x 600" — which is two pictures. False here is not an error
     * state: it is the ordinary case for every slide made before this column
     * existed, and the slider falls back to the desktop picture in both frames,
     * asking for the width that covering the phone frame actually needs.
     *
     * A PHONE PICTURE ALONE IS NOT DRAWABLE. `drawable()` above is the gate the
     * storefront filters on and it reads `image`, so a slide with a phone
     * picture and no desktop one draws nothing at all rather than half of
     * itself on one breakpoint — which is why this method is deliberately not
     * part of that gate.
     */
    public function hasPhonePicture(): bool
    {
        return trim((string) $this->image_m) !== '';
    }

    /**
     * The desktop picture's own `[width, height]`, or null.          (Lane RC)
     *
     * The stored columns first -- they are read off the file once, when the
     * picture is saved -- and the FILE ITSELF only when they are empty, which
     * is a row written before the columns existed or by an import that did not
     * fill them. Both the single-image banner and a slider's `auto` frame are
     * sized from this, so it is never measured in the browser.
     *
     * @return array{0: int, 1: int}|null
     */
    public function naturalSize(): ?array
    {
        return self::sizeFrom((string) $this->image, $this->image_w, $this->image_h);
    }

    /** The same for the phone picture, or null when there is none. */
    public function phoneNaturalSize(): ?array
    {
        if (! $this->hasPhonePicture()) {
            return null;
        }

        return self::sizeFrom((string) $this->image_m, $this->image_m_w, $this->image_m_h);
    }

    /** @var array<string, array{0: int, 1: int}|null> one header read per file per process */
    private static array $sizes = [];

    /**
     * Drop the memo. Registered in tests/Support/StaticMemos, because a
     * process-level static survives a test: a later test writing a different
     * file at the same path would otherwise be handed the first one's size.
     */
    public static function forgetSizes(): void
    {
        self::$sizes = [];
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private static function sizeFrom(string $path, mixed $w, mixed $h): ?array
    {
        if ((int) $w > 0 && (int) $h > 0) {
            return [(int) $w, (int) $h];
        }

        $path = trim($path);

        if ($path === '') {
            return null;
        }

        /*
         * MEMOISED PER PATH, because getimagesize() opens the file and the
         * homepage asks for the first picture more than once per render. A
         * miss is memoised too: a remote or missing picture is not retried.
         */
        if (! array_key_exists($path, self::$sizes)) {
            self::$sizes[$path] = \App\Support\ImageVariants::sizeOf(\App\Support\ImageVariants::rootRelative($path));
        }

        return self::$sizes[$path];
    }
}
