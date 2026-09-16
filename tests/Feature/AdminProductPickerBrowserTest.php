<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * The product picker, driven the way the owner drives it.
 *
 * "On Add order page, when search for products, it appears and disappears
 * instantly, it doesn't allow me to choose anything."
 *
 * WHAT ACTUALLY HAPPENED, because a test is worth little if it does not say.
 * New Order paints itself and THEN awaits /admin-api/manual-orders/bootstrap:
 *
 *     window.go('order-new')  ->  render();  boot();
 *     boot()                  ->  await bootstrap  ->  render()
 *
 * The form is live for the whole of that request, so an operator who starts
 * typing immediately — which is what an operator does — gets suggestions, and
 * then the answer lands and render() rebuilds the screen from scratch. The
 * input, its text, its caret and the results box all go. It was never a blur
 * handler; neither screen has one.
 *
 * So the load-bearing assertion below is not "a search returns rows". It is
 * that the rows, the typed text and the caret are STILL THERE after the screen
 * has re-rendered underneath them, and that a suggestion can still be clicked.
 * The bootstrap request is delayed in the browser so that re-render happens at
 * a known moment rather than whenever the machine feels like it.
 *
 * The rest of "works" is here too, because the owner's sentence contains all of
 * it: arrow keys and Enter, Escape, a mouse click, the same on the order detail
 * screen, and a photograph beside every name with initials — never a broken
 * image icon — where a product has none.
 *
 * Its own preview on its own database, for the same reason
 * PaymentsGatewayTabsTest boots one: the suite's connection is inside a
 * transaction no external process can see.
 */

/** Where node, playwright and Chromium have to be for this to mean anything. */
function pickerPrereqs(): array
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

    if (! is_file(base_path('tests/browser/order-product-picker.mjs'))) {
        $missing[] = 'tests/browser/order-product-picker.mjs is missing';
    }

    return ['chrome' => $chrome, 'missing' => $missing];
}

/**
 * A preview of THIS checkout, with a catalogue whose pictures really resolve.
 *
 * @return array{base:string, email:string, password:string, order:int, stop:callable}
 */
function bootPickerPreview(): array
{
    $dir = storage_path('framework/testing/lane-bu-picker');
    $root = $dir.'/webroot';
    $db = $dir.'/preview.sqlite';

    @mkdir($root.'/media', 0o777, true);
    @unlink($dir.'/kbb-upgrade-app');
    @symlink(base_path(), $dir.'/kbb-upgrade-app');

    copy(base_path('public-web-root/index.php'), $root.'/index.php');

    /*
     * php -S hands EVERY request to the script it is given, so without this the
     * product photographs come back as the Laravel 404 page with Content-Type:
     * text/html, no browser draws them, and a test about images would be
     * measuring the placeholder path twice. Returning false is what makes the
     * built-in server serve a real file, which is what a real web server does
     * with /media/....
     */
    file_put_contents($root.'/router.php', <<<'PHP'
        <?php
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($path !== '/' && is_file(__DIR__ . $path)) {
            return false;
        }
        require __DIR__ . '/index.php';
        PHP);

    // A real 2x2 PNG. Hard-coded rather than drawn, so the fixture does not
    // depend on GD being compiled into whatever PHP runs the suite.
    file_put_contents($root.'/media/shot.png', (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAF0lEQVQI12P8//8/AzbAxIAH'
        .'jEqOSoIAJLcCAqYjLkoAAAAASUVORK5CYII=',
        true
    ));

    @unlink($db);
    touch($db);

    $env = [
        'KBB_PUBLIC_PATH' => $root,
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'true',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $db,
        // The suite's .env has both as `array`, and an array session does not
        // survive the redirect after a login POST.
        'SESSION_DRIVER' => 'file',
        'CACHE_STORE' => 'file',
        'APP_KEY' => (string) config('app.key'),
        'PHP_CLI_SERVER_WORKERS' => '4',
    ];

    $envPrefix = '';

    foreach ($env as $k => $v) {
        $envPrefix .= $k.'='.escapeshellarg($v).' ';
    }

    exec($envPrefix.'php '.escapeshellarg(base_path('artisan')).' migrate --force 2>&1', $out, $code);

    if ($code !== 0) {
        throw new RuntimeException("preview migrate failed:\n".implode("\n", array_slice($out, -20)));
    }

    /*
     * Put the repo's compiled assets back where they were.
     *
     * Migration 2026_08_28_183000_relocate_public_assets MOVES base_path
     * ('public/build') to the configured public path, which for the run above is
     * this preview's throwaway webroot — and $stop() below rm -rf's that whole
     * directory. Left alone, running this test deletes tracked files from the
     * working copy, which is a rotten thing for a test to do to whoever runs it.
     */
    if (! is_dir(base_path('public/build')) && is_dir($root.'/build')) {
        @mkdir(base_path('public'), 0o777, true);
        exec('cp -r '.escapeshellarg($root.'/build').' '.escapeshellarg(base_path('public/build')));
    }

    config()->set('database.connections.lane_bu_preview', [
        'driver' => 'sqlite', 'database' => $db, 'prefix' => '', 'foreign_key_constraints' => true,
    ]);

    $email = 'picker-walker@example.test';
    $password = 'lane-bu-password';

    AdminUser::on('lane_bu_preview')->create([
        'name' => 'Picker Walker', 'email' => $email, 'password' => $password, 'role' => 'owner',
    ]);

    $brand = Brand::on('lane_bu_preview')->create(['name' => 'K-Beauty Bliss', 'slug' => 'kbb']);

    /*
     * Four products the search term 'serum' finds, all with a picture, and one
     * 'balm' with none — the placeholder case. The migration set seeds demo
     * products of its own, so every name here is deliberately unlike them.
     */
    $made = [];

    foreach ([
        ['Zephyr Serum One', 11000, '/media/shot.png'],
        ['Zephyr Serum Two', 12000, '/media/shot.png'],
        ['Zephyr Serum Three', 13000, '/media/shot.png'],
        ['Zephyr Serum Four', 14000, '/media/shot.png'],
        ['Zephyr Balm Nought', 9000, null],
    ] as $i => [$name, $price, $image]) {
        $made[] = Product::on('lane_bu_preview')->create([
            'slug' => 'zephyr-'.$i,
            'name' => $name,
            'sku' => 'ZEP-'.$i,
            'brand_id' => $brand->id,
            'type' => 'simple',
            'status' => 'publish',
            'is_visible' => true,
            'price' => $price,
            'image' => $image,
            'stock' => 50,
            'stock_status' => 'instock',
        ]);
    }

    $customer = Customer::on('lane_bu_preview')->create([
        'name' => 'Layla Al Mansoori', 'email' => 'layla@example.ae', 'phone' => '+971500000000',
    ]);

    // An order in an editable status, with one line whose product has a picture
    // and one whose product does not: the empty grey square the owner saw.
    $order = Order::on('lane_bu_preview')->create([
        'order_number' => 'KBB-PICKER-B1',
        'customer_id' => $customer->id,
        'status' => 'processing',
        'currency' => 'AED',
        'email' => $customer->email,
        'phone' => $customer->phone,
        'subtotal' => 20000, 'total' => 22000, 'shipping_total' => 2000,
        'billing_address' => ['name' => 'Layla Al Mansoori', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'emirate' => 'Dubai'],
        'shipping_address' => ['name' => 'Layla Al Mansoori', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'emirate' => 'Dubai'],
    ]);

    foreach ([$made[0], $made[4]] as $product) {
        OrderItem::on('lane_bu_preview')->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => $product->name,
            'brand' => 'K-Beauty Bliss',
            'sku' => $product->sku,
            'quantity' => 1,
            'unit_price' => 10000, 'subtotal' => 10000, 'total' => 10000,
        ]);
    }

    DB::purge('lane_bu_preview');

    $port = 8760 + random_int(30, 120);
    $command = $envPrefix.'php -S 127.0.0.1:'.$port.' -t '.escapeshellarg($root).' '.escapeshellarg($root.'/router.php');

    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $dir.'/serve.log', 'w'], 2 => ['file', $dir.'/serve.log', 'a']],
        $pipes,
        $root
    );

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the preview server');
    }

    $base = 'http://127.0.0.1:'.$port;
    $up = false;

    for ($i = 0; $i < 60; $i++) {
        usleep(300_000);
        $ch = curl_init($base.'/admin/login');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 200) {
            $up = true;
            break;
        }
    }

    $stop = function () use ($process, $pipes, $dir) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $status = proc_get_status($process);

        if ($status['running'] ?? false) {
            exec('pkill -P '.(int) $status['pid'].' 2>/dev/null');
            proc_terminate($process);
        }

        proc_close($process);
        exec('rm -rf '.escapeshellarg($dir));
    };

    if (! $up) {
        $log = @file_get_contents($dir.'/serve.log') ?: '';
        $stop();

        throw new RuntimeException("preview server never answered on {$base}\n".substr($log, -800));
    }

    return ['base' => $base, 'email' => $email, 'password' => $password, 'order' => (int) $order->id, 'stop' => $stop];
}

/** @return array<string,mixed> */
function drivePicker(array $preview, string $chrome, array $opts = []): array
{
    $env = [
        'KBB_PP_BASE' => $preview['base'],
        'KBB_PP_EMAIL' => $preview['email'],
        'KBB_PP_PASSWORD' => $preview['password'],
        'KBB_PP_CHROME' => $chrome,
        'KBB_PP_ORDER' => (string) $preview['order'],
        'KBB_PP_TERM' => $opts['term'] ?? 'zephyr serum',
        'KBB_PP_TERM_NOIMG' => $opts['termNoImage'] ?? 'zephyr balm',
        'KBB_PP_WIDTH' => (string) ($opts['width'] ?? 1280),
        'NODE_PATH' => (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'),
    ];

    $prefix = '';

    foreach ($env as $k => $v) {
        $prefix .= $k.'='.escapeshellarg((string) $v).' ';
    }

    $command = $prefix.'node '.escapeshellarg(base_path('tests/browser/order-product-picker.mjs')).' 2>/dev/null';

    // One retry, for the same reason admin-overflow.mjs has one: driving a
    // browser against a local single-process server is not perfectly
    // deterministic, and this must fail for the reason it is about.
    $decoded = null;

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $decoded = json_decode((string) shell_exec($command), true);

        if (is_array($decoded) && ($decoded['ok'] ?? false)) {
            return $decoded;
        }
    }

    if (! is_array($decoded)) {
        throw new RuntimeException('the product picker walker returned no JSON');
    }

    return $decoded;
}

it('keeps the suggestion list alive through the re-render that used to eat it, in a real browser', function () {
    $prereqs = pickerPrereqs();

    if ($prereqs['missing'] !== []) {
        test()->markTestSkipped(
            'browser half skipped: '.implode('; ', $prereqs['missing'])
            .'. Run with KBB_BROWSER_TESTS=1 and a playwright Chromium present.'
        );
    }

    $preview = bootPickerPreview();

    try {
        $r = drivePicker($preview, $prereqs['chrome']);

        expect($r['ok'] ?? false)->toBeTrue('the walker failed: '.($r['error'] ?? 'no reason given'));

        /* ------------------------------------------------ the reported bug */

        $before = $r['beforeLateRender'];
        $after = $r['afterLateRender'];

        expect($before['hits'])->toBeGreaterThan(
            0,
            'no suggestions appeared at all while the bootstrap request was still in flight'
        );

        $survived = $after['hits'] === $before['hits'];
        expect($survived)->toBeTrue(
            "the suggestion list was wiped by the screen re-rendering underneath it: {$before['hits']} suggestions "
            ."while the operator was typing, {$after['hits']} once /manual-orders/bootstrap answered and "
            .'render() rebuilt #moScreen. That is the owner\'s "it appears and disappears instantly".'
        );

        $keptText = $after['value'] === $before['value'];
        expect($keptText)->toBeTrue(
            'the typed search term was thrown away by the same re-render: '
            .json_encode($before['value']).' became '.json_encode($after['value'])
        );

        $keptCaret = $after['focused'] === 'moProdSearch';
        expect($keptCaret)->toBeTrue(
            'the caret was dropped out of the search box by the re-render; it ended up on '
            .json_encode($after['focused']).', so the next character the operator typed went nowhere'
        );

        $click = $r['clickAfterLateRender'];
        expect($click['attempted'])->toBeTrue('there was nothing left to click after the re-render');
        expect($click['ok'])->toBeTrue('the suggestion could not be clicked: '.($click['error'] ?? ''));
        expect($click['lines'])->toBe(1, 'clicking a suggestion did not put a line on the order');

        /* ------------------------------------------- images and placeholder */

        $rows = $r['newOrderRows'];
        expect($rows['count'])->toBeGreaterThan(0, 'the New Order picker returned no rows');

        $allDrawn = $rows['loaded'] === $rows['count'];
        expect($allDrawn)->toBeTrue(
            "New Order suggestions are missing their product photograph: {$rows['loaded']} of {$rows['count']} "
            .'rows drew an image the browser could decode'
        );

        expect($rows['listRole'])->toBe('listbox')
            ->and($rows['optionRole'])->toBe('option');

        $none = $r['newOrderNoImageRows'];
        expect($none['count'])->toBeGreaterThan(0, 'the no-image search term matched nothing');
        expect($none['broken'])->toBe(0, 'a product with no image drew a broken-image icon');

        $hasInitials = $none['initials'] === $none['count'];
        expect($hasInitials)->toBeTrue(
            "a product with no image should show initials: {$none['initials']} of {$none['count']} rows did"
        );

        /* -------------------------------------------------------- keyboard */

        $kb = $r['keyboard'];
        expect($kb['highlighted'])->not->toBeNull('ArrowDown highlighted nothing');

        $added = $kb['linesAfter'] === $kb['linesBefore'] + 1;
        expect($added)->toBeTrue(
            "Enter did not add the highlighted product: {$kb['linesBefore']} lines before, {$kb['linesAfter']} after"
        );

        $tookTheRightOne = in_array($kb['highlighted'], $kb['names'], true);
        expect($tookTheRightOne)->toBeTrue(
            'Enter added something other than the highlighted row: highlighted '
            .json_encode($kb['highlighted']).', lines are '.json_encode($kb['names'])
        );

        expect($kb['lineThumbs'])->toBe($kb['linesAfter'], 'a line on the order is missing its thumbnail');

        $esc = $r['escape'];
        expect($esc['before'])->toBeGreaterThan(0)
            ->and($esc['after'])->toBe(0, 'Escape did not close the suggestion list');

        /* --------------------------------------------------- order detail */

        $items = $r['orderItems'];
        expect($items['thumbs'])->toBe($items['rows'], 'a line item on the order detail screen has no thumbnail cell');
        expect($items['loaded'])->toBeGreaterThan(0, 'no line item drew its product photograph');
        expect($items['initials'])->toBeGreaterThan(0, 'the line item whose product has no image drew no initials');
        expect($items['empty'])->toBe(0, 'a line item still draws the empty square the owner reported');

        $det = $r['orderDetailRows'];
        expect($det['count'])->toBeGreaterThan(0, 'the order detail picker returned no rows');

        $detDrawn = $det['loaded'] === $det['count'];
        expect($detDrawn)->toBeTrue(
            "order detail suggestions are missing their photograph: {$det['loaded']} of {$det['count']} drew one"
        );

        $detKb = $r['orderDetailKeyboard'];
        $detAdded = $detKb['itemsAfter'] === $detKb['itemsBefore'] + 1;
        expect($detAdded)->toBeTrue(
            "Enter did not add a product to the open order: {$detKb['itemsBefore']} rows before, {$detKb['itemsAfter']} after"
        );

        $detTookRight = in_array($detKb['highlighted'], $detKb['names'], true);
        expect($detTookRight)->toBeTrue(
            'the order detail picker added something other than the highlighted row: highlighted '
            .json_encode($detKb['highlighted'])
        );

        $detClick = $r['orderDetailClick'];
        expect($detClick['attempted'])->toBeTrue('the order detail picker had nothing to click');
        expect($detClick['ok'])->toBeTrue('the order detail suggestion could not be clicked: '.($detClick['error'] ?? ''));

        $detClickAdded = $detClick['itemsAfter'] === $detClick['itemsBefore'] + 1;
        expect($detClickAdded)->toBeTrue(
            "clicking a suggestion did not add a line: {$detClick['itemsBefore']} rows before, {$detClick['itemsAfter']} after"
        );

        expect($r['pageErrors'])->toBe([], 'JavaScript threw while the picker was being driven');

        // The paths this screen owns. The admin asks for a favicon it does not
        // ship, which 404s on every page of the console and is not this test's
        // business; an endpoint or a product photograph failing very much is.
        $mine = array_values(array_filter(
            $r['httpErrors'] ?? [],
            fn (string $line) => str_contains($line, '/admin-api/') || str_contains($line, '/media/')
        ));

        expect($mine)->toBe([], 'a request the picker depends on failed: '.implode(', ', $mine));
    } finally {
        $preview['stop']();
    }
});
