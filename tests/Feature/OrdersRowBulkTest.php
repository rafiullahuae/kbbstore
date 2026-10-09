<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\Order;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Tests\Support\CompiledCaches;
use Tests\Support\OrdersAdminRoutes;
use Tests\Support\OwnerAppRoutes as OA;
use Tests\Support\PreviewPort;

/**
 * Lane ORD. The owner, on the Orders list:
 *
 *   "the whole row click should take me to the order details page. and also
 *    upon bulk selection, the statuses should ask me confirmation beside the
 *    statuses selection with a button 'Proceed' and then the bulk statuses
 *    will changed. need super lightening and optimized function. also in
 *    mobile owner app, we need bulk change statuses, and long press on rows
 *    will give bulk selection. or by checkbox"
 *
 * The server half is pinned over HTTP; the screens are pinned in their source
 * here and, with KBB_BROWSER_TESTS=1, driven in Chromium at the bottom.
 */

function ordAdminSource(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $a = strpos($src, 'LANE V · Store · Orders — BEGIN');
    $b = strpos($src, 'LANE V · Store · Orders — END');

    return substr($src, (int) $a, (int) $b - (int) $a);
}

function ordJsFunction(string $source, string $name): string
{
    $at = strpos($source, 'function '.$name.'(');
    expect($at)->not->toBeFalse("function {$name} is missing");
    $next = strpos($source, "\n  function ", $at + 10);
    $nextAsync = strpos($source, "\n  async function ", $at + 10);
    $ends = array_filter([$next, $nextAsync], fn ($x) => $x !== false);

    return substr($source, (int) $at, ($ends ? min($ends) : strlen($source)) - (int) $at);
}

function ordOrders(int $n, string $status = 'processing'): array
{
    static $k = 0;
    $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $k++;
        $c = Customer::create(['name' => 'Ord Buyer '.$k, 'email' => 'ord-buyer-'.$k.'@example.test']);
        $ids[] = (int) Order::create([
            'customer_id' => $c->id, 'order_number' => 'ORD-'.str_pad((string) $k, 5, '0', STR_PAD_LEFT), 'email' => $c->email,
            'status' => $status, 'total' => 10000 + $k, 'subtotal' => 10000, 'currency' => 'AED',
            'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
        ])->id;
    }

    return $ids;
}

function ordAdmin(string $role = 'owner'): AdminUser
{
    static $wired = null;
    if ($wired !== spl_object_id(app())) {
        OrdersAdminRoutes::wire(app());
        $wired = spl_object_id(app());
    }
    $u = AdminUser::create(['name' => 'Ord '.$role, 'email' => 'ord-'.$role.'-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
    test()->actingAs($u, 'admin');

    return $u;
}

/* ============================================================ the server */

it('changes N orders in ONE request and answers with what the screen needs to redraw them in place', function () {
    // DEFECT: the screen reloaded the whole list after a bulk change because
    // the answer named no rows, and "already Completed" orders were silently
    // counted out. MUTATION: drop changed_ids / unchanged_ids from
    // OrdersApiController::bulkStatus's response -> red.
    ordAdmin();
    $todo = ordOrders(3);
    $already = ordOrders(1, 'completed');

    DB::enableQueryLog();
    $r = test()->postJson('/admin-api/orders-bulk-status', ['ids' => array_merge($todo, $already), 'status' => 'completed'])->assertOk();
    $selects = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with(strtolower(\Tests\Support\SqlShape::portable($q['query'])), 'select "id", "order_number", "status", "total" from "orders"'))->count();
    DB::disableQueryLog();

    expect($r->json('changed'))->toBe(3)
        ->and($r->json('changed_ids'))->toEqualCanonicalizing($todo)
        ->and($r->json('unchanged_ids'))->toBe($already)
        ->and($r->json('revenue'))->toBeTrue()
        // One read of the selection, not one per order.
        ->and($selects)->toBe(1);
});

it('still runs the per-order rules on every order: the note, the revenue guard and the revive refusal', function () {
    // MUTATION: replace the moveTo() loop with Order::whereIn()->update() ->
    // the note expectation is red; drop the $losesRevenue check -> the
    // revenue skip is red.
    ordAdmin();
    [$a, $b] = ordOrders(2);

    test()->postJson('/admin-api/orders-bulk-status', ['ids' => [$a], 'status' => 'shipped'])->assertOk();
    expect(DB::table('order_notes')->where('order_id', $a)->latest('id')->value('content'))
        ->toContain('Status changed from processing to shipped.')->toContain('Set from the orders list.');

    $r = test()->postJson('/admin-api/orders-bulk-status', ['ids' => [$b], 'status' => 'cancelled'])->assertOk();
    expect($r->json('changed'))->toBe(0)
        ->and($r->json('skipped.0.forceable'))->toBeTrue()
        ->and(Order::find($b)->status)->toBe('processing');
});

it('sends the customer emails after the answer, not before it', function () {
    // DEFECT: every status email went out INSIDE the request, so fifty
    // "Completed" emails on the shop's SMTP relay (~600 ms each) held Proceed
    // for half a minute. Measured with a 100 ms stand-in per message: 5,859 ms
    // to the answer before, 194 ms after.
    // MUTATION: delete OrderMailer::deferUntilResponse() from bulkStatus() ->
    // a send is seen before RequestHandled and this is red.
    ordAdmin();
    $ids = ordOrders(3);
    $order = [];
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function () use (&$order) { $order[] = 'mail'; });
    \Illuminate\Support\Facades\Event::listen(\Illuminate\Foundation\Http\Events\RequestHandled::class, function () use (&$order) { $order[] = 'answered'; });

    test()->postJson('/admin-api/orders-bulk-status', ['ids' => $ids, 'status' => 'shipped'])->assertOk()->assertJsonPath('changed', 3);

    expect($order)->toContain('mail')
        ->and($order[0])->toBe('answered');
});

it('takes a whole 500-row page in one request, read in chunks', function () {
    // DEFECT: the list offers 500 per page and a header tick selects the
    // page, but the endpoint refused anything over 200 with "nothing was
    // changed". MUTATION: put the cap back to BULK_MAX -> 422, red.
    ordAdmin();
    expect(\App\Http\Controllers\Admin\OrdersApiController::BULK_STATUS_MAX)->toBe(500);
    $ids = ordOrders(3);
    $padded = array_merge($ids, range(900001, 900001 + 496));   // 500 ids, 497 of them gone

    DB::enableQueryLog();
    test()->postJson('/admin-api/orders-bulk-status', ['ids' => $padded, 'status' => 'onhold'])->assertOk()->assertJsonPath('changed', 3);
    $reads = collect(DB::getQueryLog())->filter(fn ($q) => str_contains(\Tests\Support\SqlShape::portable($q['query']), 'select "id", "order_number", "status", "total"'))->count();
    DB::disableQueryLog();
    expect($reads)->toBe(5);   // 500 / BULK_CHUNK(100)

    test()->postJson('/admin-api/orders-bulk-status', ['ids' => range(1, 501), 'status' => 'onhold'])->assertStatus(422);
});

it('tells the screen which statuses email the customer, from the mail policy itself', function () {
    // MUTATION: hard-code status_emails, or drop it -> the switch below no
    // longer moves the answer, red.
    ordAdmin();
    ordOrders(1);
    expect(test()->getJson('/admin-api/orders-list')->json('status_emails'))
        ->toMatchArray(['shipped' => true, 'pending' => false]);

    app(\App\Services\Mail\OrderStatusMailPolicy::class)->setEnabled('shipped', false);
    expect(test()->getJson('/admin-api/orders-list')->json('status_emails.shipped'))->toBeFalse();
});

it('keeps the admin capability on the bulk endpoint and refuses a role without it', function () {
    // MUTATION: remap admin-api/orders-bulk-status to orders.view, or drop
    // the row (the closed default then lets nobody but the owner in) -> red.
    expect(AdminCapabilities::forPath('POST', 'admin-api/orders-bulk-status'))->toBe('orders.manage');
    $id = ordOrders(1)[0];

    ordAdmin('editor');
    test()->postJson('/admin-api/orders-bulk-status', ['ids' => [$id], 'status' => 'shipped'])->assertForbidden();
    expect(Order::find($id)->status)->toBe('processing');

    ordAdmin('support');
    test()->postJson('/admin-api/orders-bulk-status', ['ids' => [$id], 'status' => 'shipped'])->assertOk();
});

it('gives the owner app the same answer through its own auth, and refuses a member without orders.manage', function () {
    // MUTATION: drop the refuse(..., 'orders.manage') line in
    // OwnerApp\OrdersController::bulkStatus -> the editor's call is 200, red.
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
    OA::member(OA::admin());
    [$c, $csrf] = OA::enrol($this);
    [$a, $b] = ordOrders(2);
    DB::table('orders')->where('id', $b)->update(['status' => 'shipped']);

    $r = OA::post($this, 'orders-bulk-status', ['ids' => [$a, $b], 'status' => 'shipped'], $c, $csrf)->assertOk();
    expect($r->json('changed_ids'))->toBe([$a])->and($r->json('unchanged_ids'))->toBe([$b]);
    expect(OA::get($this, 'orders', $c, $csrf)->json('emails'))->toHaveKey('shipped');

    // Without a session the app's own guard answers first.
    OA::post($this, 'orders-bulk-status', ['ids' => [$a], 'status' => 'completed'], [], null)->assertStatus(401);

    $editor = OA::admin('editor', 'ord-editor@example.com', 'Ord Editor');
    OA::member($editor, '735190');
    [$c2, $csrf2] = OA::enrol($this, 'ord-editor@example.com', '735190');
    OA::post($this, 'orders-bulk-status', ['ids' => [$a], 'status' => 'completed'], $c2, $csrf2)->assertForbidden();
    expect(Order::find($a)->status)->toBe('shipped');
});

it('keeps the orders list flat as the shop grows, with the new field in it', function () {
    // MUTATION: compute status_emails per row -> the counts differ, red.
    ordAdmin();
    ordOrders(3);
    $count = function (string $q) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/admin-api/orders-list?'.$q)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $count('per_page=50');
    $three = $count('per_page=50');
    ordOrders(47);
    // The first request after the inserts also flushes the owner app's queued
    // "new order" pushes (a terminating callback); that is not the list.
    $count('per_page=50');
    $fifty = $count('per_page=50');

    expect($fifty)->toBe($three);
});

/* ============================================================ the screens */

it('makes the order number a real link to the order and the row the target', function () {
    // DEFECT: only the "View" button opened an order; a click anywhere else
    // on the row did nothing, and there was no address to open in a new tab.
    // MUTATION: drop data-olrow from the <tr>, or point olHref anywhere but
    // '#orders/' + id -> red. The deep-link half: drop the sub branch from the
    // window.go('orders') wrapper and the Chromium run lands on the list.
    $src = ordAdminSource();
    expect($src)->toContain('<tr class="olrow" data-olrow="\' + o.id + \'" tabindex="0"')
        ->toContain('<a class="ollink" href="\' + sesc(olHref(o.id)) + \'" data-olopen="\' + o.id + \'" tabindex="-1">')
        ->toContain("function olHref(id){ return location.pathname + '#orders/' + (+id); }")
        ->toContain('tbl.onclick = olRowClick;')
        ->toContain('tbl.onauxclick = olRowClick;')
        ->toContain('tbl.onkeydown = olRowKey;');

    $full = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect($full)->toContain("if(id==='orders'){ _go(id); return /^[0-9]{1,10}$/.test(String(sub || '')) ? renderOrderDetail(+sub) : renderOrders(); }");
});

it('lets the checkbox, buttons, selects and a drag-select keep their own clicks', function () {
    // MUTATION: remove .cbx or button from OL_OWN_CLICK, or the selection
    // check -> a tick or a copy opens the order (the Chromium run sees it too).
    $src = ordAdminSource();
    expect($src)->toContain("var OL_OWN_CLICK = 'button,select,input,textarea,label,.cbx,[data-olsel],a:not([data-olopen])';");
    $click = ordJsFunction($src, 'olRowClick');
    expect($click)->toContain('if(e.target.closest(OL_OWN_CLICK)) return;')
        ->toContain('if(picked && tr.contains(window.getSelection().anchorNode)) return;')
        ->toContain("if(newTab){ window.open(olHref(id), '_blank', 'noopener'); return; }")
        ->toContain('if(link && newTab) return;');
    expect(ordJsFunction($src, 'olRowKey'))->toContain("e.key !== 'Enter'");
});

it('defines the selection reader every control in the bulk bar calls', function () {
    // DEFECT, on main: olSelectedIds() was called by Set status, Print, Move
    // to trash and Restore and defined nowhere, so every one of them threw a
    // ReferenceError and the bar did nothing. MUTATION: delete the function
    // -> red here, and the Chromium run records the pageerror.
    $src = ordAdminSource();
    expect(substr_count($src, 'function olSelectedIds(){'))->toBe(1)
        ->and(substr_count($src, 'olSelectedIds()'))->toBeGreaterThan(4);
});

it('arms a chosen status beside the select and sends nothing until Proceed', function () {
    // DEFECT: choosing "Set status to…" opened a modal whose one button
    // applied it; the owner asked for the confirmation beside the select.
    // MUTATION: call olRunStatus from the select's onchange, or from
    // olConfirmStatus -> red here and in Chromium (a request before Proceed).
    $src = ordAdminSource();
    $confirm = ordJsFunction($src, 'olConfirmStatus');
    expect($confirm)->not->toContain('olRunStatus')->not->toContain('api(')->not->toContain('openModal');
    expect($src)->toContain("olConfirmStatus(olSelectedIds(), e.target.value);")
        ->toContain("if(OL.pend) olRunStatus(olSelectedIds(), OL.pend, false);")
        ->toContain('customers will be emailed');
    expect(substr_count($src, "api('/admin-api/orders-bulk-status'"))->toBe(1);
    // The rows move in place, and the result line accounts for every order.
    $run = ordJsFunction($src, 'olRunStatus');
    expect($run)->toContain('out.changed_ids.forEach')->toContain('olRefreshCounts();');
    expect(ordJsFunction($src, 'olResultText'))->toContain("' skipped: already '");
});

it('long-presses at 450 ms, cancels past 10 px, and routes every owner app bulk button through Proceed', function () {
    // MUTATION: change LP_MS / LP_SLOP, drop the pointercancel or scroll
    // listener, or call bulk() from the 'bulk' click directly -> red here
    // and in the Chromium touch run.
    $js = (string) file_get_contents(resource_path('js/owner-app/orders.js'));
    expect($js)->toContain('export const LP_MS = 450;')
        ->toContain('export const LP_SLOP = 10;')
        ->toContain("document.addEventListener('pointercancel', lpStop, { passive: true });")
        ->toContain("document.addEventListener('scroll', lpStop, { passive: true, capture: true });")
        ->toContain('if (Math.abs(e.clientX - LP.x) > LP_SLOP || Math.abs(e.clientY - LP.y) > LP_SLOP) lpStop();')
        ->toContain("if (act === 'bulk') { askBulk(view, b.getAttribute('data-v')); return true; }")
        ->toContain("(s) => askBulk(view, s)")
        ->toContain("data-act=\"selmode\">Select</button>")
        ->toContain('navigator.vibrate(12)');
    expect(substr_count($js, "api('POST', 'orders-bulk-status', { ids, status })"))->toBe(1);
    // The Customise-app switch still turns all of it off.
    expect($js)->toContain("if (!fn('bulk') && act === 'selmode') return true;")
        ->toContain("if (e.button > 0 || !fn('bulk')) return;");
});

/* ============================================================ Chromium */

function ordBrowserPrereqs(): array
{
    $chrome = env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
    $missing = [];
    if (! env('KBB_BROWSER_TESTS')) {
        $missing[] = 'KBB_BROWSER_TESTS is not set';
    }
    if (! is_file($chrome)) {
        $missing[] = "no Chromium at {$chrome}";
    }
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        $missing[] = 'node is not on PATH';
    }

    return ['chrome' => $chrome, 'missing' => $missing];
}

function ordBootPreview(): array
{
    $dir = storage_path('framework/testing/lane-ord-preview');
    $root = $dir.'/webroot';
    $db = $dir.'/preview.sqlite';
    exec('rm -rf '.escapeshellarg($dir));
    @mkdir($root, 0o777, true);
    @symlink(base_path(), $dir.'/kbb-upgrade-app');
    copy(base_path('public-web-root/index.php'), $root.'/index.php');
    file_put_contents($root.'/router.php', <<<'PHP'
        <?php
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($path !== '/' && is_file(__DIR__ . $path)) {
            return false;
        }
        require __DIR__ . '/index.php';
        PHP);
    touch($db);

    $app = 'ord_owner_preview';
    $env = [
        'KBB_PUBLIC_PATH' => $root, 'APP_ENV' => 'local', 'APP_DEBUG' => 'true',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db,
        'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'MAIL_MAILER' => 'array',
        'APP_KEY' => (string) config('app.key'), 'PHP_CLI_SERVER_WORKERS' => '4',
        'KBB_OWNER_APP_PATH' => $app,
    ] + CompiledCaches::environmentFor($dir.'/compiled');
    $prefix = '';
    foreach ($env as $k => $v) {
        $prefix .= $k.'='.escapeshellarg((string) $v).' ';
    }

    exec($prefix.'php '.escapeshellarg(base_path('artisan')).' migrate --force 2>&1', $out, $code);
    if ($code !== 0) {
        throw new RuntimeException("preview migrate failed:\n".implode("\n", array_slice($out, -20)));
    }
    // See AdminProductPickerBrowserTest: the relocate migration moves the
    // tracked build into this throwaway webroot. Put it back.
    if (! is_dir(base_path('public/build')) && is_dir($root.'/build')) {
        exec('cp -r '.escapeshellarg($root.'/build').' '.escapeshellarg(base_path('public/build')));
    }
    if (! is_dir($root.'/build')) {
        @symlink(base_path('public/build'), $root.'/build');
    }

    config()->set('database.connections.lane_ord_preview', ['driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true]);
    $email = 'ord-walker@example.test';
    $password = 'lane-ord-password';
    $pin = '482613';
    $admin = AdminUser::on('lane_ord_preview')->create(['name' => 'Ord Walker', 'email' => $email, 'password' => $password, 'role' => 'owner']);
    DB::connection('lane_ord_preview')->table('owner_app_members')->insert([
        'admin_user_id' => $admin->id, 'enabled' => true, 'pin_hash' => \Illuminate\Support\Facades\Hash::make($pin), 'pin_length' => 6,
        'pin_set_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    // Nothing leaves the preview: the shop's own mail switch set to "log".
    DB::connection('lane_ord_preview')->table('settings')->updateOrInsert(['key' => 'mail_transport'], ['value' => 'log', 'created_at' => now(), 'updated_at' => now()]);

    $names = ['Layla Al Mansoori', 'Omar Haddad', 'Sara Khan', 'Mariam Saeed', 'Noor Rahman', 'Aisha Malik', 'Hind Qasim', 'Rania Aziz'];
    for ($i = 1; $i <= 24; $i++) {
        $name = $names[$i % count($names)];
        $c = Customer::on('lane_ord_preview')->create(['name' => $name, 'email' => 'buyer'.$i.'@example.ae']);
        Order::on('lane_ord_preview')->create([
            'customer_id' => $c->id, 'order_number' => (string) (56160 + $i), 'email' => $c->email,
            'status' => $i === 21 ? 'completed' : 'processing', 'total' => 9900 + $i * 1250, 'subtotal' => 9900, 'currency' => 'AED',
            'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
            'billing_address' => ['first_name' => explode(' ', $name)[0], 'last_name' => 'Test', 'city' => 'Dubai'],
        ]);
    }
    DB::purge('lane_ord_preview');

    $port = PreviewPort::claim(8790, 8880);
    $process = proc_open($prefix.'php -S 127.0.0.1:'.$port.' -t '.escapeshellarg($root).' '.escapeshellarg($root.'/router.php'),
        [0 => ['pipe', 'r'], 1 => ['file', $dir.'/serve.log', 'w'], 2 => ['file', $dir.'/serve.log', 'a']], $pipes, $root);
    $base = 'http://127.0.0.1:'.$port;
    $up = false;
    for ($i = 0; $i < 60 && ! $up; $i++) {
        usleep(300_000);
        $ch = curl_init($base.'/admin/login');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        curl_exec($ch);
        $up = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
    }
    $stop = function () use ($process, $pipes, $dir) {
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        $s = proc_get_status($process);
        if ($s['running'] ?? false) {
            exec('pkill -P '.(int) $s['pid'].' 2>/dev/null');
            proc_terminate($process);
        }
        proc_close($process);
        if (! env('KBB_ORD_KEEP')) {
            exec('rm -rf '.escapeshellarg($dir));
        }
    };
    if (! $up) {
        $stop();
        throw new RuntimeException("preview server never answered on {$base}");
    }

    return ['base' => $base, 'email' => $email, 'password' => $password, 'pin' => $pin, 'app' => $app, 'stop' => $stop];
}

it('drives the admin row, Proceed and the owner app long-press in Chromium', function () {
    $pre = ordBrowserPrereqs();
    if ($pre['missing'] !== []) {
        test()->markTestSkipped('browser half skipped: '.implode('; ', $pre['missing']).'. Run with KBB_BROWSER_TESTS=1.');
    }

    $preview = ordBootPreview();
    try {
        $env = [
            'KBB_ORD_BASE' => $preview['base'], 'KBB_ORD_EMAIL' => $preview['email'], 'KBB_ORD_PASSWORD' => $preview['password'],
            'KBB_ORD_PIN' => $preview['pin'], 'KBB_ORD_APP' => $preview['app'], 'KBB_ORD_CHROME' => $pre['chrome'],
            'KBB_ORD_SHOTS' => (string) env('KBB_ORD_SHOTS', ''),
            'NODE_PATH' => (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'),
        ];
        $prefix = '';
        foreach ($env as $k => $v) {
            $prefix .= $k.'='.escapeshellarg($v).' ';
        }
        $raw = (string) shell_exec($prefix.'node '.escapeshellarg(base_path('tests/browser/ord-rows-bulk.mjs')).' 2>/dev/null');
        if (env('KBB_ORD_DUMP')) {
            file_put_contents((string) env('KBB_ORD_DUMP'), $raw);
        }
        $r = json_decode($raw, true);
        expect($r)->toBeArray('the walker printed no JSON: '.substr($raw, 0, 300));
        expect($r['ok'] ?? false)->toBeTrue('the walker failed: '.($r['error'] ?? '?'));
        expect($r['errors'])->toBe([], 'console errors: '.implode(' | ', $r['errors']));

        $a = $r['admin'];
        // the row
        expect($a['href']['href'])->toEndWith('#orders/'.$a['href']['id'])
            ->and($a['hoverCursor'])->toBe('pointer')
            ->and($a['cellClickOpens'])->toBeTrue('a click on the customer cell did not open the order')
            ->and($a['checkboxStays'])->toBeTrue('ticking the box navigated')
            ->and($a['dragSelected'])->toBeGreaterThan(0)
            ->and($a['dragStays'])->toBeTrue('a drag-select navigated')
            ->and($a['selectStays'])->toBeTrue('opening a select in the bar navigated')
            ->and($a['ctrlClick']['url'])->toMatch('/#orders\/[0-9]+$/')
            ->and($a['ctrlClick']['detail'])->toBeTrue('the new tab did not open the order')
            ->and($a['ctrlClickStays'])->toBeTrue()
            ->and($a['middleClick']['detail'])->toBeTrue()
            ->and($a['middleOnLink']['detail'])->toBeTrue()
            ->and($a['middleStays'])->toBeTrue()
            ->and($a['enterOpens'])->toBeTrue('Enter on a focused row did not open the order')
            ->and($a['linkClickOpens'])->toBeTrue()
            ->and($a['viewOpens'])->toBeTrue('View no longer opens the order')
            ->and($a['scrollWidth'])->toBe(1280);
        // Proceed
        expect($a['callsAfterSelect'])->toBe(0, 'picking a status sent the request before Proceed')
            ->and($a['proceedVisible'])->toBeTrue()
            ->and($a['proceedText'])->toContain('Set 5 orders to Completed')->toContain('customers will be emailed')
            ->and($a['selectHolds'])->toBe('completed')
            ->and($a['cancelHides'])->toBeTrue()
            ->and($a['callsAfterCancel'])->toBe(0)
            ->and($a['callsAfterProceed'])->toBe(1, 'Proceed did not send exactly one request')
            ->and(count($a['body']['ids']))->toBe(5)
            ->and($a['result'])->toContain('4 updated, 1 skipped: already Completed')
            ->and($a['loadingShown'])->toBeFalse('the list reloaded instead of updating in place')
            ->and(array_values(array_unique($a['rowsAfter'])))->toBe(['completed'])
            ->and($a['selectionCleared'])->toBeTrue()
            ->and($a['listCallsAfterProceed'])->toBe(1)
            ->and($a['resultStillThere'])->toBeTrue()
            ->and($a['scrollWidth390'])->toBe(390);

        $p = $r['app'];
        expect($p['selectBtn'])->toBeTrue()
            ->and($p['scrollSelects'])->toBe(0, 'a 30 px drag selected a row')
            ->and($p['scrollMode'])->toBeFalse()
            ->and($p['at300'])->toBe(0, 'selected before the 450 ms threshold')
            ->and($p['at600'])->toBe(1, 'a 600 ms press with a 4 px wobble did not select')
            ->and($p['modeAt600'])->toBeTrue()
            ->and($p['afterRelease'])->toBe(1, 'the release after a long-press toggled the row back off')
            ->and($p['hashAfterLongPress'])->toBe('#/orders', 'the long-press opened the order')
            ->and($p['boxes'])->toBe($p['rows'], 'selection mode did not show a tick box on every row')
            ->and($p['afterTap'])->toBe(2)
            ->and($p['hashAfterTap'])->toBe('#/orders')
            ->and($p['callsBeforeProceed'])->toBe(0, 'the status sheet applied before Proceed')
            ->and($p['confirmText'])->toContain('Set 2 orders to Shipped?')->toContain('Customers will be emailed.')
            ->and($p['callsAfterProceed'])->toBe(1)
            ->and(count($p['body']['ids']))->toBe(2)
            ->and($p['modeAfter'])->toBeFalse()
            ->and($p['toast'])->toContain('2 updated')
            ->and($p['selectButtonMode'])->toBeTrue()
            ->and($p['selectButtonCount'])->toBe(0)
            ->and($p['quickAsks'])->toBeTrue('a quick status button applied without asking')
            ->and($p['callsAfterQuick'])->toBe(1)
            ->and($p['tapOpens'])->toBeTrue('a tap outside selection mode no longer opens the order')
            ->and($p['scrollWidth'])->toBe(390);
    } finally {
        ($preview['stop'])();
    }
});
