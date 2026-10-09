<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * Lane IGR: delete every stored credential of the retired Instagram API module.
 *
 * The owner retired the module ("the old instagram api etc will be discontinue
 * from the app, and also the instagram connect page too"), and a credential
 * nothing reads is still a credential: an access token for the shop's Instagram
 * and Facebook Page, and the two app secrets that mint more. They go, rather
 * than waiting in `settings` for whatever reads that table next.
 *
 * THE KEYS, BY NAME (App\Services\Instagram\InstagramCredentials::ALL_KEYS, the
 * class that wrote them, copied here so this file stands on its own):
 *
 *   instagram_app_id, instagram_app_secret, instagram_token,
 *   instagram_token_expires, instagram_user_id, instagram_via,
 *   instagram_fb_app_id, instagram_fb_app_secret, instagram_fb_config_id,
 *   instagram_fb_page_id, instagram_fb_page_name, instagram_token_invalid
 *
 * and, as a sweep, any other `settings` key that starts `instagram_` — no live
 * feature stores one (Instagram embeds use `igembed_`, the email footer's link
 * is `mail_support_instagram`, the SEO profile link `seo_soc_ig`).
 *
 * THE CACHES: the half-finished "Connect with Facebook" pick
 * (kbb.instagram.fb.pending holds a short-lived user token while the owner
 * chooses a Page), the feed's tiles (kbb.ig.feed.* via its index), and the
 * Spotted page's API cards (kbb.spotted.ig.cards); then the settings map, so no
 * request serves a copy that still has a row.
 *
 * NOTHING IS PRINTED BUT KEY NAMES AND A COUNT — never a value. No down():
 * a deleted secret is not restored by a rollback, by design.
 */
return new class extends Migration
{
    private const KEYS = [
        'instagram_app_id', 'instagram_app_secret', 'instagram_token',
        'instagram_token_expires', 'instagram_user_id', 'instagram_via',
        'instagram_fb_app_id', 'instagram_fb_app_secret', 'instagram_fb_config_id',
        'instagram_fb_page_id', 'instagram_fb_page_name', 'instagram_token_invalid',
    ];

    public function up(): void
    {
        $sweep = DB::table('settings')->pluck('key')
            ->filter(fn ($k) => is_string($k) && str_starts_with($k, 'instagram_'))
            ->all();

        $keys = array_values(array_unique(array_merge(self::KEYS, $sweep)));
        $present = DB::table('settings')->whereIn('key', $keys)->pluck('key')->all();
        $deleted = DB::table('settings')->whereIn('key', $keys)->delete();

        try {
            foreach ((array) Cache::get('kbb.ig.feed.index', []) as $key) {
                if (is_string($key)) {
                    Cache::forget($key);
                }
            }

            foreach (['kbb.ig.feed.index', 'kbb.instagram.fb.pending', 'kbb.spotted.ig.cards'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cold or unreachable store holds nothing to forget.
        }

        Setting::flushMap();
        app(SettingsService::class)->flush();

        if (app()->runningInConsole()) {
            sort($present);
            echo 'Deleted '.$deleted.' Instagram API setting(s)'.($present === [] ? '' : ': '.implode(', ', $present)).".\n";
        }
    }

    public function down(): void {}
};
