<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE HOMEPAGE'S BANNER BECOMES PICTURES, AT THE OWNER'S TWO SIZES, WITH THE
 * COUNTRIES STRIP UNDER IT.                                       (Lane SEC)
 *
 * His instruction, verbatim and whole, because every default this migration
 * moves is a clause of it:
 *
 *   "The top countries bar, i need under banner, and the banner i need to
 *    change to simple image banners, not cards, simple only images banner,
 *    with slide if multiple and user can slide left to right and right to
 *    left. if single image, then no sliding. apply this on desktop and mobile
 *    both. for desktop the size should be 1920 x 550 and in mobile 500 x 600"
 *
 * and then, when he was asked whether these should be controls to flip:
 *
 *   "whatever i said, keep applying on the site, don't let me know that change
 *    from the backend, i have the options on backend, i want to apply such
 *    things directly to the site to save time."
 *
 * ── WHY A MIGRATION AND NOT A CHANGED DEFAULT ───────────────────────────────
 *
 * Because a changed default would have moved nothing at all, and would have
 * looked applied. `banner_sets.kind` has a database default of `cards`, so
 * every row on the shop carries the string `cards` in the column; the model's
 * fallback is never consulted for it and neither is the create path. The same
 * is true of the two ratios: `16/9` and `4/3` are IN the rows, not inferred.
 * Three defaults moved in app code (BannerSet::$attributes, kind(),
 * sliderRatioCss()/sliderRatioMobileCss()) plus this, and only together do they
 * leave no row holding the old shape.
 *
 * ── THE FIVE WRITES, EACH ONE NAMED ─────────────────────────────────────────
 *
 *   1. banner_sets.kind          'cards'  -> 'slider'     every row that has
 *                                                         not been switched by
 *                                                         hand already
 *   2. banner_sets.slider_ratio  '16/9'   -> '1920/550'   his desktop number
 *   3. banner_sets.slider_ratio_m '4/3'   -> '500/600'    his phone number
 *   4. module_toggles.cards_banner  off   -> on           the banner cannot be
 *                                                         the homepage's banner
 *                                                         while its module is
 *                                                         off
 *   5. header_settings.fb_desktop  false  -> true         "apply this on
 *      (and fb_mobile held at true),                       desktop and mobile
 *      but ONLY when the row exists:                       both"
 *      the schema default covers a shop
 *      that has never saved that screen
 *
 * and one choice rather than a write, item 6 below: if the Banners module has
 * no set chosen for the homepage and there is exactly one that would draw, it
 * is chosen. A shop with two published sets is left alone — picking one of two
 * for somebody is a guess, and the console lists both.
 *
 * ── WHAT IT DOES TO A SHOP WITH NO BANNER PICTURES, WHICH IS THE IMPORTANT
 *    HALF ───────────────────────────────────────────────────────────────────
 *
 * Nothing. `Banners::forHome()` draws a set only when a published card with a
 * non-empty `image` exists (`where('banner_cards.image', '<>', '')`), so the
 * module toggle and the chosen set are both inert until there is a picture.
 * Every write above is idempotent and every one of them is a no-op on an empty
 * table. The one thing that DOES change on every shop is the countries strip:
 * it was on for phones and off for desktop, and it is now on for both and
 * drawn under the banner rather than above the header.
 *
 * WHAT IS NOT HERE, and it is the one thing the owner may still be looking at:
 * the homepage's OTHER banner. store/home.blade.php's hero band is a rotation
 * of `linear-gradient` panels with a headline, a line of text and a button —
 * App\Services\HomepageContent has no image field of any kind, so the hero
 * cannot be made "simple only images" by configuration, at any value, and
 * turning it off is not this migration's call to make. Reported to the
 * integrator in the round's hand-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moved = [];

        /*
         * ── 1-3. THE SETS ───────────────────────────────────────────────────
         *
         * SCOPED TO THE OLD DEFAULT AND NOT TO EVERY ROW, for each of the
         * three. An owner who has already chosen `21/9` for a set chose it;
         * overwriting that would be this migration deciding it knows better
         * than the screen he typed into. The rows that hold `16/9` and `4/3`
         * are the rows nobody has touched, which on this shop is all of them.
         */
        $moved['kind: cards -> slider'] = DB::table('banner_sets')
            ->where('kind', 'cards')->update(['kind' => 'slider']);

        $moved['slider_ratio: 16/9 -> 1920/550'] = DB::table('banner_sets')
            ->where('slider_ratio', '16/9')->update(['slider_ratio' => '1920/550']);

        $moved['slider_ratio_m: 4/3 -> 500/600'] = DB::table('banner_sets')
            ->where('slider_ratio_m', '4/3')->update(['slider_ratio_m' => '500/600']);

        /*
         * ── 4. THE MODULE ───────────────────────────────────────────────────
         *
         * updateOrInsert rather than update: ModuleSeeder writes one row per
         * module, but a shop whose seeder ran before `cards_banner` existed has
         * no row at all and ModuleRegistry then reads the registry's own
         * default, which is off.
         */
        $before = DB::table('module_toggles')->where('module', 'cards_banner')->value('enabled');

        DB::table('module_toggles')->updateOrInsert(
            ['module' => 'cards_banner'],
            ['enabled' => true, 'updated_at' => now(), 'created_at' => now()],
        );

        $moved['module cards_banner: on'] = (int) ((bool) $before !== true);

        /*
         * ── 5. THE COUNTRIES STRIP, ON FOR BOTH ─────────────────────────────
         *
         * `header_settings` is ONE settings row holding a JSON object, and it
         * is read by HeaderSettings::all() as "the saved array merged over the
         * schema defaults". So an absent key already means `fb_mobile` is true;
         * writing it anyway is what makes the row say what the shop does rather
         * than leaving half the answer in a PHP constant.
         *
         * The decode is defensive in both directions because this column has
         * held a scalar for other keys: anything that is not a JSON object is
         * replaced by one rather than merged into, which cannot lose an
         * operator's header settings (there were none to lose) and cannot throw
         * on a string.
         */
        $row = DB::table('settings')->where('key', 'header_settings')->value('value');

        $moved['fb_desktop: off -> on'] = 0;

        /*
         * AND ONLY WHEN THE ROW IS ALREADY THERE, which is the whole of the
         * `if`. A shop that has never opened Appearance → Header has no row at
         * all, all() answers the schema defaults, and `fb_desktop` is now true
         * there — so writing one would move nothing the owner can see and WOULD
         * move something he cannot: `/admin-api/seo/settings` returns the
         * settings table, so a row created for no reason is a changed payload
         * on a screen this round has nothing to do with.
         * SeoBackOfficePayloadTest reported exactly that ("settings.
         * header_settings is new") before this condition was written.
         */
        if (is_string($row)) {
            $header = json_decode($row, true);
            $header = is_array($header) ? $header : [];

            $before = $header['fb_desktop'] ?? null;

            $header['fb_mobile'] = true;
            $header['fb_desktop'] = true;

            DB::table('settings')
                ->where('key', 'header_settings')
                ->update(['value' => json_encode($header), 'updated_at' => now()]);

            $moved['fb_desktop: off -> on'] = (int) ($before !== true);
        }

        /*
         * ── 6. AND THE SET THE HOMEPAGE SHOWS, WHEN THERE IS ONLY ONE ───────
         *
         * `Banners::forHome()` reads the module setting `cards_banner.set` and
         * draws nothing while it is empty, so leaving it empty would have left
         * every write above with nothing to show. The id is read the same way
         * forHome() reads a set: published, with at least one published card
         * carrying a picture. Lowest position, and only when there is exactly
         * one candidate.
         *
         * The module settings table is read directly rather than through
         * SettingsService because Setting::map() memoises in a process-level
         * static as well as in the cache, and a migration runs in the same
         * process as whatever ran before it.
         */
        $chosen = DB::table('module_settings')
            ->where('module', 'cards_banner')->where('key', 'set')->value('value');

        $moved['homepage set chosen'] = 0;

        if (trim((string) $chosen) === '') {
            $candidates = DB::table('banner_sets')
                ->join('banner_cards', 'banner_cards.banner_set_id', '=', 'banner_sets.id')
                ->where('banner_sets.status', 'publish')
                ->where('banner_cards.status', 'publish')
                ->where('banner_cards.image', '<>', '')
                ->orderBy('banner_sets.position')
                ->orderBy('banner_sets.id')
                ->distinct()
                ->pluck('banner_sets.id');

            if ($candidates->count() === 1) {
                DB::table('module_settings')->updateOrInsert(
                    ['module' => 'cards_banner', 'key' => 'set'],
                    ['value' => (string) $candidates->first(), 'updated_at' => now(), 'created_at' => now()],
                );

                $moved['homepage set chosen'] = 1;
            }
        }

        if (app()->runningInConsole()) {
            echo "The homepage banner is now a picture slider at 1920 x 550 on desktop and\n"
                ."500 x 600 on phones, and the countries strip sits under it on both.\n"
                ."Slides only when there is more than one picture. Moved:\n";

            foreach ($moved as $what => $rows) {
                echo sprintf("  %-34s %d\n", $what, $rows);
            }

            echo "Appearance -> Banners is where the pictures and both sizes live;\n"
                ."Appearance -> Header -> Flag bar is where the strip's colours are.\n";
        }
    }

    /**
     * Back to cards at the old two shapes.
     *
     * NOT A PERFECT INVERSE AND IT SAYS SO. A set the owner switched to
     * `slider` by hand before this ran is switched back to `cards` by this,
     * because after up() has run there is nothing in the row that distinguishes
     * the two. Reversing a default move is lossy in exactly this way; the
     * alternative is a second column recording what the row used to be, which
     * is a schema change to make a rollback tidier than the forward path.
     */
    public function down(): void
    {
        DB::table('banner_sets')->where('kind', 'slider')->update(['kind' => 'cards']);
        DB::table('banner_sets')->where('slider_ratio', '1920/550')->update(['slider_ratio' => '16/9']);
        DB::table('banner_sets')->where('slider_ratio_m', '500/600')->update(['slider_ratio_m' => '4/3']);
    }
};
