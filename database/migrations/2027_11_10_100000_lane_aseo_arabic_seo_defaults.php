<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Arabic homepage's title, descriptions and keywords, filled in (Lane ASEO,
 * 17 October 2026).
 *
 * The owner: "the default title of the arabic version is not there, it's still
 * showing the english title etc on the arabic homepage. it should arabic title,
 * give me proper backend option to change it also, for now translate it by
 * default. make sure it must be as per our seo etc. also update all meta
 * keywords, description of the site etc in arabic too."
 *
 * Store -> SEO & Meta -> Settings -> "Arabic search appearance (/ar)" holds the
 * five boxes; App\Support\Seo reads them on /ar only. This writes his Arabic
 * into them so the shop is Arabic the moment the package applies ("what he
 * asked for ships ON"), and so he sees the text in the boxes and can edit it.
 *
 * ONLY WHERE THE BOX IS EMPTY OR ABSENT: an Arabic he typed himself is his.
 * The English settings are not read or written. LITERALS ONLY (the 2.60.512
 * rule): no App\ class, the updater may still be running the old ones.
 */
return new class extends Migration
{
    private const VALUES = [
        'seo_site_name_ar' => 'كي بيوتي بليس',
        'seo_home_title_ar' => 'كي بيوتي بليس — منتجات العناية الكورية الأصلية في الإمارات',
        'seo_home_description_ar' => 'تسوّقي العناية الكورية بالبشرة الأصلية 100% في الإمارات من كي بيوتي بليس: سيروم وتونر وواقي شمس من أشهر العلامات الكورية، مع توصيل سريع لكل الإمارات.',
        'seo_default_description_ar' => 'منتجات العناية الكورية بالبشرة والجمال الكوري الأصلية من أشهر العلامات الكورية في كي بيوتي بليس، متجرك الإلكتروني في الإمارات مع التوصيل لجميع الإمارات.',
        'seo_home_keywords_ar' => 'العناية الكورية بالبشرة، منتجات كورية أصلية، الجمال الكوري، كي بيوتي، كي بيوتي بليس، مستحضرات تجميل كورية، روتين العناية الكوري، سيروم كوري، تونر كوري، واقي شمس كوري، منتجات كورية في الإمارات، منتجات كورية في دبي، متجر منتجات كورية أونلاين',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! $this->isThisBrand()) {
            return;
        }

        $now = now();
        foreach (self::VALUES as $key => $value) {
            $row = DB::table('settings')->where('key', $key)->first(['value']);

            if ($row === null) {
                DB::table('settings')->insert(['key' => $key, 'value' => $value, 'created_at' => $now, 'updated_at' => $now]);
            } elseif (trim((string) $row->value) === '') {
                DB::table('settings')->where('key', $key)->update(['value' => $value, 'updated_at' => $now]);
            }
        }

        $this->forgetCaches();
    }

    /** Takes back only the text this wrote; a box he has since edited is his. */
    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::VALUES as $key => $value) {
            DB::table('settings')->where('key', $key)->where('value', $value)->delete();
        }

        $this->forgetCaches();
    }

    /**
     * The text names K-Beauty Bliss, so it is written only for a shop that is
     * still called that (in English: Site name, else Store name, else neither,
     * which is this brand's own default). A renamed shop gets no row and its
     * /ar pages fall back to the shop's Arabic defaults instead. The fold is
     * App\Support\BrandName::isOurs()'s, copied as a literal.
     */
    private function isThisBrand(): bool
    {
        $name = '';
        foreach (['seo_site_name', 'store_name'] as $key) {
            $value = trim((string) DB::table('settings')->where('key', $key)->value('value'));
            if ($value !== '') {
                $name = $value;
                break;
            }
        }

        $fold = (string) preg_replace('/[^a-z]/', '', strtolower($name));

        return in_array($fold, ['', 'kbeautybliss', 'kbeautyblissuae'], true);
    }

    /** The settings maps, by their literal keys. */
    private function forgetCaches(): void
    {
        try {
            foreach (['kbb.settings', 'kbb.settings.map'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }
    }
};
