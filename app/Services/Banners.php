<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Models\Media as MediaUrl;
use App\Support\Color;
use Illuminate\Support\Facades\DB;

/**
 * Appearance → Banners → Cards banner. The module, and the homepage's reader.
 *
 * Phase 22, Lane BN. The owner: "I need multiple cards type with auto scroll
 * smooth scroll ... need full backend controls under Appearance > Banners >
 * cards banner, we can turn on off card banners ... and the whole section will
 * have full control options to choose which banner will show on homepage".
 *
 * ── TWO THINGS LIVE IN THE MODULE FRAMEWORK, AND ONLY TWO ───────────────────
 *
 *   `module_toggles.cards_banner`   the section's on/off
 *   `module_settings.set`           which set the homepage draws
 *
 * Everything else the owner called "inside each banner section" is a column on
 * `banner_sets`, because SCHEMA holds one value per key for the WHOLE SHOP and
 * those controls are per set. The migration's header has the argument in full.
 *
 * ── THE HOMEPAGE COSTS ONE QUERY, AND IT IS ONE QUERY RATHER THAN TWO ───────
 *
 * `StorefrontQueryBudgetTest` gives the homepage 5 and the brief allows this
 * section ONE more. `BannerSet::with('cards')` is the obvious answer and it is
 * TWO — the set, then the cards — which spends the whole allowance on the shape
 * of the code rather than on the feature. So `forHome()` is a single JOIN that
 * brings the set's columns back alongside its cards, and the models are
 * hydrated from that one result set.
 *
 * It is also FLAT, which is the property that file says a budget alone cannot
 * prove: the query does not grow with the number of cards, the number of sets,
 * or the size of the catalogue. CardsBannerQueryCostTest measures it at 3 cards
 * and again at 24 and asserts the two counts are the same number.
 *
 * ── AND IT COSTS NOTHING AT ALL WHEN THE MODULE IS OFF ──────────────────────
 *
 * `enabled()` is read first and short-circuits, and it reads the module
 * snapshot every storefront page has already taken. A shop that has not turned
 * this on pays for this feature exactly what it paid before the package
 * applied.
 */
class Banners
{
    /** The ModuleRegistry key, and the `module_settings` bucket. */
    public const MODULE = 'cards_banner';

    /**
     * The one shop-wide setting: which set the homepage draws.
     *
     * A SELECT over the sets that exist, so rule 5's "a select stores one of its
     * own options or the default" is `ModuleSchema::cast()`'s job here rather
     * than this file's — the same trade `SectionDividers::overrides()` makes for
     * its section picker, and for the same reason: the option set lives in
     * another store and naming it in `overrides()` is what lets ModuleSchema
     * check it.
     *
     * `''` IS A REAL OPTION and it is the default. The module ships off AND with
     * nothing chosen, so turning the switch on without picking a set still draws
     * nothing rather than guessing at the first row in the table.
     */
    public const SCHEMA = [
        'set' => ['type' => 'select', 'label' => 'Which banner shows on the homepage', 'default' => '',
            'help' => 'Only published sets can be chosen. Leave it on “None” and the homepage draws nothing at all, even with the section switched on.',
            'options' => ['' => 'None — nothing shows on the homepage'],
            'store' => ModuleSchema::STORE_MODULE],
    ];

    public const TABS = [
        'homepage' => ['On the homepage', 'Which of your banner sets the front page shows.', ['set']],
    ];

    /**
     * This module's point on ModuleSchema's policy axes.
     *
     * `invalid => default` is the one that matters: a hand-rolled POST of
     * `set=<script>` — or of the id of a set that was deleted a minute ago —
     * stores `''` and the homepage draws nothing, instead of coming back in a
     * `rejected` list a screen then has to explain. `blank => keep` because ''
     * is the "None" option and putting the default back over it would make
     * "show nothing" unselectable.
     */
    public const POLICY = [
        'max' => 20,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'bool' => 'cast',
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * The option set the positional SCHEMA has no slot for: the sets that exist.
     *
     * ── AND IT IS THE ADMIN'S READER, NEVER THE STOREFRONT'S ────────────────
     *
     * This costs a query. The screen and the guard can afford one; the homepage
     * cannot, which is the whole of the budget note in this class's header. So
     * `forHome()` does NOT cast the stored id through these options — it hands
     * it to the JOIN as a bound parameter, and the join's own `status =
     * 'publish'` is what refuses a set that must not be drawn. A deleted id, a
     * drafted id and a string of nonsense all return no rows and all draw
     * nothing, which is the same answer the cast would give for one more query.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function overrides(): array
    {
        $options = ['' => 'None — nothing shows on the homepage'];

        foreach (BannerSet::query()->orderBy('position')->orderBy('id')->get(['id', 'name', 'status']) as $set) {
            // A draft is listed and marked rather than hidden: an owner who has
            // drafted the set he meant to show should be told why the homepage
            // is empty, not left to wonder which of his sets vanished.
            $options[(string) $set->id] = $set->status === 'publish'
                ? (string) $set->name
                : $set->name.' (draft — will not show)';
        }

        return ['set' => ['options' => $options]];
    }

    /**
     * The one storefront reader of the module switch.
     *
     * A LITERAL KEY AND NOT self::MODULE. ModuleFrameworkGuardTest tokenises
     * app/ and resources/views/ for `moduleEnabled()` with a
     * T_CONSTANT_ENCAPSED_STRING first argument — that is how it proves a `live`
     * registry row has a real reader rather than trusting the row. Written
     * `moduleEnabled(self::MODULE, false)` the call is invisible to it, which is
     * the fault that guard exists to catch: `seo_engine` and `product_sorting`
     * both shipped that way.
     *
     * `false` is the default, so a store that has saved nothing is OFF.
     */
    public function enabled(): bool
    {
        return $this->settings->moduleEnabled('cards_banner', false);
    }

    /**
     * Every module setting, each through cast(), with the shipped default for
     * anything unsaved.
     *
     * NOT `ModuleSchema::read()`, for the reason UgcSettings spells out at
     * length: read() and write() are POLICY-BLIND — both call normalise() with
     * no policy argument, so every field resolves to DEFAULT_POLICY's
     * `invalid => reject`, and a `set` posted as the id of a set that has since
     * been deleted would come back as an error instead of quietly becoming
     * "None".
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $out = [];

        foreach (ModuleSchema::normalise(self::SCHEMA, self::POLICY, self::overrides()) as $key => $field) {
            $saved = $this->settings->moduleSetting(self::MODULE, $key, null);

            $out[$key] = $saved === null ? $field['default'] : ModuleSchema::cast($field, $saved);
        }

        return $out;
    }

    /**
     * Writes the keys the payload actually carries, each through cast().
     *
     * Only keys in the schema, so an unknown key cannot ride in; only keys
     * present, so a screen that posts one control does not blank another. A
     * refused value is RETURNED rather than dropped.
     *
     * @param  array<string, mixed>  $values
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public function save(array $values): array
    {
        $fields = ModuleSchema::normalise(self::SCHEMA, self::POLICY, self::overrides());
        $written = [];
        $rejected = [];

        foreach ($values as $key => $value) {
            if (! isset($fields[$key])) {
                continue;
            }

            $cast = ModuleSchema::cast($fields[$key], $value);

            if ($cast === null) {
                $rejected[$key] = (string) $fields[$key]['label'];

                continue;
            }

            $this->settings->setModuleSetting(self::MODULE, $key, $cast);
            $written[] = $key;
        }

        return ['written' => $written, 'rejected' => $rejected];
    }

    /**
     * The set the homepage draws and its published cards, in ONE query, or null.
     *
     * Returns `[BannerSet, list<BannerCard>]`, both hydrated from the same
     * result set and neither of them saved anywhere — they exist to be read by
     * the template.
     *
     * The three ways this returns null are all "draw nothing", and no caller has
     * to tell them apart: the module is off, no set is chosen, or the chosen set
     * is missing / drafted / empty of drawable cards.
     *
     * @return array{0: BannerSet, 1: list<BannerCard>}|null
     */
    public function forHome(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $chosen = (string) ($this->settings->moduleSetting(self::MODULE, 'set', '') ?? '');

        // ctype_digit rather than is_numeric: `1e3`, ` 12` and `-4` are all
        // numeric and none of them is an id this shop ever issued. A value that
        // is not a plain positive integer never reaches the database at all.
        if ($chosen === '' || ! ctype_digit($chosen) || (int) $chosen < 1) {
            return null;
        }

        return $this->load((int) $chosen);
    }

    /**
     * The same read, for a set named directly — the admin preview's door.
     *
     * The preview has to draw a DRAFT set, because a draft is precisely what the
     * owner is looking at while he builds it. `$anyStatus` is the one difference
     * between the two readers and it is a parameter rather than a second copy of
     * the query, so the preview cannot drift from the shop.
     *
     * @return array{0: BannerSet, 1: list<BannerCard>}|null
     */
    public function forPreview(int $id): ?array
    {
        return $this->load($id, true);
    }

    /**
     * @return array{0: BannerSet, 1: list<BannerCard>}|null
     */
    private function load(int $id, bool $anyStatus = false): ?array
    {
        /*
         * ── ONE QUERY, AND THE COLUMN LIST IS EXPLICIT ──────────────────────
         *
         * `select('banner_sets.*', 'banner_cards.*')` would be shorter and it
         * would be wrong twice over: two `id` columns collide and whichever the
         * driver returns second wins, and a column added to either table later
         * would start arriving here without anybody deciding it should. Named
         * columns are also what makes the set's attributes separable from the
         * card's below.
         */
        $setColumns = [
            'id', 'name', 'slug', 'status', 'position', 'autoplay', 'speed_ms',
            'animation', 'per_view', 'peek', 'gap', 'card_radius', 'show_arrows',
            'show_dots', 'pause_on_hover', 'ratio', 'show_text', 'show_button', 'shadow',
            // Lane BP. NAMED HERE OR THE STOREFRONT NEVER SEES THEM: this list
            // is the whole of what forHome() hydrates, so a column added to the
            // table and not added here reaches the admin screen and the preview
            // (which read the model) and silently does nothing on the shop.
            'bg_mode', 'bg_color', 'bg_image', 'btn_bg', 'btn_text', 'btn_hover', 'title_pos',
            // Lane BN2, and the same warning applies word for word: this list
            // is the whole of what forHome() hydrates. `kind` missing from it
            // is a slider that draws as cards on the shop and as a slider in
            // the admin preview — the exact "reaches the screen and silently
            // does nothing" fault the note above was written for.
            'kind', 'slider_style', 'slider_ratio', 'slider_ratio_m',
            // Lane RC, and the warning above applies a third time: the fit and
            // the two height caps reach the shop through this list or not at
            // all. BannerSingleImageTest drives the stored value to the page.
            'slider_fit', 'slider_h', 'slider_h_m',
            // Lane HB. Same row, same query: the text box adds no query.
            'text_box',
        ];

        $select = ['banner_cards.'.'id as c_id'];

        /*
         * ▲ THE PHONE PICTURE'S THREE COLUMNS JOIN THE LIST, AND THE WARNING
         *   OVER $setColumns APPLIES TO THIS ONE WORD FOR WORD.     (Lane SEC)
         *
         * This list is the whole of what forHome() hydrates. A column added to
         * `banner_cards` and not added here reaches the admin screen and the
         * preview — which read the model — and silently does nothing on the
         * shop. That is the exact fault `kind` shipped with once, and a phone
         * picture that appears in the console and never on a phone would be the
         * same fault with a longer feedback loop, because the owner would only
         * find it by looking at his own site on his own handset.
         */
        foreach ([
            'image', 'alt', 'heading', 'body', 'button_label', 'button_url',
            'image_w', 'image_h', 'position',
            'image_m', 'image_m_w', 'image_m_h',
            // Lane HB, the words on a slider picture.
            'box_on', 'box_pos', 'eyebrow', 'sticker', 'sticker_ring',
            'eyebrow_ar', 'heading_ar', 'body_ar', 'button_label_ar', 'sticker_ar', 'sticker_ring_ar',
        ] as $c) {
            $select[] = 'banner_cards.'.$c.' as c_'.$c;
        }

        foreach ($setColumns as $c) {
            $select[] = 'banner_sets.'.$c.' as s_'.$c;
        }

        $query = DB::table('banner_cards')
            ->join('banner_sets', 'banner_sets.id', '=', 'banner_cards.banner_set_id')
            ->where('banner_sets.id', $id)
            ->where('banner_cards.status', 'publish')
            ->where('banner_cards.image', '<>', '')
            ->orderBy('banner_cards.position')
            ->orderBy('banner_cards.id')
            ->select($select);

        if (! $anyStatus) {
            $query->where('banner_sets.status', 'publish');
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $first = (array) $rows->first();

        $set = new BannerSet;
        $setAttributes = [];

        foreach ($setColumns as $c) {
            $setAttributes[$c] = $first['s_'.$c];
        }

        /*
         * forceFill + syncOriginal, and `exists` left FALSE on purpose. These
         * models are a view over one query's rows; nothing in the storefront may
         * save them, and an instance that believes it exists is one `->save()`
         * away from writing the homepage's read back into the table.
         */
        $set->forceFill($setAttributes)->syncOriginal();

        $cards = [];

        foreach ($rows as $row) {
            $row = (array) $row;

            $card = new BannerCard;

            $card->forceFill([
                'id' => $row['c_id'],
                'banner_set_id' => $setAttributes['id'],
                'image' => $row['c_image'],
                'alt' => $row['c_alt'],
                'heading' => $row['c_heading'],
                'body' => $row['c_body'],
                'button_label' => $row['c_button_label'],
                'button_url' => $row['c_button_url'],
                'image_w' => $row['c_image_w'],
                'image_h' => $row['c_image_h'],
                'image_m' => $row['c_image_m'],
                'image_m_w' => $row['c_image_m_w'],
                'image_m_h' => $row['c_image_m_h'],
                'box_on' => $row['c_box_on'],
                'box_pos' => $row['c_box_pos'],
                'eyebrow' => $row['c_eyebrow'],
                'sticker' => $row['c_sticker'],
                'sticker_ring' => $row['c_sticker_ring'],
                'eyebrow_ar' => $row['c_eyebrow_ar'],
                'heading_ar' => $row['c_heading_ar'],
                'body_ar' => $row['c_body_ar'],
                'button_label_ar' => $row['c_button_label_ar'],
                'sticker_ar' => $row['c_sticker_ar'],
                'sticker_ring_ar' => $row['c_sticker_ring_ar'],
                'position' => $row['c_position'],
                'status' => 'publish',
            ])->syncOriginal();

            $cards[] = $card;
        }

        return [$set, $cards];
    }

    /**
     * The custom properties the row is sized and timed with.
     *
     * ── AND THE REASON THERE IS NO MEASURING JAVASCRIPT ANYWHERE HERE ───────
     *
     * Rule 4 forbids the element-measuring APIs by name and this is the
     * sanctioned answer rather than a workaround for it. Every number a card
     * needs is a `calc()` input on the viewport element, so the browser lays the
     * row out ONCE from the stylesheet and lays it out again by itself when the
     * window changes size. There is no resize listener because there is nothing
     * for one to recompute.
     *
     * `--kbbn-per-lg` and `--kbbn-peek-lg` carry the LARGE-SCREEN numbers, and
     * the suffix is load-bearing rather than decorative: these are written as an
     * inline `style` attribute, an inline declaration beats every stylesheet
     * rule including one inside a media query, so a media query cannot narrow
     * `--kbbn-per` if the server has written that name directly. The stylesheet
     * therefore owns `--kbbn-per`, sets it to the phone's value, and only at the
     * widest breakpoint assigns it FROM the `-lg` property this writes.
     *
     * ── THE DURATION IS PER CARD, MULTIPLIED HERE ───────────────────────────
     *
     * `speed_ms` is the time to travel ONE card width; the animation is one
     * `translateX(-50%)` over a track holding the list twice, so the CSS
     * duration is `speed_ms × cards`. Stored as a loop time instead, a set with
     * four cards and one with thirty would move at wildly different speeds under
     * the same number.
     *
     * @param  list<BannerCard>  $cards
     */
    public static function cssVariables(BannerSet $set, array $cards): string
    {
        $count = max(1, count($cards));

        $per = self::clamp($set->per_view, ...BannerSet::LIMITS['per_view']);
        $peek = self::clamp($set->peek, ...BannerSet::LIMITS['peek']);
        $gap = self::clamp($set->gap, ...BannerSet::LIMITS['gap']);
        $radius = self::clamp($set->card_radius, ...BannerSet::LIMITS['card_radius']);
        $speed = self::clamp($set->speed_ms, ...BannerSet::LIMITS['speed_ms']);

        $vars = [
            '--kbbn-per-lg:'.$per,
            // Two decimals, printed from an integer this method has already
            // clamped, so the declaration cannot carry anything but digits.
            '--kbbn-peek-lg:'.number_format($peek / 100, 2, '.', ''),
            '--kbbn-gap:'.$gap.'px',
            '--kbbn-r:'.$radius.'px',
            '--kbbn-ar:'.$set->ratioCss(),
            '--kbbn-sh:'.$set->shadowCss(),
            '--kbbn-dur:'.number_format(($speed * $count) / 1000, 2, '.', '').'s',
        ];

        /*
         * ── THE FOUR OPERATOR COLOURS, AND WHY EACH IS OMITTED WHEN EMPTY ──
         *
         * Rule 1 on the most visible page in the shop. Every one of these ships
         * at '' and the template's fallback is what the button already draws —
         * `var(--pink,#E8919F)` and `#fff`. Written as an empty custom property
         * the fallback would NOT apply (an empty value is a value), so the
         * declaration is left out of the string entirely instead.
         *
         * `hex()` is the gate: it returns '' for anything Color::isValidHex()
         * refuses, so what is printed here is `#` and three or six hex digits
         * or nothing at all. No operator string reaches the style attribute.
         */
        foreach ([
            '--kbbn-btn-bg' => $set->btn_bg,
            '--kbbn-btn-tx' => $set->btn_text,
            '--kbbn-btn-hv' => $set->btn_hover,
        ] as $property => $raw) {
            $hex = self::hex($raw);

            if ($hex !== '') {
                $vars[] = $property.':'.$hex;
            }
        }

        return implode(';', $vars);
    }

    /**
     * What goes behind the WHOLE row, on the section's own element.
     *
     * ── AND IT IS A SECOND METHOD BECAUSE IT IS A SECOND ELEMENT ────────────
     *
     * `cssVariables()` is written onto `.kbbn-vp`, the scroller. A custom
     * property inherits DOWNWARDS and only downwards, so a background declared
     * on `.kbbn` — the section's outer box, which is where the bleed and the
     * padding live — cannot see anything written on the box inside it.
     *
     * The first version of this round put all of it on the scroller and the
     * background did not paint at all: `has-bg` was on the element, the rule
     * matched, and `background-color:var(--kbbn-bg,transparent)` resolved to
     * the fallback because `--kbbn-bg` was two levels out of reach. A
     * screenshot found it — `background.color` came back `rgba(0,0,0,0)` with
     * the class present — which is the whole reason rule 2 asks for measured
     * numbers rather than "it works".
     *
     * Returns '' for a set with no background, and the template then writes no
     * `style` attribute on that element at all — rule 1, and the reason the
     * byte-identity comparison in CardsBannerAppearanceTest holds.
     */
    public static function sectionVariables(BannerSet $set): string
    {
        $mode = $set->bgMode();

        if ($mode === 'color') {
            return '--kbbn-bg:'.self::hex($set->bg_color);
        }

        if ($mode === 'image') {
            return '--kbbn-bgimg:'.self::cssUrl(self::imageUrl((string) $set->bg_image));
        }

        return '';
    }

    /**
     * The custom properties ONE SLIDER is sized, timed and pointed with.
     *
     * Lane BN2. The companion to `cssVariables()` above and it obeys the same
     * two rules: every value is either an integer this method has clamped or a
     * literal looked up in one of BannerSet's own enums, so nothing an operator
     * can type reaches the `style` attribute; and the whole geometry is
     * arithmetic the browser does once, so there is nothing for a script to
     * measure.
     *
     * ── `--kbbs-dirn` IS HOW THE SLIDER RUNS BACKWARDS ON /ar ───────────────
     *
     * The track is moved with `translateX`, and `transform` is PHYSICAL: there
     * is no logical transform, so `translateX(-100%)` walks left in Arabic as
     * well and the slider would run the wrong way. The three answers are a
     * `[dir="rtl"]` rule, a `:dir(rtl)` rule, or a number.
     *
     * This is the number, and it is the right answer here rather than the
     * cheap one. A `[dir]` rule is a SECOND place the direction is decided —
     * `<html dir>` is already written from `Locale::direction()`, and a
     * stylesheet that re-reads the attribute can disagree with the server that
     * wrote it (an admin preview iframe has no `<html dir>` of its own, which
     * is exactly where this would have gone wrong). Everything else about the
     * slider — where the arrows sit, which way the bars run, which end the
     * progress fills from — is a logical property or a flex direction and
     * mirrors by itself with no rule at all.
     *
     *   `--kbbs-flip`   1 / -1, and it only ever multiplies a scaleX: it turns
     *                   the chevron round so "previous" points at the inline
     *                   start in both directions.
     *   `--kbbs-dirn`   -1 / 1, the sign of the track's travel.
     *
     * ── AND `--kbbs-dwell` IS A PER-SLIDE REST, NOT A LOOP TIME ─────────────
     *
     * `speed_ms` is the same column the cards banner reads and it means the
     * same thing in both: the time attached to ONE item. For a slider that is
     * how long a picture rests before the next one comes, so a set of three and
     * a set of twelve feel the same under the same number.
     *
     * @param  list<BannerCard>  $cards
     */
    public static function sliderVariables(BannerSet $set, array $cards, bool $rtl): string
    {
        $radius = self::clamp($set->card_radius, ...BannerSet::LIMITS['card_radius']);
        $dwell = self::clamp($set->speed_ms, ...BannerSet::LIMITS['speed_ms']);

        $vars = [
            // Lane RC: the cards are passed so `auto` can read the first
            // picture's own width and height -- two integers, never a string.
            '--kbbs-ar:'.$set->sliderRatioCss($cards),
            '--kbbs-arm:'.$set->sliderRatioMobileCss($cards),
            '--kbbs-r:'.$radius.'px',
            '--kbbs-sh:'.$set->shadowCss(),
            // Printed from an integer this method has already clamped, so the
            // declaration cannot carry anything but digits and a dot.
            '--kbbs-dwell:'.number_format($dwell / 1000, 2, '.', '').'s',
            '--kbbs-dirn:'.($rtl ? '1' : '-1'),
            '--kbbs-flip:'.($rtl ? '-1' : '1'),
        ];

        /*
         * ── THE HEIGHT CAPS, OMITTED AT AUTO ──────────────────────── (Lane RC)
         *
         * Printed from sliderHeight(), which clamps to an integer, so the
         * declaration is digits and `px`. At 0 (Auto) the property is LEFT OUT
         * rather than written as `none`, so the stylesheet's own fallback is
         * what applies -- the same idiom as the button colours above.
         */
        foreach (['--kbbs-hd' => false, '--kbbs-hm' => true] as $property => $phone) {
            $h = $set->sliderHeight($phone);

            if ($h > 0) {
                $vars[] = $property.':'.$h.'px';
            }
        }

        return implode(';', $vars);
    }

    /**
     * The custom properties the SINGLE-IMAGE banner is drawn with.  (Lane RC)
     *
     * Two, and both are the columns the other two kinds already share: the
     * corner radius (an integer clamped to LIMITS) and the shadow (a literal
     * out of BannerSet::SHADOWS). There is no shape and no height here,
     * because the picture's own proportions are the height -- that is the
     * whole of what the owner asked this kind to be.
     */
    public static function singleVariables(BannerSet $set): string
    {
        $radius = self::clamp($set->card_radius, ...BannerSet::LIMITS['card_radius']);

        return '--kbbi-r:'.$radius.'px;--kbbi-sh:'.$set->shadowCss();
    }

    /**
     * The milliseconds a slider rests on one picture, or 0 when it does not
     * move at all.
     *
     * ONE PLACE DECIDES IT, for the reason `animates()` gives on the model: the
     * template writes this into a data attribute, the script reads it, and 0 is
     * the single spelling of "there is no autoplay" — the set said no, or there
     * is only one picture and there is nowhere to go. A script that had to ask
     * two questions would eventually answer them differently from the stylesheet.
     *
     * @param  list<BannerCard>  $cards
     */
    public static function sliderDwell(BannerSet $set, array $cards): int
    {
        if (! $set->autoplay || count($cards) < 2) {
            return 0;
        }

        return self::clamp($set->speed_ms, ...BannerSet::LIMITS['speed_ms']);
    }

    /**
     * A colour a set may print into CSS, as `#rrggbb` or `#rgb`, or ''.
     *
     * `Color::isValidHex()` is the allowlist and it is deliberately the ONLY
     * test: it matches `#?([0-9a-f]{3}|[0-9a-f]{6})` anchored at both ends, so
     * a value it accepts cannot carry a semicolon, a brace, a `url(`, a comment
     * opener or whitespace. The `#` is added rather than assumed, because that
     * pattern makes it optional and a bare `e0567b` printed into a declaration
     * is not a colour — it is an invalid token that kills the whole declaration.
     *
     * CLAUDE.md records that this helper once accepted a hex nobody could see:
     * that was about CONTRAST, not about syntax, and it is why the screen draws
     * a real swatch beside every one of these boxes rather than a text field.
     */
    public static function hex(?string $raw): string
    {
        $value = trim((string) $raw);

        if (! Color::isValidHex($value)) {
            return '';
        }

        return '#'.ltrim($value, '#');
    }

    /**
     * A URL as a CSS `url()` token, with everything that could end the token
     * percent-encoded.
     *
     * ── WHY NOT THE HTML ESCAPER, WHICH IS THE BUG NEXT DOOR ────────────────
     *
     * Lane SX is fixing this exact class of defect across twelve storefront
     * files as this is written: a CSS `url()` built with `e()` / `{{ }}`.
     * HTML escaping turns `"` into `&quot;`, which a CSS parser reads as six
     * literal characters and NOT as a closing quote — so the escaper both fails
     * to terminate the injection and corrupts the path. The two grammars are
     * different and the escaper has to match the one it is writing into.
     *
     * So this encodes for CSS: every character outside an unreserved,
     * path-shaped set becomes `%XX`. `"`, `'`, `(`, `)`, `\`, `;`, `{`, `}`,
     * whitespace and every control character are therefore impossible in the
     * output, which is what makes the surrounding `url("…")` unclosable.
     *
     * The input is already narrow — `bg_image` holds a path MediaRegistrar
     * allowlisted — and this does not rely on that. Two doors, the same trade
     * BannerSet's enum header describes.
     *
     * ▲ WHEN LANE SX'S SHARED HELPER LANDS, THIS SHOULD BECOME A CALL TO IT.
     *   It is written here because that helper does not exist in this tree yet
     *   and the alternative was to ship the escaper that is being removed.
     */
    public static function cssUrl(string $url): string
    {
        $safe = preg_replace_callback(
            '/[^A-Za-z0-9\-._~:\/?#\[\]@!$&*+,=]/',
            static fn (array $m): string => rawurlencode($m[0]),
            $url
        );

        return 'url("'.$safe.'")';
    }

    private static function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }

    /**
     * A URL a card's button may point at, or '' — rule 5, applied to a setting.
     *
     * ── THE DECODE-AND-STRIP IS THE PART THAT DOES THE WORK ─────────────────
     *
     * The same check `App\Support\RichText::url()` makes, written here because
     * that one is private to its own class and because this value takes a
     * different road to the page: RichText is guarding an `href` it parsed out
     * of stored markup, this is guarding an `href` an operator typed into a box.
     * The reasoning is RichText's and is repeated rather than referenced,
     * because a reader of this method has to be able to see why the order of the
     * three steps matters:
     *
     * a browser resolves `jav&#x09;ascript:alert(1)` to a javascript URL, and a
     * naive `str_starts_with($url, 'javascript:')` sees a string starting "jav&"
     * and waves it through. So entities are decoded FIRST, then every whitespace
     * and C0/C1 control character is removed, and only THEN is the scheme read —
     * the check runs on the same string the browser will.
     *
     * A protocol-relative `//evil.test` carries no scheme and is not relative
     * either; it is refused by name.
     *
     * Returns '' rather than null for the one thing this is for: the template
     * asks `=== ''` to decide whether to draw a button at all, and a card whose
     * URL was refused draws NO BUTTON rather than a dead one.
     */
    public const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function safeUrl(?string $raw): string
    {
        $url = trim((string) $raw);

        if ($url === '') {
            return '';
        }

        $probe = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = (string) preg_replace('/[\s\x00-\x1F\x7F-\x9F]+/u', '', $probe);
        $probe = strtolower($probe);

        if (str_starts_with($probe, '//')) {
            return '';
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m) === 1) {
            return in_array($m[1], self::SAFE_SCHEMES, true) ? $url : '';
        }

        // No scheme at all: a path, a query or a fragment. Safe by construction,
        // and Url::to() gives it this shop's base path at the template.
        return $url;
    }

    /**
     * The URL a card's stored path is served from.
     *
     * `Media::urlFor()` and not `Url::to()`, for the reason that method's own
     * header records: an admin upload's path begins `uploads/` and is served
     * from `site_url`, which ALREADY carries any subfolder the app lives under.
     * Url::to() would add the base path a second time — the double-prefix bug
     * this repository has fixed twice already.
     */
    public static function imageUrl(string $path): string
    {
        return MediaUrl::urlFor($path);
    }
}
