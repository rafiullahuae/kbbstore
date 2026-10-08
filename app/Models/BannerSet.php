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
        // Lane BP, round 7. Every one ships at what the page already draws.
        'bg_mode', 'bg_color', 'bg_image', 'btn_bg', 'btn_text', 'btn_hover', 'title_pos',
        // Lane BN2, round 8. The other three are read only by a slider.
        //
        // ▲ `kind` SHIPPED AT `cards` AND NOW SHIPS AT `slider`.     (Lane SEC)
        // The three defaults below are the whole of that move at the model
        // level; the rows that already exist are moved by the migration
        // `banner_ships_as_image_slider`.
        'kind', 'slider_style', 'slider_ratio', 'slider_ratio_m',
        // Lane RC. How a slider fits its pictures, and the two height caps.
        'slider_fit', 'slider_h', 'slider_h_m',
        // Lane HB. The slider's text box, set-wide: one JSON document that only
        // App\Support\BannerTextBox reads or writes.
        'text_box',
    ];

    /**
     * WHAT A SET IS BEFORE ANYBODY HAS CHOSEN ANYTHING.
     *
     * Three moved defaults, all of them the owner's own words:            (SEC)
     *
     *   kind            cards      -> slider      "not cards, simple only
     *                                              images banner"
     *   slider_ratio    16/9       -> 1920/550    "for desktop the size should
     *                                              be 1920 x 550"
     *   slider_ratio_m  4/3        -> 500/600     "and in mobile 500 x 600"
     *
     * ON THE MODEL AND NOT ON THE COLUMN, deliberately. `->change()` on three
     * string columns runs differently on SQLite (a table rebuild) and on MySQL
     * (an in-place ALTER), and a rebuild of `banner_sets` inside an update
     * package is a risk taken for nothing: nothing in this application inserts
     * into the table except through this model, so the column default is never
     * the value that wins. The migration writes the EXISTING rows and this
     * writes every row made from here on; between them there is no row left
     * holding the old shape.
     *
     * @var array<string, string>
     */
    /*
     * ── AND TWO MORE, WHICH ARE THE BANNER'S CORNERS AND ITS SHADOW ─────────
     *                                                            (Lane BG)
     *   card_radius     18       -> 0           "by default, make the main
     *   shadow          'soft'   -> 'none'       images banner full width, and
     *                                            remove the corner radius etc."
     *
     * The "etc." is the SHADOW, and it is here rather than left out because a
     * drop shadow is the second thing that stops a picture reading as edge to
     * edge: with the radius gone but the shadow kept, the banner is a square
     * card floating a few pixels off the page instead of part of it. It is
     * still a control — Appearance → Banners → (a set) → Shadow — so putting it
     * back is one dropdown.
     *
     * BOTH COLUMNS ARE SHARED WITH THE `cards` KIND, which is worth saying
     * because it means a new CARDS set made from here on also starts square.
     * `kind` itself defaults to `slider` two lines up, so a set made without
     * choosing anything is the thing these two are chosen for; and the
     * migration that moves the rows already on the shop is scoped to sliders,
     * so an existing cards set keeps the corners it was given.
     */
    /*
     * ── AND FOUR MORE, WHICH ARE "NOTHING IS EVER CUT" ─────────── (Lane RC)
     *
     *   slider_ratio    1920/550 -> auto        "image should adjust auto with
     *   slider_ratio_m  500/600  -> auto         the screen without cutting etc."
     *   slider_fit      (new)       contain
     *   slider_h/_h_m   (new)       0 = Auto     "there should be height control
     *                                             of the overall banner"
     *
     * `auto` is the first picture's OWN shape, so a 1920 x 550 upload still
     * draws a 1920 : 550 frame -- his numbers are kept whenever his art is
     * those numbers -- and art that is only roughly that shape is no longer
     * cropped to fit a preset. `contain` is the half that covers every other
     * picture in the set: fitted whole, never cut. Both ship ON because he
     * asked for them (CLAUDE.md rule 1, reversed 30 September); both are still
     * controls on Appearance -> Banners -> (a set) -> Size & fit.
     */
    protected $attributes = [
        'kind' => 'slider',
        'slider_ratio' => 'auto',
        'slider_ratio_m' => 'auto',
        'slider_fit' => 'contain',
        'slider_h' => 0,
        'slider_h_m' => 0,
        'card_radius' => 0,
        'shadow' => 'none',
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
        'slider_h' => 'int',
        'slider_h_m' => 'int',
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

    /* ══════════════════════ LANE BN2 — THE SECOND KIND ═══════════════════════ */

    /**
     * What a set IS, `token => label`.
     *
     * `cards` FIRST and it is the default, because it is what every row in this
     * table was before this column existed — rule 1, and the reason applying
     * the package moves nothing. `kind()` below is the third door: a row edited
     * straight in the database to a kind nobody issued draws `cards`.
     *
     * The two kinds share this table deliberately rather than getting one each.
     * The owner's words were "another banner type", not "another screen": the
     * sets list, the homepage picker, the module switch, the preview, the
     * publish flag and the background are the same facts about the same thing,
     * and a second table would have meant a second copy of all six.
     */
    public const KINDS = [
        'cards' => 'Cards — a row of picture cards that scrolls itself',
        'slider' => 'Slider — pictures only, one at a time, with arrows and bars',
        /*
         * Lane RC. The owner: "i need here option single image ... in case of
         * single image, the height will be as per the image height itself".
         * One picture, full width, its height following from its own
         * proportions -- no frame shape to choose, so nothing to crop.
         */
        'single' => 'Single image — one picture, shown whole at its own height',
    ];

    /** The partial each kind draws through. CONSTANTS, never a built string. */
    public const KIND_PARTIALS = [
        'cards' => 'partials.home.cards-banner',
        'slider' => 'partials.home.slider-banner',
        'single' => 'partials.home.single-banner',
    ];

    /**
     * The homepage section's top padding, per kind.                 (Lane RC)
     *
     * The owner: "remove any space between header and banner". Measured in
     * Chromium before the fix: the header's bottom edge at y=93.5 (1280) and
     * y=127 (390), the banner picture's top at 101.5 and 135 -- an 8px strip
     * of page background at both widths, and the whole of it was the
     * section's inline `padding-top:8px` in store/home.blade.php.
     *
     * The two PICTURE kinds sit flush under the header. The cards row keeps
     * its 8px: it is a row of rounded cards, not a banner, the owner did not
     * ask about it, and a shop drawing cards renders the same bytes as before.
     *
     * A CONSTANT, printed into a `style` attribute, so it is never a setting.
     */
    public const SECTION_STYLES = [
        'cards' => 'padding-top:8px',
        'slider' => 'padding-top:0',
        'single' => 'padding-top:0',
    ];

    /**
     * How a slider fills its frame, `token => label`.                (Lane RC)
     *
     * `contain` FIRST and it is the default, because the owner asked for it:
     * "image should adjust auto with the screen without cutting". `cover` is
     * what every slider drew before this column existed and stays one choice
     * away -- a control he may want back, which is the reason it is built.
     */
    public const SLIDER_FITS = [
        'contain' => 'Whole picture — never cut',
        'cover' => 'Fill the frame — edges may be cut',
    ];

    /** The `auto` frame-shape token: the first picture's own proportions. */
    public const SLIDER_AUTO = 'auto';

    /**
     * The four treatments of the slider, `token => [label, the note]`.
     *
     * The owner asked for "some nice previews to chooose from" and has twice
     * sent back options that were too alike. These four vary the three things
     * that actually read at thumbnail size — WHERE THE ARROWS SIT, HOW THE BARS
     * READ, and WHETHER THE BARS ARE ON THE PICTURE OR UNDER IT — rather than
     * the radius and the shade of white.
     *
     * Every one of them is the SAME MARKUP. The token becomes one class on the
     * outer element and the stylesheet does the rest, so there is no fourth
     * template to keep in step and no treatment that quietly has a feature the
     * others do not.
     */
    public const SLIDER_STYLES = [
        'inset' => ['On the picture — round arrows, full-width bars',
            'Round arrows over the picture at each edge, and the bars are segments running the whole width along the bottom of it. The boldest of the four.'],
        'outside' => ['Beside the picture — arrows outside, ticks below',
            'The arrows sit outside the picture so nothing covers it, and the bars are short ticks centred underneath. The quietest of the four.'],
        'veil' => ['Clean — arrows on hover, one filling rail',
            'Nothing over the picture until the mouse is on it; underneath, one thin rail whose current segment fills as the picture rests. On a phone the arrows are always there, because there is no hover.'],
        'corner' => ['Cornered — a joined pair, bars opposite',
            'Both arrows together as one capsule in the bottom corner, with the bars as short thick ticks in the other. The most compact of the four.'],
    ];

    /**
     * The frame shapes a SLIDER may take, `token => [label, the aspect-ratio]`.
     *
     * Its own list rather than RATIOS, and that is not duplication. RATIOS is
     * the CARD shape — portrait, because a row of six cards is portrait — and
     * it has no entry wider than 16:9 because a card never is. A banner is the
     * other way round: 21:9 and 3:1 are the ordinary shapes and 3:4 is the odd
     * one. Sharing the list would have meant either a banner with no wide
     * option or a card list with two entries that make no sense in it.
     *
     * The value on the right is written into `aspect-ratio` as-is and is a
     * literal in this file, exactly as RATIOS' is.
     */
    public const SLIDER_RATIOS = [
        /*
         * ── AUTO, FIRST, AND THE DEFAULT ─────────────────────────── (Lane RC)
         *
         * Its CSS half is EMPTY on purpose: the value is not a constant, it is
         * the first picture's stored width and height, two integers, and
         * sliderRatioCss() builds `<int> / <int>` from them. Nothing an
         * operator types reaches the stylesheet by this road either.
         */
        'auto' => ['Auto — the picture’s own shape, nothing cut', ''],
        /*
         * ── THE TWO THE OWNER ASKED FOR BY NUMBER, FIRST ────────────────────
         *
         * "for desktop the size should be 1920 x 550 and in mobile 500 x 600".
         * Those are 3.49 : 1 and 5 : 6 — a letterbox wider than the widest
         * preset here was, and a PORTRAIT phone frame. Neither is within
         * rounding of anything below: the nearest were 3 : 1 (3.00 against
         * 3.49, so a 16% error) and 4 : 5 (0.80 against 0.83).
         *
         * WRITTEN AS THE PIXELS HE GAVE rather than reduced. `1920 / 550` and
         * `500 / 600` are what `aspect-ratio` takes, they reduce to 192/55 and
         * 5/6 which name nothing, and a label carrying his own numbers is the
         * one he can check against the file he uploads.
         */
        '1920/550' => ['Banner — 1920 × 550', '1920 / 550'],
        '500/600' => ['Phone banner — 500 × 600', '500 / 600'],
        '3/1' => ['Ultra-wide — 3 : 1', '3 / 1'],
        '21/9' => ['Cinematic — 21 : 9', '21 / 9'],
        '2/1' => ['Wide — 2 : 1', '2 / 1'],
        '16/9' => ['Widescreen — 16 : 9', '16 / 9'],
        '3/2' => ['Photo — 3 : 2', '3 / 2'],
        '4/3' => ['Classic — 4 : 3', '4 / 3'],
        '1/1' => ['Square — 1 : 1', '1 / 1'],
        '4/5' => ['Portrait — 4 : 5', '4 / 5'],
    ];

    /**
     * What sits behind the whole row, `token => label`.
     *
     * `none` FIRST and it is the default, because it is what every set drew
     * before this control existed — rule 1, on the most visible page in the
     * shop. The other two read a column each, and a mode whose column is empty
     * falls back to `none` rather than painting a black band.
     */
    public const BG_MODES = [
        'none' => 'None — the page’s own background shows through',
        'color' => 'A colour',
        'image' => 'A picture',
    ];

    /**
     * Where the heading and its line sit, `token => label`.
     *
     * `below` is the band under the picture, which is what shipped. `over`
     * lays the same band on the bottom of the picture with a scrim behind it —
     * the card is the same height either way, because the height comes from
     * `aspect-ratio` and from nothing else, which is the rule the whole section
     * is built on.
     */
    public const TITLE_POSITIONS = [
        'below' => 'Below the picture — a band under it',
        'over' => 'On the picture — over the bottom of it',
    ];

    /** The bounds every numeric control is clamped to, `column => [min, max]`. */
    public const LIMITS = [
        'speed_ms' => [600, 20000],
        'per_view' => [1, 8],
        'peek' => [0, 90],
        'gap' => [0, 48],
        'card_radius' => [0, 40],
        'position' => [0, 9999],
        /*
         * Lane RC. The banner's height cap in CSS pixels, 0 = Auto (no cap:
         * the frame's shape decides the height at every width). See
         * sliderHeight() for why it is a CAP and not a fixed height.
         */
        'slider_h' => [0, 1000],
        'slider_h_m' => [0, 1000],
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

    /**
     * The `box-shadow`, or the default's.
     *
     * ▲ THE FALLBACK MOVED FROM `soft` TO `none` WITH THE DEFAULT. (Lane BG)
     *
     * This is the third door the class header describes, and a door has to
     * agree with the other two or it is a way in. `$attributes['shadow']` is
     * `none` and the migration writes `none` over every slider row holding
     * `soft`, so a row that reaches here with a token the enum does not carry —
     * hand-edited, or written by a release older than this one — would
     * otherwise be the one place on the shop that still draws the old shadow.
     */
    public function shadowCss(): string
    {
        return (self::SHADOWS[$this->shadow] ?? self::SHADOWS['none'])[1];
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

    /**
     * The background mode this set will really draw, or 'none'.
     *
     * THE FOURTH DOOR, and the same one `ratioCss()` opens for the ratio: a row
     * whose `bg_mode` was edited straight in the database to something that is
     * not one of its own options gets `none`. A mode whose own column is empty
     * also gets `none`, because "colour: (nothing)" is not a colour and painting
     * the fallback would be the section deciding something the owner did not.
     */
    public function bgMode(): string
    {
        $mode = (string) $this->bg_mode;

        if (! isset(self::BG_MODES[$mode])) {
            return 'none';
        }

        if ($mode === 'color' && ! \App\Support\Color::isValidHex((string) $this->bg_color)) {
            return 'none';
        }

        if ($mode === 'image' && trim((string) $this->bg_image) === '') {
            return 'none';
        }

        return $mode;
    }

    /** `below` or `over`, and anything else is `below`. */
    public function titlePosition(): string
    {
        return isset(self::TITLE_POSITIONS[(string) $this->title_pos]) ? (string) $this->title_pos : 'below';
    }

    /* ══════════════════════ LANE BN2 — THE SECOND KIND ═══════════════════════ */

    /**
     * `cards` or `slider`, and ANYTHING ELSE IS `cards`.
     *
     * The same third door `ratioCss()` and `bgMode()` open, and here it is the
     * one that carries rule 1: a row whose `kind` column is null — which is
     * every row on a server where the migration added the column without a
     * backfill, and every row hydrated by a test that predates it — draws the
     * cards banner it has always drawn. There is no state of this table in
     * which a set silently becomes a slider.
     */
    /**
     * ▲ THE FALLBACK MOVED FROM 'cards' TO 'slider'.                (Lane SEC)
     *
     * The owner: "the banner i need to change to simple image banners, not
     * cards, simple only images banner". A row with no `kind` at all, or with
     * a value that is not a key of KINDS, is now a picture slider. The rows
     * that hold the string 'cards' are moved by the migration rather than by
     * this method, because 'cards' IS a key of KINDS and this branch never
     * sees it.
     */
    public function kind(): string
    {
        return isset(self::KINDS[(string) $this->kind]) ? (string) $this->kind : 'slider';
    }

    public function isSlider(): bool
    {
        return $this->kind() === 'slider';
    }

    /** Lane RC. One picture, whole, at its own height. */
    public function isSingle(): bool
    {
        return $this->kind() === 'single';
    }

    /**
     * The homepage section's `style` attribute for this kind.       (Lane RC)
     *
     * A lookup in SECTION_STYLES, so what is printed is one of three literals.
     */
    public function homeSectionStyle(): string
    {
        return self::SECTION_STYLES[$this->kind()];
    }

    /**
     * The Blade partial this set draws through.
     *
     * ── A LOOKUP IN A CONSTANT, NEVER A BUILT STRING ────────────────────────
     *
     * `'partials.home.'.$this->kind.'-banner'` would be shorter and it would be
     * a template name assembled from a database column, which is a file path
     * assembled from a database column. `kind()` has already narrowed the value
     * to one of KINDS' keys and this maps those literals to literals, so the
     * set of view names this method can ever return is fixed -- three since
     * Lane RC added `single` -- and is visible in this file.
     *
     * The homepage, the stored preview and the buffered preview all ask this
     * one method, so a set cannot be drawn as a slider in the admin and as
     * cards on the shop.
     */
    public function homePartial(): string
    {
        return self::KIND_PARTIALS[$this->kind()];
    }

    /** One of SLIDER_STYLES' keys, or the default. */
    public function sliderStyle(): string
    {
        return isset(self::SLIDER_STYLES[(string) $this->slider_style]) ? (string) $this->slider_style : 'inset';
    }

    /**
     * Does this treatment's current bar FILL as the picture rests?
     *
     * One token does, and the answer lives here rather than as
     * `$set->sliderStyle() === 'veil'` in the template — for the reason this
     * class's own header gives about the enums: a treatment renamed, or a
     * second filling one added, would otherwise need finding in a Blade file.
     * SLIDER_STYLES is the list; this is the one property of it the markup
     * needs to ask about.
     */
    public function sliderFills(): bool
    {
        return $this->sliderStyle() === 'veil';
    }

    /**
     * The desktop `aspect-ratio` value for a slider, or the shipped shape's.
     *
     * ▲ THE FALLBACK MOVED FROM 16/9 TO 1920/550, and it is a moved default
     * rather than a tidy-up.                                       (Lane SEC)
     *
     * The owner: "for desktop the size should be 1920 x 550 and in mobile 500
     * x 600". Under the reversed rule 1 that is the shape the shop SHIPS at,
     * not a preset he has to go and pick, so the value a row falls back to
     * when it names nothing — every row created before the column existed, and
     * every row whose stored value is not a key of SLIDER_RATIOS — is his.
     *
     * The migration `banner_ships_as_image_slider` writes the same two strings
     * onto the rows that still hold `16/9` / `4/3`, so a set the owner has
     * never opened and a set he saved at the old default both land on his
     * numbers. This fallback is the half that covers a row the migration could
     * not see; the migration is the half that covers a row this method is
     * never asked about because the column holds a valid older key.
     */
    public function sliderRatioCss(array $cards = []): string
    {
        $key = $this->sliderRatioKey(false);

        return $key === self::SLIDER_AUTO
            ? self::autoRatioCss($cards, false)
            : self::SLIDER_RATIOS[$key][1];
    }

    /** The same, below 768px. Moved from 4/3 to 500/600 in the same change. */
    public function sliderRatioMobileCss(array $cards = []): string
    {
        $key = $this->sliderRatioKey(true);

        return $key === self::SLIDER_AUTO
            ? self::autoRatioCss($cards, true)
            : self::SLIDER_RATIOS[$key][1];
    }

    /**
     * The stored frame-shape token, or `auto`.                      (Lane RC)
     *
     * ▲ THE FALLBACK MOVED FROM 1920/550 (and 500/600) TO `auto`, with the
     * default it mirrors -- the third door has to agree with the other two or
     * it is a way in. With no pictures to read, `auto` answers the shipped
     * presets, so a set with nothing in it draws exactly what it drew before.
     */
    public function sliderRatioKey(bool $phone): string
    {
        $raw = (string) ($phone ? $this->slider_ratio_m : $this->slider_ratio);

        return isset(self::SLIDER_RATIOS[$raw]) ? $raw : self::SLIDER_AUTO;
    }

    /**
     * `auto`'s aspect-ratio: the FIRST picture's own `<w> / <h>`.    (Lane RC)
     *
     * The first one because the frame is one box and the first picture is the
     * one the page opens on -- and the LCP element. Every later picture is
     * fitted into that box by `slider_fit`, which under the shipped `contain`
     * means whole, never cut, whatever its shape.
     *
     * Phone: the slide's own phone picture when it has one; otherwise the
     * desktop picture, which is what the phone then draws. Built from two
     * integers (stored on save, or read off the file once -- see
     * BannerCard::naturalSize()), so the string is digits, a slash and spaces.
     * Unknown on both counts falls back to the shipped preset.
     *
     * @param  list<BannerCard>  $cards
     */
    private static function autoRatioCss(array $cards, bool $phone): string
    {
        $first = $cards[0] ?? null;
        $size = null;

        if ($first instanceof BannerCard) {
            $size = ($phone ? $first->phoneNaturalSize() : null) ?? $first->naturalSize();
        }

        if ($size === null) {
            return self::SLIDER_RATIOS[$phone ? '500/600' : '1920/550'][1];
        }

        return $size[0].' / '.$size[1];
    }

    /**
     * `contain` or `cover`, and anything else is `contain`.         (Lane RC)
     */
    public function sliderFit(): string
    {
        return isset(self::SLIDER_FITS[(string) $this->slider_fit]) ? (string) $this->slider_fit : 'contain';
    }

    /**
     * The slider's height cap in CSS pixels, or 0 for Auto.         (Lane RC)
     *
     * ── A CAP, NOT A FIXED HEIGHT, AND THAT IS THE DECISION ─────────────────
     *
     * "height control of the overall banner" and "image should adjust auto with
     * the screen without cutting" have to be true at once. A FIXED height can
     * only keep the second promise by wasting room: a 1920 x 550 picture is
     * 229px tall on an 800px window, so a fixed 450px banner is either a
     * picture cropped to a sliver (cover) or 220px of empty band (contain).
     * A CAP keeps both: below it the banner is the picture's own shape and
     * shrinks with the screen; at it, the banner stops growing and the picture
     * is fitted whole inside it, centred, with what is behind the banner on
     * either side. Written as `max-height` on the frame, beside its
     * `aspect-ratio` -- one declaration, no script.
     *
     * Clamped here as well as on save, so a hand-edited row cannot print a
     * number outside LIMITS; a positive value under 80 reads as 80, because a
     * 5px banner is a typo, not a design.
     */
    public function sliderHeight(bool $phone = false): int
    {
        $raw = (int) ($phone ? $this->slider_h_m : $this->slider_h);
        [, $max] = self::LIMITS[$phone ? 'slider_h_m' : 'slider_h'];

        return $raw <= 0 ? 0 : max(80, min($max, $raw));
    }

    /**
     * Does the phone frame take a SERVER-MADE CROP of the desktop picture?
     *                                                               (Lane RC)
     *
     * Only when the set crops at all (`cover`) and the phone frame is a fixed
     * preset. Under `contain` the phone draws the whole picture, and under
     * `auto` the frame IS the picture's shape -- a crop would cut exactly what
     * the owner asked never to be cut. The storefront and the admin's crop
     * writer both ask this one method.
     */
    public function sliderCropsPhone(): bool
    {
        return $this->sliderFit() === 'cover' && $this->sliderRatioKey(true) !== self::SLIDER_AUTO;
    }

    /**
     * The phone frame's shape as a cache-directory token — `500x600`.
     *                                                               (Lane SEC)
     *
     * Taken from the KEY of SLIDER_RATIOS and not from anything an operator
     * typed: the accessor below falls back to a shipped preset for an unknown
     * key, so what comes out of here is always one of this class's own
     * constants with its slash turned into an `x`.
     *
     * ImageVariants::cropDir() checks it again with a strict pattern, and that
     * is deliberate rather than belt-and-braces: this value becomes a PATH
     * SEGMENT under the web root, and a path segment assembled from a setting
     * is how a cache directory becomes a traversal. Two locks, one of which is
     * in the class that builds the path.
     */
    public function sliderRatioMobileToken(): string
    {
        // `auto` is a key and NOT a shape, so it answers the shipped phone
        // preset here, exactly as an unknown key does. Nothing crops under
        // `auto` (sliderCropsPhone() says no), so this only keeps the token
        // a valid directory name for the one older caller that asks blind.
        $key = isset(self::SLIDER_RATIOS[(string) $this->slider_ratio_m])
            && (string) $this->slider_ratio_m !== self::SLIDER_AUTO
            ? (string) $this->slider_ratio_m
            : '500/600';

        return str_replace('/', 'x', $key);
    }

    /** The desktop frame's width divided by its height. */
    public function sliderRatioValue(array $cards = []): float
    {
        return self::ratioValue($this->sliderRatioCss($cards));
    }

    /** The same, below 768px. */
    public function sliderRatioMobileValue(array $cards = []): float
    {
        return self::ratioValue($this->sliderRatioMobileCss($cards));
    }

    /**
     * `'1920 / 550'` as 3.4909…                                   (Lane SEC)
     *
     * ── PARSED FROM THE CSS STRING AND NOT FROM THE KEY, DELIBERATELY ───────
     *
     * The key and the value are two different spellings of the same ratio and
     * only one of them is what the browser lays out with. Parsing the CSS is
     * therefore the version that cannot disagree with the frame: if a later
     * preset is ever written with a key and a value that do not match, the
     * arithmetic here follows the pixels rather than the label.
     *
     * It is also total. Every value in SLIDER_RATIOS is `<int> / <int>` and
     * both accessors above fall back to a shipped preset for an unknown key, so
     * the string can only be one of this class's own — but a zero denominator
     * would be a division by zero on the front page, so it is guarded and
     * answers 0.0, which bannerSliderCoverSizes() reads as "do not compute".
     */
    private static function ratioValue(string $css): float
    {
        $parts = array_map('trim', explode('/', $css));

        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return 0.0;
        }

        $height = (float) $parts[1];

        return $height <= 0.0 ? 0.0 : (float) $parts[0] / $height;
    }
}
