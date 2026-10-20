<?php

declare(strict_types=1);

use App\Support\ShopByConcern;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 20 October 2026, the owner: "in the shop by conern, i need another tab
 * 'Dull Skin' and do according the icon etc. english and arabic".
 *
 * Appends ONE tab to Appearance → Homepage content → Shop by Concern → tabs:
 * label and title "Dull Skin" / "البشرة الباهتة", its own icon (dullskin),
 * a tip in both languages, and its products picked by hand from the shop's
 * brightening range (vitamin C, rice, niacinamide, propolis) -- the shop has
 * no glow or brightening collection, and the concern's automatic source would
 * fall back to Hydration & Glow and repeat the Hydration tab. Every tab he
 * already has is kept exactly as it is, in his order; the new one goes last.
 * Nothing happens when a Dull Skin tab is already there. Literals only, plus
 * ShopByConcern::rows()/clean() so the row is the shape the editor saves.
 */
return new class extends Migration
{
    private const KEY = 'home_sbc_tabs';

    private const SLUGS = [
        'beauty-of-joseon-glow-serum-propolis-niacinamide',
        'medicube-deep-vita-c-capsule-cream',
        'arencia-vitamin-c-booster-shot',
        'anua-rice-70-glow-milky-toner',
        'skin-1004-madagascar-centella-tone-brightening-capsule-ampoule',
        'dr-althea-vitamin-c-boosting-serum',
        'numbuzin-no-3-super-glowing-essence-toner',
        'medicube-kojic-acid-turmeric-vita-jelly-mist-serum',
        'goodal-green-tangerine-vita-c-dark-spot-care-serum',
        'some-by-mi-galactomyces-pure-vitamin-c-glow-serum-30-ml',
        'beauty-of-joseon-glow-deep-serum-rice-alpha-arbutin-30ml',
        'anua-rice-enzyme-brightening-cleansing-powder',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('products')) {
            return;
        }

        $stored = DB::table('settings')->where('key', self::KEY)->value('value');
        $rows = ShopByConcern::rows(is_string($stored) ? $stored : '');

        foreach ($rows as $row) {
            if ($row['i'] === 'dullskin' || strcasecmp($row['l'], 'Dull Skin') === 0) {
                return;
            }
        }

        if (count($rows) >= ShopByConcern::CAP) {
            return;
        }

        $ids = DB::table('products')->whereIn('slug', self::SLUGS)->pluck('id', 'slug')->all();
        $picks = [];
        foreach (self::SLUGS as $slug) {
            if (isset($ids[$slug])) {
                $picks[] = (int) $ids[$slug];
            }
        }

        $rows[] = [
            'k' => 'dullskin',
            'on' => true,
            'i' => 'dullskin',
            'l' => 'Dull Skin',
            'la' => 'البشرة الباهتة',
            'h' => 'Dull Skin',
            'ha' => 'البشرة الباهتة',
            't' => 'Dull skin is usually dead cells and thirst. Exfoliate gently twice a week with AHA or rice enzymes, add vitamin C or niacinamide every morning, hydrate in layers — and always finish with SPF.',
            'ta' => 'غالبًا ما يكون بهتان البشرة بسبب الخلايا الميتة والجفاف. قشّري بلطف مرتين أسبوعيًا بأحماض AHA أو إنزيمات الأرز، وأضيفي فيتامين سي أو النياسيناميد كل صباح، ورطّبي على طبقات — واختمي دائمًا بواقي الشمس.',
            'img' => '',
            'imga' => '',
            // Fewer than two of the picks on this shop: the concern's own collection instead.
            'src' => count($picks) >= 2 ? 'manual' : 'auto',
            'cats' => [],
            'brands' => [],
            'picks' => $picks,
            'sort' => 'bestselling',
            'n' => 0,
            'u' => '',
        ];

        $now = now();
        DB::table('settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => ShopByConcern::clean($rows), 'autoload' => true, 'updated_at' => $now],
        );

        foreach (['kbb.settings', 'kbb.settings.map', 'kbb.modules', 'kbb.module_settings'] as $cacheKey) {
            try {
                Cache::forget($cacheKey);
            } catch (\Throwable) {
                // A cache that cannot forget expires on its own.
            }
        }

        try {
            Cache::increment(ShopByConcern::GENERATION);
        } catch (\Throwable) {
            // The section's own cache lives ten minutes.
        }
    }

    public function down(): void {}
};
