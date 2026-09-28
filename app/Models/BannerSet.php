<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One named cards banner, with the controls that belong to it.
 *
 * ── THE ENUMS LIVE HERE, AND THAT IS RULE 5 ─────────────────────────────────
 *
 * "A select stores one of its own options or the default." These four constants
 * ARE those option sets: the admin draws its dropdowns from them, the controller
 * validates against `array_keys()` of them, and the template looks a stored
 * value up in them and falls back to the default when it is not there. A value
 * that is not one of its own options therefore cannot survive a write, cannot
 * survive a read, and cannot reach the page even if somebody puts it in the
 * table by hand — three doors, because the storefront is the one that matters
 * and it is the one furthest from the validator.
 *
 * Every one of them maps its stored token to a CSS FRAGMENT, not to markup, and
 * the fragment is a constant in this file. Nothing an operator types is ever
 * printed into the `<style>` the section emits.
 */
class BannerSet extends Model
{
    protected $fillable = [
        'name', 'slug', 'status', 'position',
        'autoplay', 'speed_ms', 'animation', 'per_view', 'peek', 'gap',
        'card_radius', 'show_arrows', 'show_dots', 'pause_on_hover',
        'ratio', 'show_text', 'show_button', 'shadow',
    ];

    protected $casts = [
        'position' => 'int',
        'autoplay' => 'bool',
        'speed_ms' => 'int',
        'per_view' => 'int',
        'peek' => 'int',
        'gap' => 'int',
        'card_radius' => 'int',
        'show_arrows' => 'bool',
        'show_dots' => 'bool',
        'pause_on_hover' => 'bool',
        'show_text' => 'bool',
        'show_button' => 'bool',
    ];

    /**
     * The card shapes, `token => [label, the aspect-ratio value]`.
     *
     * The owner's reference is a tall portrait card, so `3/4` is the default —
     * but it is a CONTROL rather than one number chosen forever, because the
     * same row is a banner strip on one shop and a poster wall on another.
     *
     * The value on the right is written into `aspect-ratio` as-is and is a
     * literal in this file. Storing "the ratio" as two numbers an operator types
     * would put arithmetic the browser performs behind a box anybody can put
     * `1/0` in.
     */
    public const RATIOS = [
        '3/4' => ['Portrait — 3 : 4 (the tall card)', '3 / 4'],
        '2/3' => ['Tall portrait — 2 : 3', '2 / 3'],
        '4/5' => ['Soft portrait — 4 : 5', '4 / 5'],
        '1/1' => ['Square — 1 : 1', '1 / 1'],
        '4/3' => ['Landscape — 4 : 3', '4 / 3'],
        '16/9' => ['Wide — 16 : 9', '16 / 9'],
    ];

    /**
     * How the row moves, `token => [label, the CSS animation-name]`.
     *
     * Three, and no more, because each has to be a real CSS animation over the
     * doubled track and a fourth that cannot be written that way would be a
     * dropdown entry that does nothing — the fault CLAUDE.md names three times.
     *
     * `off` is not "no animation-name": it is the token that makes the row a
     * plain hand-scrolled rail, which is also what reduced motion turns the
     * other two into.
     */
    public const ANIMATIONS = [
        'slide' => ['Glide left — continuous, seamless', 'kbbn-slide'],
        'slide_reverse' => ['Glide right — continuous, seamless', 'kbbn-slide-rev'],
        'off' => ['Still — the shopper scrolls it by hand', ''],
    ];

    /** `token => [label, the box-shadow]`. */
    public const SHADOWS = [
        'none' => ['None — flat', 'none'],
        'soft' => ['Soft — the shop’s own card shadow', '0 10px 30px -18px rgba(42,34,40,.45)'],
        'lift' => ['Lifted — deeper, for a dark background', '0 18px 44px -20px rgba(42,34,40,.62)'],
    ];

    public const STATUSES = ['publish' => 'Published', 'draft' => 'Draft'];

    /** The bounds every numeric control is clamped to, `column => [min, max]`. */
    public const LIMITS = [
        'speed_ms' => [600, 20000],
        'per_view' => [1, 8],
        'peek' => [0, 90],
        'gap' => [0, 48],
        'card_radius' => [0, 40],
        'position' => [0, 9999],
    ];

    public function cards(): HasMany
    {
        return $this->hasMany(BannerCard::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The `aspect-ratio` value, or the default's.
     *
     * The third door described in the class header: a row edited straight in
     * the database to `ratio = '; }'` gets `3 / 4`, so no operator string can
     * reach the `<style>` element even by a path the controller never saw.
     */
    public function ratioCss(): string
    {
        return (self::RATIOS[$this->ratio] ?? self::RATIOS['3/4'])[1];
    }

    public function shadowCss(): string
    {
        return (self::SHADOWS[$this->shadow] ?? self::SHADOWS['soft'])[1];
    }

    /** The CSS animation-name, or '' when the row does not move. */
    public function animationCss(): string
    {
        return (self::ANIMATIONS[$this->animation] ?? self::ANIMATIONS['slide'])[1];
    }

    /**
     * Does this set actually animate?
     *
     * Two switches say so and both have to agree: `autoplay` is the owner's
     * on/off and `animation` is which way it goes, and `off` is a real choice in
     * that list rather than a second spelling of autoplay=false. Asking it in
     * one place keeps the template, the preview and the test from each deciding
     * it differently.
     */
    public function animates(): bool
    {
        return $this->autoplay && $this->animationCss() !== '';
    }
}
