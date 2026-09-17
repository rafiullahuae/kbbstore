<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Brand;
use App\Support\Money;
use App\Support\TrustClaims;
use Illuminate\Support\Facades\Cache;

/**
 * What the homepage SAYS, as opposed to which of its sections appear.
 *
 * ── THE DEFECT THIS EXISTS FOR ──────────────────────────────────────────────
 *
 * Appearance → Homepage already owns whether each of the seventeen sections
 * renders, on which device, and in which grid skin. Nothing owned their words.
 *
 * Four keys are READ by the storefront and WRITTEN BY NOTHING — not by
 * AdminController::SETTING_RULES, not by any module endpoint, not by a seeder,
 * and by no ->set() anywhere in the tree:
 *
 *   home_banners   the hero slider. Three slides of marketing copy compiled
 *                  into HomeController, carrying the page's only <h1>, shown
 *                  to every visitor of every install, unchangeable and
 *                  unremovable from any screen.
 *   about_text     the About us paragraph.
 *   home_ticker    the scrolling promo chip. Absent by default (deliberately —
 *                  see 2026_11_04_000000_clear_caches_storefront_claims), so
 *                  the section an owner can switch on could never say anything.
 *   site_title     the quiet <h1> used when the hero is off. NOT handled here;
 *                  it is also read by store/review-wall.blade.php and belongs
 *                  with the site-wide settings, not with this screen.
 *
 * This is the same shape of fault TrustClaims was built for, one section along:
 * a sentence about the business, typed into a template, on a host where
 * changing a template needs a signed package. The remedy is the same one —
 * the shipped wording becomes the DEFAULT, so nothing changes for a shop that
 * leaves it alone, and clearing the box means SAY NOTHING rather than print an
 * empty element.
 *
 * ── REUSING THE SETTINGS SCHEMAS, WHICH IS THE WHOLE DESIGN ─────────────────
 *
 * Nothing below invents a way to describe a field. The two constants are
 * ModuleSchema-shaped, so the same normalise()/fields()/cast()/describe() that
 * draw and validate PayShipRules and MarketingPixels draw and validate these,
 * and the console renders them with the generic control renderer it already
 * has. A second vocabulary here would be a screen that disagrees with the one
 * next to it.
 *
 * The one thing ModuleSchema does not model is a REPEATER, because no module
 * needed one: it maps key => field against one settings row each. A hero is a
 * LIST of slides. So the list is the new part and the SLIDE is not: each slide
 * is one instance of SLIDE_SCHEMA, cast field by field through
 * ModuleSchema::cast() and rendered through ModuleSchema::fields(). The repeat
 * is a count, not a second field language.
 *
 * ── WHY THE GRADIENTS ARE COLOURS AND NOT THE CSS THEY WERE ─────────────────
 *
 * The stored slide used to carry `gradient` and `panel` as whole CSS strings,
 * printed straight into a style attribute by home.blade.php. That is fine for a
 * literal in a controller and a stylesheet-injection sink the moment an owner
 * can type into it. All three shipped slides have the SAME two shapes —
 * `linear-gradient(118deg,A,B 58%,C)` and `linear-gradient(150deg,D,E)` — so
 * the shape stays here and only the five colours are the owner's. They are
 * `colour` fields, which ModuleSchema::cast() already refuses unless they match
 * /^#[0-9a-f]{6}$/. Nothing an owner types can reach the attribute.
 *
 * `heading` is the same argument in the other direction. home.blade.php printed
 * it unescaped, because the defaults carry a <br>. An owner-editable unescaped
 * field is stored XSS on the shop's front page. The field is plain text with
 * NEWLINES now, and the template escapes it and converts the newlines — which
 * renders the shipped headings byte-identically and closes the hole.
 *
 * `url` is the third. Url::to() passes anything with a scheme through
 * untouched, javascript: included, so a hero button is a link the owner types
 * and a shopper clicks. Only a path beginning with a single slash is accepted.
 */
final class HomepageContent
{
    /** Enough for a slider nobody has to scroll twice; a bound, not a target. */
    public const MAX_SLIDES = 6;

    /**
     * The two CSS shapes a slide's colours are poured into, with the field keys
     * as placeholders.
     *
     * ONE DEFINITION, READ BY BOTH SIDES. render() below substitutes it for the
     * storefront; the console's preview is handed this same string in the
     * payload and substitutes it for the box on screen. So "the preview cannot
     * drift from what ships" is structural here rather than a promise — there
     * is no second copy of the shape to drift, and the console names not one of
     * the five colour keys.
     *
     * Both shapes are the ones all three shipped slides already had, so this is
     * a transcription and not a redesign.
     */
    public const CSS = [
        'gradient' => 'linear-gradient(118deg,{bg_from},{bg_mid} 58%,{bg_to})',
        'panel' => 'linear-gradient(150deg,{card_from},{card_to})',
    ];

    /**
     * Figures a slide may quote, which the shop MEASURES somewhere else.
     *
     * ── WHY A TOKEN AND NOT A NUMBER ────────────────────────────────────────
     *
     * Two of the three shipped slides stated things this shop counts elsewhere
     * on the same page. "93 brands · sourced direct" sat forty lines above a
     * strip that counts the real number off the catalogue — eight, on the
     * preview database — and the free-delivery figure was a literal in a slide
     * sitting directly above a band and a ticker that had both already been
     * repaired to read Store → Shipping. One page, three answers, and the
     * loudest one was the one nobody could edit.
     *
     * HomeController's own header records the same fault being removed from
     * three other statements on this page: `max($brandTotal, 93)` and
     * `max($catalogueCount, 671)` overstated the size of the shop in the About
     * band and the shop filters, and the invented review summary was taken out
     * with them. This is the last survivor of that set, and it is removed the
     * same way — by making the sentence read the counter rather than quote it.
     *
     * A TOKEN RATHER THAN A HARD-WIRED SENTENCE, because the wording around the
     * figure is the owner's and only the figure is the shop's. The eyebrow can
     * become "{brands} Korean brands, all checked" and still be true tomorrow;
     * deleting the token leaves a sentence with no number in it, which is also
     * allowed. What is not available any more is typing a number of your own
     * and having the page repeat it forever.
     *
     * AN UNRESOLVABLE TOKEN DROPS ITS LINE, not the whole field and not the
     * token alone. `{free_from}` has no answer for a shopper in a country this
     * shop has no free-shipping rule for — the delivery band and the ticker
     * below already drop their own half in exactly that case, and this is the
     * same decision one band up. Dropping the LINE is what lets the shipped
     * third slide keep "Split any order into four.", which is true everywhere,
     * while losing the sentence that is not. Nothing is invented to fill the
     * gap and no owner copy is deleted for a shopper the figure is true for.
     */
    public const TOKENS = [
        '{brands}' => 'How many brands the catalogue holds, counted — the figure the brand strip on this page prints.',
        '{free_from}' => 'The free-delivery threshold for this visitor, from Store → Shipping. A line quoting it is dropped where the shop has no such rule.',
    ];

    /**
     * One hero slide. ModuleSchema field definitions, so the console's generic
     * renderer draws them and ModuleSchema::cast() validates them.
     */
    public const SLIDE_SCHEMA = [
        'kicker' => [
            'type' => 'text',
            'label' => 'Eyebrow',
            'default' => '',
            'help' => 'The small line above the headline. Leave it empty and nothing is shown. {brands} prints how many brands the shop carries, counted.',
        ],
        'heading' => [
            'type' => 'textarea',
            'label' => 'Headline',
            'default' => '',
            'help' => 'Press Enter for a line break. The first slide’s headline is this page’s only <h1>, so write it as the sentence that says what the shop sells.',
        ],
        'text' => [
            'type' => 'textarea',
            'label' => 'Supporting line',
            'default' => '',
            'help' => '{brands} and {free_from} print the shop’s own figures. A line quoting a figure the shop has no answer for is dropped rather than guessed at.',
        ],
        'button' => [
            'type' => 'text',
            'label' => 'Button wording',
            'default' => '',
            'help' => 'Empty hides the button. The whole slide is still a link.',
        ],
        'url' => [
            'type' => 'text',
            'label' => 'Where the slide links',
            'default' => '/shop/',
            'help' => 'A page on this shop, starting with a slash — /shop/, /brands/, /shop/?on_sale=1.',
        ],
        'bg_from' => ['type' => 'colour', 'label' => 'Background · from', 'default' => '#F7C6D4', 'help' => ''],
        'bg_mid' => ['type' => 'colour', 'label' => 'Background · middle', 'default' => '#E0567B', 'help' => ''],
        'bg_to' => ['type' => 'colour', 'label' => 'Background · to', 'default' => '#A82F53', 'help' => ''],
        'card_from' => ['type' => 'colour', 'label' => 'Inset panel · from', 'default' => '#FFE6EE', 'help' => ''],
        'card_to' => ['type' => 'colour', 'label' => 'Inset panel · to', 'default' => '#EFA8BE', 'help' => ''],
    ];

    /**
     * The flat copy this screen owns, outside the slider.
     *
     * `store => 'setting'` and not 'admin': both are written by this class's own
     * endpoint through SettingsService::set(), so AdminController::updateSettings()
     * — and therefore SETTING_RULES, and therefore its silent drop of any key
     * that is not on it — is not in the path at all. ModuleSchema's header
     * documents that as the reason the distinction exists.
     */
    public const SCHEMA = [
        'home_ticker' => [
            'type' => 'text',
            'label' => 'Promo ticker chip',
            'default' => '',
            'store' => 'setting',
            'help' => 'The first chip in the scrolling strip under the hero. Empty means no chip — the delivery chips beside it still scroll. Nothing is invented to fill it.',
        ],
        'about_text' => [
            'type' => 'textarea',
            'label' => 'About us paragraph',
            'default' => '',
            'store' => 'setting',
            'help' => 'The paragraph in the About us band. Clear it and the paragraph is dropped; the heading, the counted figures and the link stay.',
        ],
    ];

    /**
     * tab => [label, description, keys] — the shape ModuleSchema::tabs() reads.
     *
     * The hero is NOT a tab here, and that is deliberate rather than an
     * omission: a tab in this constant is a group of FLAT keys, and the hero's
     * fields belong to a slide rather than to the screen. It is drawn from
     * `slide_fields` instead, once per slide, out of the same SLIDE_SCHEMA. A
     * tab declaring zero fields would be the screen telling ModuleSchema a
     * thing that is not true about it.
     */
    public const TABS = [
        'copy' => ['Other wording', 'Sentences elsewhere on the homepage that had no screen.', ['home_ticker', 'about_text']],
    ];

    /**
     * The three slides that shipped, in the new field shape.
     *
     * Carried across from HomeController verbatim, including the <br> in each
     * headline, which becomes a newline: nl2br() over the escaped value renders
     * the identical markup, so a shop that has saved nothing sees no change.
     *
     * THEY ARE DEFAULTS, NOT TRUTHS — and two of the three claims that made
     * that sentence necessary are now derived rather than asserted (Lane FR).
     * "93 brands" was counted on the brands strip forty lines below and quoted
     * here as a literal; it is `{brands}` now, and reads the same counter. The
     * free-delivery number is owned by Store → Shipping, per zone; it is
     * `{free_from}` now, and a line quoting it is dropped where the shop has no
     * such rule. See TOKENS.
     *
     * WHAT IS STILL ONLY A DEFAULT is the third: "Shop the Super Sale" and
     * "Medicube · limited-time offer" advertise a sale and an offer on every
     * fresh install, and NOTHING in this application knows whether either is
     * running. There is no counter to read, so no number is invented and the
     * owner's copy is not deleted — it stays the editable default it became,
     * and whether to remove it is the owner's call.
     * docs/FO-HOMEPAGE-INVENTORY.md §2 names all three.
     */
    public const DEFAULT_SLIDES = [
        [
            'kicker' => 'Medicube · limited-time offer',
            'heading' => "Age-R Booster Pro\nwith a free gift set",
            'text' => 'The device everyone is asking about, bundled with the PDRN glow set.',
            'button' => 'Shop Medicube',
            'url' => '/shop/',
            'bg_from' => '#F7C6D4', 'bg_mid' => '#E0567B', 'bg_to' => '#A82F53',
            'card_from' => '#FFE6EE', 'card_to' => '#EFA8BE',
        ],
        [
            'kicker' => '{brands} brands · sourced direct',
            'heading' => "The authentic\nK-beauty store",
            'text' => 'Every product original, every order checked. Freebies with every parcel.',
            'button' => 'Shop all brands',
            'url' => '/brands/',
            'bg_from' => '#CDE6DA', 'bg_mid' => '#3E8F6E', 'bg_to' => '#2A6A50',
            'card_from' => '#E4F3EC', 'card_to' => '#9FCBB6',
        ],
        [
            'kicker' => 'Tabby · Tamara · COD',
            'heading' => "Pay later,\ndelivered in 1–3 days",
            'text' => "Split any order into four.\nFree delivery across the UAE over {free_from}.",
            'button' => 'Shop the Super Sale',
            'url' => '/shop/?on_sale=1',
            'bg_from' => '#FFE1A8', 'bg_mid' => '#E8A33D', 'bg_to' => '#C07F1E',
            'card_from' => '#FFF2D9', 'card_to' => '#EFCE8A',
        ],
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * The slides the storefront should render, each with its two gradients
     * already built.
     *
     * A shop that has saved nothing gets DEFAULT_SLIDES. A shop that has saved
     * an EMPTY list gets an empty list — "no hero" is a decision an owner is
     * allowed to make, and home.blade.php already renders no slider and
     * promotes the quiet <h1> when the list is empty.
     *
     * @return list<array<string, string>>
     */
    public function slides(): array
    {
        $figures = $this->figures();

        return array_map(fn (array $s) => $this->fill($this->render($s), $figures), $this->editable());
    }

    /**
     * What each token resolves to for THIS request, or null for "no answer".
     *
     * Both come from the reader that already owns the figure, not from a second
     * copy of the query or of the setting:
     *
     *   {brands}     the same Cache key HomeController counts the brand strip
     *                from, so the eyebrow and the strip forty lines below it
     *                cannot print two different numbers. Reading it here costs
     *                the page nothing — whichever of the two runs first fills
     *                the entry and the other reads it.
     *   {free_from}  ShippingService::thresholdHere(), which is what the
     *                delivery band and the promo ticker inside this same hero
     *                were repaired to use, memoised on the Request.
     *
     * Money::plain() and NOT Money::format(): format() returns a <span>, and
     * the supporting line is printed through {{ }}. The markup would appear on
     * the banner as text.
     *
     * A count of zero is NO ANSWER rather than the number nought. "0 brands ·
     * sourced direct" is arithmetically true and reads as a broken page, which
     * is the rule the delivery band beside it already follows for an empty
     * sentence.
     *
     * @return array<string, string|null>
     */
    public function figures(): array
    {
        $brands = self::brandTotal();
        $free = app(ShippingService::class)->thresholdHere();

        return [
            '{brands}' => $brands > 0 ? (string) $brands : null,
            '{free_from}' => $free === null ? null : Money::plain($free, 0),
        ];
    }

    /**
     * How many brands the catalogue holds.
     *
     * THE SAME CACHE KEY HomeController uses for the brand strip's own tally,
     * deliberately: this method is now the one reader and the controller calls
     * it, so there is no second COUNT(*) and no way for the two figures on the
     * page to disagree. HomeController::flushCache() already forgets this key.
     */
    public static function brandTotal(): int
    {
        return (int) Cache::remember('kbb.home.brandcount', 900, fn () => Brand::query()->count());
    }

    /**
     * Substitute the figures into the four fields a shopper reads.
     *
     * Line by line, so an unresolved token takes its own sentence with it and
     * leaves the rest of the field standing. `url` and the five colours are not
     * touched: a token in a link or a colour would be a value the validator has
     * already refused, and substituting into either is how a text field becomes
     * an injection sink.
     *
     * @param  array<string, string|null>  $figures
     */
    private function fill(array $slide, array $figures): array
    {
        foreach (['kicker', 'heading', 'text', 'button'] as $key) {
            $value = (string) ($slide[$key] ?? '');

            if (! str_contains($value, '{')) {
                continue;
            }

            $kept = [];

            foreach (explode("\n", $value) as $line) {
                $drop = false;

                foreach ($figures as $token => $resolved) {
                    if (! str_contains($line, $token)) {
                        continue;
                    }

                    if ($resolved === null) {
                        $drop = true;

                        break;
                    }

                    $line = str_replace($token, $resolved, $line);
                }

                if (! $drop) {
                    $kept[] = $line;
                }
            }

            $slide[$key] = implode("\n", $kept);
        }

        return $slide;
    }

    /**
     * The slides as stored, widened and typed, without the derived CSS — what
     * the editor edits, and what slides() adds the two gradients to.
     *
     * One reader for both, so the screen cannot show a slide the page would
     * render differently.
     *
     * @return list<array<string, string>>
     */
    public function editable(): array
    {
        $saved = $this->settings->get('home_banners', null);

        $rows = is_array($saved) ? $saved : self::DEFAULT_SLIDES;

        return array_values(array_map(
            fn (array $row) => $this->coerce($row),
            array_slice(array_filter($rows, 'is_array'), 0, self::MAX_SLIDES)
        ));
    }

    /** The About us paragraph, or '' when the owner has cleared it. */
    public function aboutText(): string
    {
        $saved = $this->settings->get('about_text', null);

        return $saved === null
            ? (string) __('store.home.about_body')
            : trim((string) $saved);
    }

    /**
     * Every value the editor needs, in the payload shape the console's other
     * schema screens already consume.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $flat = ModuleSchema::read($this->settings, 'homepage_content', self::SCHEMA);
        $flat['about_text'] = $this->aboutText();

        $tabs = ModuleSchema::tabs(self::SCHEMA, self::TABS, $flat);

        return [
            'max_slides' => self::MAX_SLIDES,
            'css' => self::CSS,
            'slide_fields' => array_values(ModuleSchema::fields(self::SLIDE_SCHEMA, [])),
            'slides' => array_map(
                fn (array $s) => ['values' => $s, 'css' => $this->render($s)],
                $this->editable()
            ),
            'defaults' => array_map(fn (array $s) => $this->coerce($s), self::DEFAULT_SLIDES),
            // The figures a slide may quote, and what they stand at right now.
            // The editor edits the TOKEN — that is the point of it — so the
            // screen needs both to explain one and to preview the other.
            'tokens' => self::TOKENS,
            'figures' => $this->figures(),
            'tabs' => $tabs,
            // So the screen can say, in the owner's words, what the brands note
            // and trust claims on the same page currently say — those already
            // have a home and this screen must not grow a second box for them.
            'claims_elsewhere' => TrustClaims::CLAIMS['home_brands_note'],
        ];
    }

    /**
     * Write the slides.
     *
     * Returns the fields it refused, per slide index, through the same channel
     * ModuleSchema::write() uses — a save that quietly dropped a bad colour or
     * an off-site link would be the silence this whole class exists to remove.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>  "slide 2 · Background · from" => why
     */
    public function saveSlides(array $rows): array
    {
        $rejected = [];
        $clean = [];
        $fields = ModuleSchema::normalise(self::SLIDE_SCHEMA);

        foreach (array_slice(array_values($rows), 0, self::MAX_SLIDES) as $i => $row) {
            if (! is_array($row)) {
                continue;
            }

            $slide = [];

            foreach ($fields as $key => $field) {
                $cast = ModuleSchema::cast($field, self::emptyIsEmpty(
                    $field,
                    array_key_exists($key, $row) ? $row[$key] : $field['default']
                ));

                if ($cast === null) {
                    $rejected['slide ' . ($i + 1) . ' · ' . $field['label']] = 'not a valid value';
                    $slide[$key] = $field['default'];

                    continue;
                }

                $slide[$key] = (string) $cast;
            }

            $slide['heading'] = $this->normaliseLines($slide['heading']);
            $slide['text'] = $this->normaliseLines($slide['text']);

            $path = self::path($slide['url']);

            if ($path === null) {
                $rejected['slide ' . ($i + 1) . ' · ' . $fields['url']['label']]
                    = 'must be a page on this shop, starting with a slash';
                $slide['url'] = (string) $fields['url']['default'];
            } else {
                $slide['url'] = $path;
            }

            $clean[] = $slide;
        }

        $this->settings->set('home_banners', $clean);

        return $rejected;
    }

    /**
     * Write the flat copy through ModuleSchema, which is what `store` is for.
     *
     * @param  array<string, mixed>  $values
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public function saveCopy(array $values): array
    {
        $fields = ModuleSchema::normalise(self::SCHEMA);
        $clean = [];

        foreach ($values as $key => $value) {
            $clean[$key] = isset($fields[$key])
                ? self::emptyIsEmpty($fields[$key], $value)
                : $value;
        }

        return ModuleSchema::write($this->settings, 'homepage_content', self::SCHEMA, $clean);
    }

    /**
     * An empty box is an empty STRING, not an absent value.
     *
     * ── WHY THIS HAS TO BE SAID OUT LOUD ────────────────────────────────────
     *
     * ConvertEmptyStringsToNull is in this application's global middleware, so
     * a cleared box arrives here as `null`, not as ''. ModuleSchema::cast()
     * refuses null for a text field — correctly, because for a `select` or a
     * `colour` an absent value IS a bad value — and a refusal here would mean
     * the one edit this screen most needs to support, CLEARING A CLAIM, would
     * report "refused" and leave the shipped sentence on the shop's front page.
     *
     * The same middleware makes Store → Ecommerce 422 on any empty text box;
     * another lane is repairing that at the source. This does not build on the
     * broken behaviour and does not wait for the repair either: it restores ''
     * for the two types where an empty value is a real answer, and leaves every
     * other type's null to be refused exactly as it is now. When the global fix
     * lands, this becomes a no-op rather than a conflict.
     */
    private static function emptyIsEmpty(array $field, mixed $raw): mixed
    {
        if ($raw === null && in_array($field['type'], ['text', 'textarea'], true)) {
            return '';
        }

        return $raw;
    }

    /**
     * A link this shop can serve, or null.
     *
     * One leading slash and no second one, so `//evil.test` — which a browser
     * reads as a protocol-relative absolute URL — is refused along with every
     * scheme. No control characters, because `java\nscript:` is the oldest way
     * round a scheme check.
     */
    public static function path(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '' || ! str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return mb_substr($value, 0, 300);
    }

    /**
     * The two gradients, built from validated colours and never from owner text.
     *
     * Every value substituted here has been through ModuleSchema::cast() as a
     * `colour`, which refuses anything but /^#[0-9a-f]{6}$/, so nothing an
     * owner can type reaches the style attribute. That refusal is the reason
     * this may be a string template at all.
     */
    public static function css(array $slide): array
    {
        $swap = [];

        foreach (self::SLIDE_SCHEMA as $key => $field) {
            $swap['{' . $key . '}'] = (string) ($slide[$key] ?? '');
        }

        return [
            'gradient' => strtr(self::CSS['gradient'], $swap),
            'panel' => strtr(self::CSS['panel'], $swap),
        ];
    }

    private function render(array $slide): array
    {
        return $slide + self::css($slide);
    }

    /**
     * A stored row widened to every field of the schema.
     *
     * Also the migration path for a row saved in the OLD shape, which carried
     * `gradient` and `panel` as CSS: its colours cannot be recovered, so it
     * falls back to the field defaults and keeps its words. Nothing has ever
     * written that shape — this class is the first writer — but a hand-edited
     * settings row is a real thing on this host.
     */
    private function coerce(array $row): array
    {
        $out = [];

        foreach (ModuleSchema::normalise(self::SLIDE_SCHEMA) as $key => $field) {
            $value = ModuleSchema::cast($field, $row[$key] ?? $field['default']);
            $out[$key] = (string) ($value ?? $field['default']);
        }

        $out['heading'] = $this->normaliseLines($out['heading']);
        $out['text'] = $this->normaliseLines($out['text']);
        $out['url'] = self::path($out['url']) ?? (string) self::SLIDE_SCHEMA['url']['default'];

        return $out;
    }

    /** CRLF and stray blank lines out; a headline is two or three lines, not a document. */
    private function normaliseLines(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/\n{2,}/', "\n", $value) ?? $value;

        return trim($value);
    }
}
