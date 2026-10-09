<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Support\AdminCapabilities;
use App\Support\AdminNav;
use App\Support\AdminRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Lane AP -- the admin sidebar, server-rendered from one definition, and the
 * button that hides it.
 *
 * THE OWNER: "upon hard refresh some menu items from the left panel keeps
 * missing and page loading not complete and delayed ... must load everything
 * instantly with all menu items", and "the main left panel of the admin
 * section close and open icon ... must be smooth".
 *
 * What was on the shop: the sidebar was drawn by JavaScript from `const NAV`
 * and `const LATE_NAV` at the foot of a 650 KB script block -- empty for the
 * first 1.36 s of a throttled cold load, and #KBeautyBliss Spotted (in neither
 * list) missing until 5.96 s. Every role saw every row, including rows whose
 * screen answers that role with a 403.
 *
 * MUTATION NOTES, each one run:
 *   - AdminNav::visibleFor() returning GROUPS for everybody
 *       -> 'shows a role exactly the rows it may open' fails for support
 *          (Payments, Users & Roles... drawn for an account that gets 403).
 *   - give a row a `read` the route map does not know
 *       -> 'maps every row to a capability' fails naming it; and the row is
 *          owner-only in the meantime ('fails closed' pins that).
 *   - put `const NAV=[` back in app.blade.php
 *       -> 'keeps the console script a reader' fails.
 *   - drop `if (NAV_HIDDEN.has(screen)) return false;` from kbbAddNavEntry
 *       -> the same case fails: a partial would put a withheld row back.
 *   - delete the head script, or move it below the stylesheet
 *       -> 'applies the remembered state before the first paint' fails.
 */
function apConsole(string $role, string $query = ''): string
{
    $user = AdminUser::create([
        'name' => 'AP '.$role, 'email' => 'ap-'.$role.'-'.uniqid().'@example.test',
        'password' => 'password-long-enough', 'role' => $role,
    ]);

    return test()->actingAs($user, 'admin')->get('/admin'.$query)->assertOk()->getContent();
}

/** The ids inside the rendered <nav id="nav">, in order. */
function apNavIds(string $html): array
{
    $open = (int) strpos($html, '<nav class="nav" id="nav"');
    $nav = substr($html, $open, (int) strpos($html, '</nav>', $open) - $open);
    preg_match_all('/<button class="nav-item[^"]*" data-go="([a-z0-9-]+)"/', $nav, $m);

    return $m[1];
}

function apAppSrc(): string
{
    return (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

it('maps every row to a capability the route map knows, through a read that is a real GET route', function () {
    /*
     * A row whose capability resolves to null is owner-only (fail closed), so
     * an unmapped read would silently take a screen away from every manager.
     * Every `read` must be a GET route that exists and a path
     * AdminCapabilities maps; every `cap` must be a capability that exists.
     */
    $bad = [];
    foreach (AdminNav::rows() as $id => $row) {
        $has = (isset($row['read']) xor isset($row['cap']));
        if (! $has) {
            $bad[] = "{$id}: needs exactly one of `read` or `cap`";

            continue;
        }
        $cap = AdminNav::capability($row);
        // A `pending` row may name a capability its lane has not merged yet:
        // AdminRoles::can() answers an unknown one with no, so it is owner-only
        // until then -- closed, which is the point.
        if ($cap === null || (! array_key_exists($cap, AdminCapabilities::CAPABILITIES) && empty($row['pending']))) {
            $bad[] = "{$id}: resolves to ".var_export($cap, true);
        }
        if (isset($row['read'])) {
            try {
                Route::getRoutes()->match(Request::create('/'.$row['read'], 'GET'));
            } catch (Throwable $e) {
                $bad[] = "{$id}: GET /{$row['read']} is not a route";
            }
        }
    }

    expect($bad)->toBe([]);
    expect(count(AdminNav::rows()))->toBe(103); // + Store → Inquiries (lane CT); + Store → Security → Firewall (lane FW); + Safety → 404 page (lane NF, 2.60.404); + Growth & Marketing → Push Notifications (lane PN); + Platform → Domain switch (lane DW); + Growth & Marketing → Google Shopping feed (lane SEO); + Catalog → Image SEO (lane IR); + Appearance → Coming Soon page (lane CS)
});

it('fails closed: an unknown read, an unknown role and no account see nothing they cannot open', function () {
    expect(AdminNav::capability(['read' => 'admin-api/no-such-screen']))->toBeNull()
        ->and(AdminNav::capability([]))->toBeNull();

    $manager = new AdminUser(['role' => 'manager']);
    expect(AdminRoles::can($manager, null))->toBeFalse();

    // No account: an empty <nav>, no rows at all.
    expect(AdminNav::html(null))->toBe('<nav class="nav" id="nav"></nav>');

    // A role nobody knows holds nothing, so it sees nothing.
    expect(apNavIds(AdminNav::html(new AdminUser(['role' => 'intruder']))))->toBe([]);
});

it('shows a role exactly the rows it may open, server-side, and tells the script which it withheld', function () {
    /*
     * The capability filter is applied where the markup is made, so a row a
     * role cannot open is never in the page it receives. Checked for each
     * preset against AdminRoles::can() row by row, and spot-checked by name so
     * a wholesale mistake in both cannot pass.
     */
    $all = array_keys(AdminNav::rows());

    foreach (['owner', 'manager', 'support', 'editor'] as $role) {
        $html = apConsole($role);
        $user = AdminUser::where('role', $role)->latest('id')->first();
        $want = array_values(array_filter($all, fn ($id) => AdminRoles::can($user, AdminNav::capability(AdminNav::rows()[$id]))));

        expect(apNavIds($html))->toBe($want, "{$role} sees the wrong rows");

        preg_match('#window\.KBB_NAV=(\{.*?\});</script>#', $html, $m);
        $data = json_decode($m[1] ?? '', true);
        expect($data['hidden'])->toBe(array_values(array_diff($all, $want)), "{$role}: the script's withheld list disagrees with the markup");
    }

    $support = apNavIds(apConsole('support'));
    expect($support)->toContain('dash', 'orders', 'customers', 'rev-all')
        ->not->toContain('payments')->not->toContain('users')->not->toContain('settings')->not->toContain('updates')
        ->not->toContain('catalog');

    $owner = apNavIds(apConsole('owner'));
    expect($owner)->toBe($all);
});

it('stops every partial that builds a sidebar row from putting a withheld one back', function () {
    /*
     * Found in Chromium as support: the server left New Order out (it needs
     * orders.money), and manual-order-screen -- the one partial that still
     * builds its own <button> instead of calling kbbAddNavEntry() -- added it
     * back next to Orders once its script ran. A row the role is refused.
     *
     * Every partial that builds a nav row by hand must consult the withheld
     * list before it does; kbbAddNavEntry() itself is pinned in the case
     * above.
     *
     * MUTATION: delete the KBB_NAV.hidden line from manual-order-screen ->
     * this fails naming it.
     */
    $bad = [];
    foreach (glob(resource_path('views/admin/partials/*.blade.php')) ?: [] as $file) {
        $src = (string) file_get_contents($file);
        if (! preg_match('/function addNavEntry\(\)\s*\{(.*?)\n  \}/s', $src, $m) || ! str_contains($m[1], "className = 'nav-item'")) {
            continue;
        }
        $guard = strpos($m[1], 'window.KBB_NAV.hidden');
        if ($guard === false || $guard > strpos($m[1], 'createElement(')) {
            $bad[] = basename($file);
        }
    }

    expect($bad)->toBe([]);
});

it('keeps the console script a reader of AdminNav, never a second definition', function () {
    $src = apAppSrc();

    // The literals are gone; the only sidebar data the script has is the
    // printed JSON, and it is AdminNav's.
    expect($src)->not->toContain('const NAV=[')
        ->and($src)->not->toContain('const LATE_NAV=[')
        ->and($src)->toContain('const LATE_NAV_IDS=new Set(KBB_NAV_DATA.late);')
        ->and($src)->toContain('const NAV_HIDDEN=new Set(KBB_NAV_DATA.hidden);')
        ->and(substr_count($src, '\App\Support\AdminNav::html('))->toBe(1)
        ->and(substr_count($src, '\App\Support\AdminNav::forScript('))->toBe(1);

    // buildNav() binds; it draws nothing.
    $fn = substr($src, (int) strpos($src, 'function buildNav(){'), 700);
    $fn = substr($fn, 0, (int) strpos($fn, "\n}\n"));
    expect($fn)->not->toContain('innerHTML')->and($fn)->not->toContain('createElement');

    // A partial cannot put a withheld row back: the hidden check comes before
    // the helper looks for, or builds, a row.
    $helper = substr($src, (int) strpos($src, 'function kbbAddNavEntry(opts){'), 4000);
    $hidden = strpos($helper, 'if (NAV_HIDDEN.has(screen)) return false;');
    expect($hidden)->not->toBeFalse()
        ->and($hidden)->toBeLessThan(strpos($helper, "nav.querySelector('[data-go=\"' + screen"))
        ->and($hidden)->toBeLessThan(strpos($helper, 'createElement('));

    // And the rendered JSON is AdminNav's own answer for that account.
    $html = apConsole('editor');
    $user = AdminUser::where('role', 'editor')->latest('id')->first();
    expect($html)->toContain('window.KBB_NAV='.json_encode(AdminNav::forScript($user)).';</script>');
});

it('costs no query for an owner and the same handful for any other role however many rows there are', function () {
    $owner = AdminUser::create(['name' => 'Q Owner', 'email' => 'q-owner@example.test', 'password' => 'password-long-enough', 'role' => 'owner']);
    $manager = AdminUser::create(['name' => 'Q Manager', 'email' => 'q-manager@example.test', 'password' => 'password-long-enough', 'role' => 'manager']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    AdminNav::html($owner);
    AdminNav::forScript($owner);
    expect(count(DB::getQueryLog()))->toBe(0);

    AdminRoles::flush();
    DB::flushQueryLog();
    AdminNav::html($manager);
    AdminNav::forScript($manager);
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Resolved once per account per request (AdminRoles memoises), not per row.
    expect($n)->toBeLessThanOrEqual(2);
});

it('carries the App group after Platform, each row only for an account that may open it', function () {
    /*
     * The owner, via the integrator: a top-level "App" group, after Platform,
     * with Site App then Owner App. Site App's capability is Lane PW's and is
     * not in the map yet, so it is Full-Admin-only until it is (fail closed);
     * Owner App needs ownerapp.manage.
     */
    expect(array_column(AdminNav::GROUPS, 'sec'))->toContain('App')
        ->and(array_column(AdminNav::GROUPS, 'sec')[array_search('App', array_column(AdminNav::GROUPS, 'sec'), true) - 1])->toBe('Platform');
    $app = array_values(array_filter(AdminNav::GROUPS, fn ($g) => $g['sec'] === 'App'))[0];
    expect(array_column($app['rows'], 'id'))->toBe(['siteapp', 'ownerapp'])
        ->and(array_column($app['rows'], 'label'))->toBe(['Site App', 'Owner App'])
        ->and(AdminNav::capability(AdminNav::rows()['ownerapp']))->toBe('ownerapp.manage');

    expect(apNavIds(apConsole('owner')))->toContain('siteapp', 'ownerapp');
    // siteapp.manage (lane PW, 2.60.404) is owner + manager, like Catalog → Pagination;
    // the owner app stays Full-Admin only.
    expect(apNavIds(apConsole('manager')))->toContain('siteapp')->not->toContain('ownerapp');
    foreach (['support', 'editor'] as $role) {
        expect(apNavIds(apConsole($role)))->not->toContain('siteapp')->not->toContain('ownerapp');
    }
});

it('says "not installed yet" for a row whose screen has not arrived, instead of drawing the dashboard', function () {
    /*
     * go() ends `||renderDash`: a row with no renderer opened the DASHBOARD
     * under its own name, silently. kbbNoScreen() replaces that, after every
     * partial has run, on both paths a click takes (now, and the replay).
     *
     * MUTATION: drop either kbbNoScreen call -> red.
     */
    $src = apAppSrc();
    $fn = substr($src, (int) strpos($src, 'function kbbNavClick(id){'), 2600);

    expect($src)->toContain('function kbbNoScreen(id){')
        ->and($fn)->toContain("if(document.readyState!=='loading'){ kbbNoScreen(id); return; }")
        ->and($fn)->toContain("try{ window.go(want); }catch(e){}\n      kbbNoScreen(want);");

    $body = substr($src, (int) strpos($src, 'function kbbNoScreen(id){'), 900);
    expect($body)->toContain("if(id==='dash' || !$('#kbbDashWrap')) return;")
        ->and($body)->toContain(".textContent=label+' is not installed yet'");
});

it('paints the sidebar whole or not at all, never half a <nav>', function () {
    /*
     * Measured on a throttled hard refresh: the browser painted the first 57
     * of 93 rows for 217 ms, because the line had delivered only that much of
     * the <nav> -- "some menu items keep missing", reproduced by the fix.
     * The <nav> now arrives hidden and the script printed straight after
     * </nav> (which cannot run before the whole <nav> is parsed) shows it.
     *
     * MUTATION: drop ' data-wait' from AdminNav::html() -> red; drop the
     * removeAttribute call -> red (and the sidebar would never show).
     */
    $html = apConsole('owner');
    $at = strpos($html, '<nav class="nav" id="nav" data-wait>');
    $close = strpos($html, '</nav>', (int) $at);

    expect($at)->not->toBeFalse()
        ->and(substr($html, $close, 80))->toStartWith("</nav>\n    <script>document.getElementById('nav').removeAttribute('data-wait');")
        ->and($html)->toContain('#nav[data-wait]{visibility:hidden}');
});

it('applies the remembered hidden state before the first paint, from a head script', function () {
    /*
     * The class must be on <html> before the stylesheet is applied, or a
     * console left with its menu hidden paints it open and then snaps shut.
     * localStorage is a convenience: wrapped, so a blocked store means "shown".
     */
    $src = apAppSrc();
    $head = substr($src, 0, (int) strpos($src, '</head>'));
    $script = "<script>try{if(localStorage.getItem('kbb.admin.side')==='closed')document.documentElement.classList.add('side-closed')}catch(e){}</script>";

    expect(substr_count($src, $script))->toBe(1)
        ->and(strpos($head, $script))->toBeLessThan(strpos($head, '<style>'));
});

it('gives the sidebar one accessible hide / show button that slides with transform and respects reduced motion', function () {
    $html = apConsole('owner');

    // One button, a real <button>, labelled, wired to the sidebar.
    expect(substr_count($html, 'id="sideTog"'))->toBe(1)
        ->and($html)->toContain('<button class="iconbtn sidetog" id="sideTog" type="button" aria-controls="side" aria-expanded="true" aria-label="Hide menu"')
        ->and($html)->toContain("say=shut?'Show menu':'Hide menu'")
        ->and($html)->toContain("b.setAttribute('aria-expanded',shut?'false':'true')");

    // Animated by transform; the content's width is not transitioned.
    $css = substr($html, (int) strpos($html, '/* ---------- LANE AP · hide / show the sidebar'), 4000);
    expect($css)->toContain('transform:translateX(-100%)')
        ->and($css)->toContain('@keyframes kbbMainClose{from{transform:translateX(248px)}to{transform:none}}')
        ->and($css)->toContain('@media(prefers-reduced-motion:reduce)')
        ->and($css)->not->toMatch('/transition:[^;}]*\b(width|margin|left|grid-template-columns)\b/');

    // Desktop only: the phone keeps its drawer and its menu button.
    expect($css)->toContain('@media(max-width:880px){.sidetog{display:none}}')
        ->and($html)->toContain("<button class=\"iconbtn menubtn\" onclick=\"document.getElementById('side').classList.toggle('open')\">");

    // The state write is wrapped, and the new code measures nothing.
    $js = substr($html, (int) strpos($html, '/* 3. Hide / show the sidebar (desktop).'), 2200);
    expect($js)->toContain("try{localStorage.setItem(KEY,shut?'closed':'open');}catch(e){}");
    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'scrollWidth', 'getComputedStyle'] as $api) {
        expect($js)->not->toContain($api);
    }
});
