<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Translation\ArabicDraftCorrections;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use App\Support\ArabicWording;
use App\Support\Locale;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;
use Tests\Support\EnglishRenderWalk;

/*
 * Lane ARF -- /ar is Arabic BY DEFAULT.
 *
 * The owner, 15 October, browsing /ar with screenshots: "why every heading etc
 * is inside the arrow < > bracket? i need the full translation by default. and
 * if i found anything wrong, i will do it from the backend ... also the logo
 * need to write in arabic too in the footer", then "the cart page, checkout
 * pages fieds headings etc, coming in english and in arrow brackets. i don't
 * need anything in english in arabic version", then "also in the header logo,
 * the end is cut little bit".
 *
 * What he saw: the 1,804 Arabic interface drafts of 2.60.519-522 had never
 * been approved, so every footer heading and link, the trust strip, the
 * bundles heading, the chat bubble, every checkout label and button, read
 * ⟪English⟫ (translation_highlight_missing was on). The footer's small logo
 * and the WhatsApp label in the header had no Arabic at all; the copyright
 * printed "K-Beauty Bliss UAE"; the pink «بليس» in the header lost the tail of
 * its س. Each case below names the defect and the mutation that turns it red.
 */

function arfMigration(string $name): object
{
    return require database_path('migrations/'.$name.'.php');
}

const ARF_PUBLISH = '2027_11_06_100000_lane_arf_arabic_by_default';
const ARF_HIGHLIGHT = '2027_11_06_100100_lane_arf_highlight_missing_off';

function arfRow(string $field): ?object
{
    return DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)->where('field', $field)->first();
}

function arfFlush(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    TranslationStore::flush();
}

/** PHP source with comments removed: what the migration actually runs. */
function arfCode(string $file): string
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($file)) as $t) {
        $out .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
    }

    return $out;
}

afterEach(function () {
    app()->setLocale('en');
});

/*
 * 1. THE DRAFTS ARE SERVED. On the shop: "ACCOUNT", "HELP", "SHOP" and every
 *    footer link in ⟪brackets⟫, because their Arabic sat in the table as
 *    drafts. Mutation: drop the final update() in step 3 of the migration and
 *    the draft stays a draft; drop the status='draft' condition and the
 *    owner's own published row is overwritten.
 */
it('publishes every Arabic draft, exactly as Approve all does, and never touches a published row', function () {
    DB::table('translations')->where('locale', 'ar')->whereIn('field', ['store.footer.account_title', 'store.footer.help_heading'])->delete();
    $now = now();
    DB::table('translations')->insert([
        ['locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => 'store.footer.account_title', 'value' => 'الحساب', 'status' => 'draft', 'source' => 'machine', 'source_hash' => sha1('Account'), 'reviewed_at' => null, 'created_at' => $now, 'updated_at' => $now],
        // His own published wording: left exactly as he typed it.
        ['locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => 'store.footer.help_heading', 'value' => 'مساعدتكِ', 'status' => 'published', 'source' => 'manual', 'source_hash' => sha1('Help'), 'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now],
    ]);

    arfMigration(ARF_PUBLISH)->up();

    expect(arfRow('store.footer.account_title')->status)->toBe('published')
        ->and(arfRow('store.footer.account_title')->reviewed_at)->not->toBeNull()
        ->and(arfRow('store.footer.help_heading')->value)->toBe('مساعدتكِ')
        ->and(DB::table('translations')->where('locale', 'ar')->where('status', 'draft')->count())->toBe(0);

    // And the shop serves it: the footer heading is Arabic, in no brackets.
    ArabicShop::on();
    $html = $this->get('/ar/')->assertOk()->getContent();
    expect($html)->toContain('>الحساب</h2>')->toContain('>مساعدتكِ</h2>')->not->toContain('⟪');
});

/*
 * 1b. A product name that becomes published can be FOUND in Arabic. A builder
 *     update fires no model hook, so without the migration's own index the
 *     name would show and not be searchable. Mutation: remove indexSearch().
 */
it('indexes a product name it publishes for the Arabic search, normalised', function () {
    $id = DB::table('products')->insertGetId(['name' => 'Test Serum', 'slug' => 'arf-test-serum', 'price' => 1000, 'status' => 'publish', 'is_visible' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'products', 'item_id' => $id, 'field' => 'name', 'value' => 'سيروم الإشراقة', 'status' => 'draft', 'source' => 'machine', 'source_hash' => sha1('Test Serum'), 'created_at' => now(), 'updated_at' => now()]);

    arfMigration(ARF_PUBLISH)->up();

    expect(DB::table('search_texts_ar')->where('product_id', $id)->value('text'))->toBe(\App\Support\ArabicSearch::normalise('سيروم الإشراقة'));
});

/*
 * 1c. THE LATIN LEFT INSIDE THE DRAFTS. "السلة · K-Beauty Bliss" in the tab,
 *     "عبر WhatsApp" on the checkout's opt-in, "الاسم A–Z" in the sort. Only a
 *     draft still holding the shipped words is corrected. Mutation: drop the
 *     where('value', $old) condition and his edited draft is overwritten.
 */
it('corrects a shipped draft that still carries Latin, and leaves his edited one alone', function () {
    DB::table('translations')->where('locale', 'ar')->whereIn('field', ['store.checkout.whatsapp_optin', 'store.cart.page_title'])->delete();
    $now = now();
    $row = fn (string $f, string $v) => ['locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => $f, 'value' => $v, 'status' => 'draft', 'source' => 'machine', 'source_hash' => 'x', 'created_at' => $now, 'updated_at' => $now];
    DB::table('translations')->insert([
        $row('store.checkout.whatsapp_optin', ArabicDraftCorrections::ROUND3['store.checkout.whatsapp_optin'][0]),
        $row('store.cart.page_title', 'سلتي · متجري'),
    ]);

    arfMigration(ARF_PUBLISH)->up();

    expect(arfRow('store.checkout.whatsapp_optin')->value)->toBe('أرسلوا لي تحديثات الطلب والعروض الجديدة — عبر واتساب والبريد الإلكتروني.')
        ->and(arfRow('store.cart.page_title')->value)->toBe('سلتي · متجري');
});

/*
 * 1d. THE ARABIC THAT NEVER HAD A ROW is written, published, only where no
 *     row exists. Mutation: drop the isset($existing) check and his own row
 *     gains a duplicate.
 */
it('adds the missing Arabic rows published, once, and never over his', function () {
    DB::table('translations')->where('locale', 'ar')->where('field', 'like', 'store.concern.intro_%')->delete();
    DB::table('translations')->where('locale', 'ar')->where('field', 'store.wording.site_name_uae')->delete();
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => 'store.concern.intro_sun', 'value' => 'نصّي الخاص', 'status' => 'published', 'source' => 'manual', 'source_hash' => 'x', 'created_at' => now(), 'updated_at' => now()]);

    arfMigration(ARF_PUBLISH)->up();
    arfMigration(ARF_PUBLISH)->up();

    expect(DB::table('translations')->where('locale', 'ar')->where('field', 'like', 'store.concern.intro_%')->count())->toBe(8)
        ->and(arfRow('store.concern.intro_sun')->value)->toBe('نصّي الخاص')
        ->and(arfRow('store.concern.intro_acne')->status)->toBe('published')
        ->and(arfRow('store.wording.site_name_uae')->value)->toBe('كي بيوتي بليس الإمارات');
});

/*
 * 2. NO ⟪BRACKETS⟫ FOR ANYONE. The review switch was on while the shop was
 *    live. Mutation: write '1' instead of '0' and this is red. And the Arabic
 *    on/off switch is the owner's call, in either direction.
 */
it('turns the missing-translation highlight off and leaves the Arabic switch exactly where it was', function () {
    foreach (['0', '1'] as $ar) {
        app(SettingsService::class)->set('translation_highlight_missing', true);
        Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => $ar, 'autoload' => true]);

        arfMigration(ARF_HIGHLIGHT)->up();
        arfFlush();

        expect(DB::table('settings')->where('key', 'translation_highlight_missing')->value('value'))->toBe('0')
            ->and((bool) app(SettingsService::class)->get('translation_highlight_missing', false))->toBeFalse()
            ->and(DB::table('settings')->where('key', Locale::SETTING_ENABLED)->value('value'))->toBe($ar);
    }

    // Never saved: already off by default, and no row is invented for it.
    DB::table('settings')->where('key', 'translation_highlight_missing')->delete();
    arfMigration(ARF_HIGHLIGHT)->up();
    expect(DB::table('settings')->where('key', 'translation_highlight_missing')->exists())->toBeFalse();
});

/*
 * 3. THE MIGRATIONS ARE LITERAL (the 2.60.512 rule: the updater runs them in
 *    the request that copied the files, against an opcache that may still hold
 *    the old classes). And their copies are the classes' data. Mutation: read
 *    ArabicDraftCorrections::ROUND3 inside the migration, or change one
 *    correction in the class only.
 */
it('runs no class of the shop in either migration, and its literals are the classes\' data', function () {
    foreach ([ARF_PUBLISH, ARF_HIGHLIGHT] as $name) {
        $code = arfCode(database_path('migrations/'.$name.'.php'));
        expect(str_contains($code, 'App\\'))->toBeFalse($name.' names a class of the shop')
            ->and(preg_match('/\buse\s+(?!Illuminate\\\\)[A-Z]/', $code))->toBe(0, $name)
            ->and($code)->not->toContain('language_ar_enabled');
    }

    $m = arfMigration(ARF_PUBLISH);
    $corrections = (new ReflectionClassConstant($m, 'CORRECTIONS'))->getValue();
    $new = (new ReflectionClassConstant($m, 'NEW_ROWS'))->getValue();

    expect($corrections)->toBe(ArabicDraftCorrections::ROUND3);

    $english = InterfaceStrings::flat();
    foreach ($new as $key => [$en, $arabic]) {
        expect($english[$key] ?? null)->toBe($en, "{$key}: the English it was written for");
        expect(preg_match('/[A-Za-z]/', $arabic))->toBe(0, "{$key}: Latin left in the Arabic");
    }
    expect($new['store.wording.site_name_uae'][1])->toBe(ArabicWording::ARABIC['wording.site_name_uae']);

    // Every correction is a real draft of the shop's and removes Latin a shopper reads.
    $drafts = \App\Services\Translation\ArabicInterfaceDrafts::all();
    foreach (ArabicDraftCorrections::ROUND3 as $key => [, $fixed]) {
        expect($drafts[$key])->toBe($fixed);
        expect(preg_match('/[A-Za-z]/', (string) preg_replace('/:[a-z_]+/', '', $fixed)))->toBe(0, "{$key} still carries Latin");
    }
});

/*
 * 4. THE FOOTER'S LOGO AND WORDMARK IN ARABIC. On the shop: "K-Beauty" +
 *    "Bliss" over the Arabic footer, and the big name overflowing its line by
 *    12.7% once it was Arabic (measured 320-1600, tools/arf-kftname.cjs).
 *    Mutation: drop the /ar branch in SiteFooter::view() (the logo is English),
 *    or the 1.15 factor (--kft-n:13 and the name is clipped).
 */
it('writes the footer logo and the big wordmark in Arabic on /ar, and English exactly as before', function () {
    ArabicShop::on();
    $ar = $this->get('/ar/')->assertOk()->getContent();
    expect($ar)->toContain('<a class="kft-logo" href="/ar/"><bdi>كي بيوتي <b>بليس</b></bdi></a>')
        ->toContain('style="--kft-n:15"')
        ->toMatch('/class="kft-name"[^>]*>(<span[^>]*><\/span>)?كي بيوتي بليس<\/p>/u');

    $en = $this->get('/')->assertOk()->getContent();
    expect($en)->toContain('<bdi>K-Beauty<b>Bliss</b></bdi>')->toContain('style="--kft-n:14"')->not->toContain('كي بيوتي');
});

it('keeps a footer logo the owner typed himself, in every language', function () {
    $h = app(\App\Services\HeaderSettings::class);
    $h->save(['logo_text' => 'Glow', 'logo_accent' => 'House']);
    ArabicShop::on();

    expect($this->get('/ar/')->getContent())->toContain('<bdi>Glow<b>House</b></bdi>');
});

/*
 * 5. THE TWINS. The header's "24/7 support", the copyright's "K-Beauty Bliss
 *    UAE" and every <title>'s " | K-Beauty Bliss UAE" were English on /ar.
 *    Mutation: drop the support_label branch in HeaderSettings::all(), or the
 *    trim in copyrightStore() ("كي بيوتي بليس الإمارات الإمارات").
 */
it('prints the header support label, the copyright and the title site name in Arabic on /ar only', function () {
    app(SettingsService::class)->set('store_name', 'K-Beauty Bliss UAE');
    ArabicShop::on();

    $ar = $this->get('/ar/shop/')->assertOk()->getContent();
    expect($ar)->toContain('<b>دعم على مدار الساعة</b>')
        ->toContain('© '.date('Y').' كي بيوتي بليس الإمارات<')
        ->not->toContain('الإمارات الإمارات')
        ->not->toContain('K-Beauty Bliss UAE</title>')
        ->not->toContain('24/7 support');

    app()->setLocale('en');
    $en = $this->get('/shop/')->assertOk()->getContent();
    // English: one "UAE" since 17 October 2026 (the owner: "okay do it", on
    // "© 2026 K-Beauty Bliss UAE UAE"). The title keeps the name whole.
    // MUTATION: drop the ' UAE' branch in copyrightStore() -- "UAE UAE" again.
    expect($en)->toContain('<b>24/7 support</b>')->toContain('© '.date('Y').' K-Beauty Bliss UAE<')
        ->not->toContain('K-Beauty Bliss UAE UAE')->toContain('K-Beauty Bliss UAE</title>');
    expect(ArabicWording::copyrightStore('Glow House'))->toBe('Glow House')
        ->and(ArabicWording::copyrightStore('Glow House uae'))->toBe('Glow House');
});

/*
 * 5b. THE CHECKOUT'S OWN WORDS. Payment method titles and lines, delivery
 *     names and the summary rows are display text over the stored English
 *     (the order keeps its English title; the payment lock is untouched).
 *     Each is served in Arabic by default now. Mutation: leave the drafts as
 *     drafts and every one of these reads English.
 */
it('serves every checkout display wording in Arabic by default, with the payment data untouched', function () {
    foreach (['pay_cod_title', 'pay_cod_desc', 'pay_cod_fee', 'pay_card_title', 'pay_tabby_title', 'pay_tabby_desc', 'pay_tamara_title', 'pay_tamara_desc', 'free_delivery', 'delivery_charges', 'shipping_charges', 'vat_inclusive'] as $k) {
        $row = arfRow('store.wording.'.$k);
        expect($row?->status)->toBe('published', $k)
            ->and(preg_match('/\p{Arabic}/u', (string) $row?->value))->toBe(1, $k);
    }
    foreach (['store.checkout.field_email', 'store.checkout.field_phone', 'store.checkout.place_order', 'store.cart.subtotal'] as $k) {
        if (array_key_exists($k, InterfaceStrings::flat())) {
            expect(arfRow($k)?->status)->toBe('published', $k);
        }
    }

    // The gateways' own records are not touched by either migration.
    foreach ([ARF_PUBLISH, ARF_HIGHLIGHT] as $name) {
        expect(arfCode(database_path('migrations/'.$name.'.php')))->not->toContain('payment_providers')->not->toContain("'orders'");
    }
});

it('leaves a support label or store name he typed himself as he typed it', function () {
    app(\App\Services\HeaderSettings::class)->save(['support_label' => 'Talk to Sara']);
    app(SettingsService::class)->set('store_name', 'Glow House');
    ArabicShop::on();

    expect(ArabicWording::copyrightStore('Glow House'))->toBe('Glow House');
    expect($this->get('/ar/')->getContent())->toContain('<b>Talk to Sara</b>')->toContain('Glow House');
});

/*
 * 6. THE END OF «بليس». The pink word is painted with background-clip:text,
 *    which paints only inside the em's box; the Arabic س runs past it. The
 *    fix gives the paint room and takes it back with an equal negative margin,
 *    /ar only, in the source and in the built bundle the shop serves.
 *    Mutation: drop the rule, or the negative margin (the logo grows).
 */
it('gives the Arabic wordmark\'s gradient room to paint the whole س, without moving the logo box', function () {
    $rule = '.logo.lgx.lgx-ar .lgx-w em{padding:.25em;margin:-.25em}';
    expect(file_get_contents(resource_path('css/kbb/kbb.css')))->toContain($rule);

    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb.css']['file']));
    expect($built)->toContain('.logo.lgx.lgx-ar .lgx-w em{padding:.25em;margin:-.25em}');
});

/*
 * 7. EVERY /ar PAGE, HIGHLIGHT ON, ZERO ⟪. The walk StorefrontEnglishUnchanged
 *    uses -- every storefront GET route, the 404, and the cart and checkout
 *    WITH a basket -- rendered on /ar with the review switch ON, so any
 *    interface string a future lane adds without published Arabic draws ⟪…⟫
 *    and fails here. Mutation: delete one published row the footer uses
 *    (store.footer.news_button) and this is red, naming the page.
 */
it('renders every /ar page type with no ⟪ even with the highlight switched on', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-06-15 09:30:00'));
    $seed = EnglishRenderWalk::seed($this);
    $expectations = EnglishRenderWalk::expectations($seed);
    ArabicShop::on();
    app(SettingsService::class)->set('translation_highlight_missing', true);
    arfFlush();

    $fresh = function (): void {
        $this->flushSession();
        app('auth')->forgetGuards();
        app(\App\Services\CartService::class)->forget();
        (function (): void {
            $this->defaultCookies = [];
            $this->unencryptedCookies = [];
            $this->withCredentials = false;
        })->call($this);
    };

    $pages = [];
    foreach ($expectations as $uri => $spec) {
        if (! ($spec['render'] ?? false)) {
            continue;
        }
        $fresh();
        $request = ($spec['auth'] ?? false) ? $this->withSession([EnglishRenderWalk::customerSessionKey() => $seed['customer']->id]) : $this;
        $pages[$uri] = $request->get('/ar'.EnglishRenderWalk::pathFor($uri, $spec))->getContent();
    }
    $fresh();
    $pages['(404)'] = $this->get('/ar/no-such-page-at-all')->getContent();
    foreach (['/cart', '/checkout'] as $path) {
        $fresh();
        $pages['(with a basket) '.$path] = $this->withCredentials()
            ->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class)
            ->withUnencryptedCookie(\App\Services\CartService::COOKIE, $seed['cart']->token)
            ->get('/ar'.$path)->getContent();
    }

    // The walk reached real Arabic pages, the two basket pages included.
    expect(count($pages))->toBeGreaterThan(25)
        ->and($pages['(with a basket) /cart'])->toContain('cartInner')->toContain('lang="ar"')
        ->and($pages['(with a basket) /checkout'])->toContain('kbbCheckoutForm');

    $gaps = [];
    foreach ($pages as $uri => $html) {
        if (preg_match_all('/⟪([^⟫]{0,80})⟫/u', $html, $m)) {
            $gaps[] = $uri.': '.implode(' | ', array_slice(array_unique($m[1]), 0, 6));
        }
    }
    expect($gaps)->toBe([], "Interface text with no published Arabic on /ar:\n  ".implode("\n  ", $gaps));
});
