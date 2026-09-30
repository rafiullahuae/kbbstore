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
    ];

    protected $casts = [
        'banner_set_id' => 'int',
        'image_w' => 'int',
        'image_h' => 'int',
        'position' => 'int',
        'image_m_w' => 'int',
        'image_m_h' => 'int',
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
}
