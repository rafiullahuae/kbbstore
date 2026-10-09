<?php

declare(strict_types=1);

/**
 * Lane IGR — the Instagram API module is retired, and the #KBeautyBliss Spotted
 * page draws the Instagram embeds.
 *
 * THE OWNER, 9 October 2026: "the instagram new embed function should go auto on
 * the #KBeautyBlissSpoted. and the old instagram api etc will be discontinue from
 * the app, and also the instagram connect page too."
 *
 * Every case pins the FINISHED state (CLAUDE.md, "Pin the FINISHED state"): no
 * route, no screen, no nav row, no schedule, no capability, no module switch, no
 * token left in `settings`, the homepage row moved, the shortcode rewritten, the
 * Spotted page drawing Content → Instagram embeds — and the module's files, which
 * a package cannot delete, referenced by nothing live.
 */

use App\Models\AdminUser;
use App\Models\Page;
use App\Models\Setting;
use App\Models\SpottedPost;
use App\Services\HomepageLayouts;
use App\Services\HomepageSections;
use App\Services\InstagramEmbeds;
use App\Services\ModuleRegistry;
use App\Services\SettingsService;
use App\Services\SpottedSettings;
use App\Support\AdminCapabilities;
use App\Support\AdminNav;
use App\Support\AdminRoles;
use App\Support\Shortcodes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\RetiredInstagramApi;
use Tests\Support\SpottedRoutes;

function igrMigration(string $name): object
{
    return require base_path('database/migrations/'.$name.'.php');
}

/** Paste $n embeds (codes in Instagram's alphabet) and switch the section on. */
function igrEmbeds(int $n, array $options = []): void
{
    $items = [];

    for ($i = 1; $i <= $n; $i++) {
        $items[] = ['c' => 'DIgr'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'k' => $i % 3 === 0 ? 'reel' : 'p', 'l' => '', 'on' => true];
    }

    app(InstagramEmbeds::class)->save($options + ['on' => true], $items);
    SettingsService::forgetMemo();
}

function igrAdmin(string $role): AdminUser
{
    return AdminUser::create([
        'name' => ucfirst($role), 'email' => 'igr-'.$role.'-'.Str::random(6).'@example.com',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

beforeEach(function () {
    SpottedRoutes::wire($this->app);
    SpottedSettings::flush();
});

/* ═══════════════════════════════════════════ the module is unmounted ═══ */

it('registers no Instagram API route: no connect, no callback, no picker', function () {
    // MUTATION: put `require __DIR__.'/instagram-admin.php';` back in
    // routes/web.php and this is red, naming admin-api/instagram/start.
    $found = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $name = (string) $route->getName();

        if (preg_match('#^admin-api/(instagram|spotted/instagram)(/|$)#', $uri) || preg_match('/(^|\.)instagram(\.|$)/', $name)) {
            $found[] = $uri.' '.$name;
        }
    }

    expect($found)->toBe([])
        ->and(substr_count((string) file_get_contents(base_path('routes/web.php')), "require __DIR__.'/instagram-admin.php';"))->toBe(0);

    // Instagram embeds' own routes are untouched.
    expect(Route::has('admin.igembeds'))->toBeTrue();
});

it('has no sidebar row, no console screen and no search entry for the API module', function () {
    // MUTATION: restore the `instagram` row in App\Support\AdminNav, or the
    // @include('admin.partials.instagram-screen') in app.blade.php: red.
    expect(AdminNav::rows())->not->toHaveKey('instagram')
        ->and(AdminNav::rows())->toHaveKey('igembeds');

    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($app, "@include('admin.partials.instagram-screen')"))->toBe(0)
        ->and($app)->not->toContain("'instagram':['Content','Instagram']")
        ->and(preg_match('/const LATE_RENDERED=new Set\(\[([^\]]*)\]\);/', $app, $m))->toBe(1)
        ->and($m[1])->not->toContain("'instagram'")
        ->and(substr_count($m[1], "'igembeds'"))->toBe(1);

    $search = (string) file_get_contents(app_path('Support/AdminSearchIndex.php'));
    expect($search)->not->toContain('InstagramSettings')->and($search)->not->toContain('Instagram app secret');

    // The owner app never carried a piece of it; keep it that way.
    foreach (array_merge(glob(app_path('Http/Controllers/OwnerApp/*.php')) ?: [], glob(app_path('Services/OwnerApp/*.php')) ?: [], [base_path('routes/owner-app.php')]) as $file) {
        expect(str_contains(strtolower((string) file_get_contents($file)), 'instagram'))->toBeFalse(basename($file));
    }
});

it('schedules nothing that calls Instagram, and the command left on disk does nothing', function () {
    // MUTATION: restore Schedule::command('kbb:instagram-sync --unattended') in
    // routes/console.php and the first expectation is red.
    $events = array_map(fn ($e) => (string) $e->command, app(Schedule::class)->events());

    expect(array_values(array_filter($events, fn ($c) => str_contains($c, 'instagram'))))->toBe([]);

    $this->artisan('kbb:instagram-sync')->expectsOutputToContain('retired')->assertExitCode(0);
    expect((string) file_get_contents(app_path('Console/Commands/InstagramSyncCommand.php')))->not->toContain('InstagramSync $');
});

it('grants no Instagram API capability, maps no admin-api/instagram path and has no module switch', function () {
    // MUTATION: restore 'instagram.manage' in AdminCapabilities::CAPABILITIES
    // and the first expectation is red.
    $caps = array_keys(AdminCapabilities::CAPABILITIES);

    expect(array_values(array_filter($caps, fn ($c) => str_starts_with($c, 'instagram.') || $c === 'spotted.instagram')))->toBe([])
        ->and($caps)->toContain('igembeds.manage')
        ->and(AdminRoles::labels())->not->toHaveKey('instagram.manage');

    foreach (AdminCapabilities::RULES as [$method, $path]) {
        expect($path)->not->toStartWith('admin-api/instagram')->not->toStartWith('admin-api/spotted/instagram');
    }

    expect(ModuleRegistry::REGISTRY)->not->toHaveKey('instagram_profile');
});

/* ═════════════════════════════════════════════ no token left behind ═══ */

it('deletes every stored Instagram and Facebook credential, and only those', function () {
    // MUTATION: drop 'instagram_token' from the migration's KEYS and narrow its
    // sweep, and the token row survives: red.
    $secrets = ['instagram_app_id', 'instagram_app_secret', 'instagram_token', 'instagram_token_expires',
        'instagram_user_id', 'instagram_via', 'instagram_fb_app_id', 'instagram_fb_app_secret',
        'instagram_fb_config_id', 'instagram_fb_page_id', 'instagram_fb_page_name', 'instagram_token_invalid',
        // Not in the class's list: caught by the `instagram_` sweep.
        'instagram_some_future_key'];

    foreach ($secrets as $key) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => 'fake-'.$key, 'autoload' => false]);
    }

    // Keys that merely MENTION Instagram belong to live features and stay.
    $keep = ['igembed_items' => '[]', 'mail_support_instagram' => 'https://instagram.com/kbeauty.bliss', 'seo_soc_ig' => 'kbeauty.bliss'];
    foreach ($keep as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    Cache::put('kbb.instagram.fb.pending', ['token' => 'fake-user-token'], 600);
    Cache::put('kbb.spotted.ig.cards', ['cards' => [1]], 600);

    ob_start();
    igrMigration('2027_10_15_140100_forget_instagram_api_credentials')->up();
    $said = (string) ob_get_clean();

    expect(DB::table('settings')->where('key', 'like', 'instagram%')->pluck('key')->all())->toBe([])
        ->and(DB::table('settings')->whereIn('key', array_keys($keep))->count())->toBe(3)
        ->and(Cache::has('kbb.instagram.fb.pending'))->toBeFalse()
        ->and(Cache::has('kbb.spotted.ig.cards'))->toBeFalse()
        ->and(app(SettingsService::class)->all())->not->toHaveKey('instagram_token');

    // It reports key NAMES, never a value.
    expect($said)->toContain('instagram_token')->not->toContain('fake-');
});

/* ═════════════════════════════════════════════ the homepage row moved ═══ */

it('puts Instagram embeds where an ON Instagram Profile row stood, on every device either was on', function () {
    // MUTATION: make the migration copy only the old row's flags (drop the
    // `||`) and igembeds' mobile switch goes off: red. Drop the `order` copy and
    // it stays at the bottom: red.
    $settings = app(SettingsService::class);
    $keys = array_keys(HomepageSections::REGISTRY);
    $saved = [];
    foreach ($keys as $i => $key) {
        $saved[$key] = ['order' => $i + 10];
    }
    $saved['instagram'] = ['desktop' => true, 'mobile' => false, 'order' => 2];
    $saved['igembeds'] = ['desktop' => false, 'mobile' => true, 'order' => 99];
    $settings->set('homepage_sections', $saved);

    ob_start();
    igrMigration('2027_10_15_140200_move_homepage_instagram_row_to_embeds')->up();
    $said = (string) ob_get_clean();

    $after = $settings->get('homepage_sections');
    expect($after)->not->toHaveKey('instagram')
        ->and($after['igembeds'])->toMatchArray(['desktop' => true, 'mobile' => true, 'order' => 2])
        ->and($said)->toContain('1 row changed');

    // In the order the page reads: ahead of every row saved after slot 2,
    // exactly where the old row was.
    $all = app(HomepageSections::class)->all();
    $movable = array_keys(array_filter($all, fn ($r) => $r['movable']));
    expect($movable[0])->toBe('igembeds')
        ->and($all['igembeds']['desktop'])->toBeTrue()
        ->and($all['igembeds']['mobile'])->toBeTrue();
});

it('leaves Instagram embeds alone when the Instagram Profile row was off', function () {
    $settings = app(SettingsService::class);
    $settings->set('homepage_sections', ['instagram' => ['desktop' => false, 'mobile' => false, 'order' => 1], 'igembeds' => ['desktop' => true, 'mobile' => false, 'order' => 7]]);

    ob_start();
    igrMigration('2027_10_15_140200_move_homepage_instagram_row_to_embeds')->up();
    $said = (string) ob_get_clean();

    expect($settings->get('homepage_sections'))->toBe(['igembeds' => ['desktop' => true, 'mobile' => false, 'order' => 7]])
        ->and($said)->toContain('0 rows changed');
});

it('dropped the Instagram Profile row from the registry, every preset and the homepage template', function () {
    expect(HomepageSections::REGISTRY)->not->toHaveKey('instagram')
        ->and(HomepageSections::OFF_BY_DEFAULT)->not->toContain('instagram');

    foreach (HomepageLayouts::LAYOUTS as $name => $layout) {
        expect(in_array('instagram', $layout['sections'], true))->toBeFalse($name)
            ->and(in_array('instagram', $layout['off'] ?? [], true))->toBeFalse($name);
    }

    expect((string) file_get_contents(resource_path('views/store/home.blade.php')))
        ->not->toContain("Shortcodes::render('[kbb_instagram]')");
});

/* ═════════════════════════════════════════════ the shortcode rewritten ═══ */

it('rewrites every stored [kbb_instagram] to [kbb_instagram_embeds], keeping a title and the Arabic current', function () {
    // MUTATION: make rewrite() return its input and the page keeps the old tag: red.
    $old = '<p>Intro</p>[kbb_instagram layout="rail" limit="6" title="Our feed"]<p>and</p>[kbb_instagram][kbb_instagram_embeds max="3"]';
    $page = Page::create(['title' => 'IGR', 'slug' => 'igr-shortcode', 'content' => $old, 'status' => 'publish']);
    DB::table('translations')->insert(['locale' => 'ar', 'group' => 'page', 'item_id' => $page->id, 'field' => 'content',
        'value' => '[kbb_instagram]', 'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($old),
        'created_at' => now(), 'updated_at' => now()]);

    ob_start();
    igrMigration('2027_10_15_140300_rewrite_kbb_instagram_shortcode')->up();
    $said = (string) ob_get_clean();

    $new = '<p>Intro</p>[kbb_instagram_embeds title="Our feed"]<p>and</p>[kbb_instagram_embeds][kbb_instagram_embeds max="3"]';
    $tr = DB::table('translations')->where('item_id', $page->id)->first();

    expect((string) $page->fresh()->content)->toBe($new)
        ->and($tr->value)->toBe('[kbb_instagram_embeds]')
        ->and($tr->source_hash)->toBe(sha1($new))
        ->and($said)->toContain('2 row(s)');

    // Twice changes nothing.
    ob_start();
    igrMigration('2027_10_15_140300_rewrite_kbb_instagram_shortcode')->up();
    expect((string) ob_get_clean())->toContain('0 row(s)');
});

it('renders a [kbb_instagram] typed after the rewrite as nothing at all', function () {
    // MUTATION: delete the retired arm from Shortcodes::render() and the tag is
    // printed for the shopper to read: red.
    expect(Shortcodes::render('<p>a</p>[kbb_instagram layout="rail"]<p>b</p>'))->toBe('<p>a</p><p>b</p>');
});

/* ═══════════════════════════════════════ the Spotted page draws embeds ═══ */

it('draws Content → Instagram embeds on the Spotted page, above the manual posts, with no script', function () {
    // MUTATION: make SpottedSettings::instagramEmbeds() return '' and there is
    // no .kie on the page: red.
    SpottedPost::create(['image' => '/uploads/spotted/igr.jpg', 'ig_url' => 'https://www.instagram.com/p/IgrManual1/',
        'handle' => 'lina.skin', 'caption' => 'Toner', 'sort' => 1, 'on_home' => true, 'on_page' => true]);
    $plain = $this->get('/kbeautybliss-spotted/')->assertOk()->getContent();

    igrEmbeds(4, ['heading' => 'Seen on Instagram']);
    $html = $this->get('/kbeautybliss-spotted/')->assertOk()->getContent();

    expect(substr_count($html, 'class="kie '))->toBe(1)
        ->and(substr_count($html, 'class="kie-card '))->toBe(4)
        ->and(substr_count($html, 'loading="lazy" title='))->toBe(4)
        ->and(strpos($html, 'class="kie '))->toBeLessThan(strpos($html, 'class="spt-grid'))
        ->and($html)->toContain('src="https://www.instagram.com/p/DIgr0000001/embed/"')
        ->and($html)->toContain('<h2 class="kie-h">Seen on Instagram</h2>')
        ->and($html)->toContain('/uploads/spotted/igr.jpg');

    // The embeds add no script of any kind: IGE draws none.
    expect(substr_count($html, '<script'))->toBe(substr_count($plain, '<script'));
});

it('follows the "Show Instagram embeds on this page" switch and its heading, saved through the admin endpoint', function () {
    // MUTATION: ship `page_igembeds` default false and the first fetch has no
    // embeds: red (the owner asked for ON).
    igrEmbeds(2, ['heading' => 'Seen on Instagram']);
    expect(SpottedSettings::SCHEMA['page_igembeds']['default'])->toBeTrue()
        ->and($this->get('/kbeautybliss-spotted/')->getContent())->toContain('class="kie ');

    $editor = igrAdmin('editor');
    $this->actingAs($editor, 'admin')->postJson('/admin-api/spotted/settings', ['settings' => ['page_igembeds_title' => 'Our <b>feed</b>']])
        ->assertOk()->assertJsonPath('ok', true);
    SettingsService::forgetMemo();
    $titled = $this->get('/kbeautybliss-spotted/')->getContent();
    expect($titled)->toContain('<h2 class="kie-h">Our feed</h2>');

    $this->actingAs($editor, 'admin')->postJson('/admin-api/spotted/settings', ['settings' => ['page_igembeds' => false]])->assertOk();
    SettingsService::forgetMemo();
    expect($this->get('/kbeautybliss-spotted/')->getContent())->not->toContain('class="kie ');

    // The tab the control sits on: Appearance → #KBeautyBliss Spotted → Spotted page.
    $tabs = $this->actingAs($editor, 'admin')->getJson('/admin-api/spotted')->assertOk()->json('tabs');
    $page = collect($tabs)->firstWhere('key', 'page');
    expect(array_slice(array_column($page['fields'], 'key'), 0, 2))->toBe(['page_igembeds', 'page_igembeds_title']);
});

it('is indexable with only embeds on it, and stays noindex when it has nothing', function () {
    // MUTATION: drop the `$embeds === ''` from the controller's noindex test
    // and an embeds-only page asks Google to skip it: red.
    expect($this->get('/kbeautybliss-spotted/')->getContent())->toContain('noindex');

    igrEmbeds(3);
    SpottedSettings::flush();
    $html = $this->get('/kbeautybliss-spotted/')->getContent();
    expect($html)->not->toContain('noindex')
        ->and(app(SpottedSettings::class)->pageIsLive())->toBeTrue();
});

it('costs the same queries with 3 embeds as with 24', function () {
    // The embeds are settings the request has already read (SettingsRequestMemo),
    // so the page's cost is flat as the list grows.
    $counts = [];

    foreach ([3, 24] as $n) {
        igrEmbeds($n, ['max' => '24']);
        SpottedSettings::flush();
        $this->get('/kbeautybliss-spotted/'); // warm
        SettingsService::forgetMemo();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->get('/kbeautybliss-spotted/')->getContent();
        $counts[$n] = count(DB::getQueryLog());
        DB::disableQueryLog();
        expect(substr_count($html, 'class="kie-card '))->toBe($n);
    }

    expect($counts[3])->toBe($counts[24]);
});

/* ═════════════════════════════════════ the files left on disk are dead ═══ */

it('keeps the retired files unreferenced by anything live', function () {
    // An update package cannot delete a file (`kbb:package` selects
    // --diff-filter=ACMR), so the module's files stay on the server and here;
    // this proves nothing can reach them. MUTATION: add
    // `use App\Services\InstagramFeed;` to any live class: red, naming it.
    foreach (RetiredInstagramApi::FILES as $file) {
        expect(is_file(base_path($file)))->toBeTrue($file.' is listed as retired but is not on disk -- take it off the list');
    }

    $hits = [];
    $roots = [app_path(), base_path('routes'), resource_path('views'), config_path(), base_path('bootstrap/app.php'), base_path('bootstrap/providers.php')];

    foreach ($roots as $root) {
        $files = is_dir($root) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) : [new SplFileInfo($root)];

        foreach ($files as $f) {
            $path = $f->getPathname();

            if (! $f->isFile() || ! str_ends_with($path, '.php') || RetiredInstagramApi::isRetired($path)) {
                continue;
            }

            $code = (string) file_get_contents($path);

            // Prose is not a reference: Blade comments, then PHP comments.
            $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $code);
            if (str_ends_with($path, '.blade.php')) {
                // A <script>'s block comments are prose too.
                $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
            }
            $tokens = @token_get_all($code);
            $live = '';

            foreach ($tokens as $t) {
                $live .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $t[1]) : $t;
            }

            foreach (RetiredInstagramApi::NAMES as $name) {
                if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/', $live)) {
                    $hits[] = str_replace(base_path().'/', '', $path).' names '.$name;
                }
            }
        }
    }

    expect($hits)->toBe([]);
});
