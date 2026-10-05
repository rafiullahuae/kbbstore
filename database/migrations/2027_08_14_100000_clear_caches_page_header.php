<?php

declare(strict_types=1);

use App\Services\PageBanners;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Pages → Page header, the front-end "Edit header" panel, and the third line
 * on the Super Sale strip. (Lane PH)
 *
 * Adds admin routes (routes/page-header-admin.php), so the compiled route cache
 * has to go, as CLAUDE.md requires of every package that adds one, and changes
 * three storefront views, so the compiled views go too.
 *
 * ONE DATA CHANGE, AND ONLY WHERE IT IS MISSING. The owner: "also include in
 * the strip for desktop only 'Free skincare consultation', in mobile two lines
 * are fine." With no banner saved the code default already carries it. A shop
 * that has SAVED its banners has its own copy of the strip, which the new
 * default cannot reach — so the banner shown on /super-sale/ gains the line,
 * desktop only, at the end. Not when it is already there (any case, any
 * device), not when the strip is full, and on no other banner. Running this
 * twice changes nothing the second time.
 *
 * The header itself writes nothing: /super-sale/'s look (no dot, no count, no
 * "All products" button) is the code default, stored only when somebody saves.
 */
return new class extends Migration
{
    public const LINE = 'Free skincare consultation';

    public function up(): void
    {
        $added = $this->addConsultationLine();
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
            echo $added ? "The Super Sale strip gained 'Free skincare consultation' (desktop only).\n" : "The Super Sale strip needed no change.\n";
            echo "Pages -> Page header is ready.\n";
        }
    }

    /** True when a stored strip was changed. */
    private function addConsultationLine(): bool
    {
        $raw = DB::table('settings')->where('key', PageBanners::KEY)->value('value');
        $all = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($all) || ! is_array($all['banners'] ?? null)) {
            return false;
        }

        $target = (string) ($all['assign']['collection:super-sale'] ?? '');
        $changed = false;

        foreach ($all['banners'] as $n => $banner) {
            if (! is_array($banner) || $target === '' || ($banner['id'] ?? null) !== $target) {
                continue;
            }

            $items = is_array($banner['items'] ?? null) ? array_values($banner['items']) : [];

            foreach ($items as $item) {
                $en = is_array($item) ? ($item['en'] ?? '') : $item;
                if (is_string($en) && strcasecmp(trim($en), self::LINE) === 0) {
                    continue 2;
                }
            }

            if (count($items) >= PageBanners::MAX_ITEMS) {
                continue;
            }

            $items[] = ['en' => self::LINE, 'ar' => '', 'dev' => 'd'];
            $all['banners'][$n]['items'] = $items;
            $changed = true;
        }

        if ($changed) {
            app(SettingsService::class)->set(PageBanners::KEY, $all);
        }

        return $changed;
    }

    public function down(): void {}
};
