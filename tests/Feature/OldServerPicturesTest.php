<?php

declare(strict_types=1);

/*
 * "Fetch missing pictures from the old server" (Lane PX).
 *
 * THE DEFECT, ON THE SHOP. kbeautybliss.com moved to this server; pictures
 * like /wp-content/uploads/2024/11/medicube-Deep-Vita-C-Capsule-Cream-3.webp
 * were never copied, so the product shows a blank frame. MediaSideloader asks
 * kbeautybliss.com by DNS -- which is now this server -- and gets its own 404.
 * OldServerPictures asks the OLD address with the real name pinned to it.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Setting;
use App\Services\DomainMove\DomainSwitch;
use App\Services\Import\OldServerPictures;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\MediaSideloadAdminRoutes;

const OSP_IP = '177.202.242.149';
const OSP_PATH = 'wp-content/uploads/2024/11/medicube-Deep-Vita-C-Capsule-Cream-3.jpg';

function ospJpeg(int $w = 500): string
{
    $im = imagecreatetruecolor($w, $w);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 120, 90));
    ob_start();
    imagejpeg($im, null, 80);
    imagedestroy($im);

    return (string) ob_get_clean();
}

function ospProduct(string $image, string $slug = 'px-cream'): Product
{
    return Product::query()->create(['name' => 'PX '.$slug, 'slug' => $slug, 'price' => 1000, 'status' => 'published', 'image' => $image]);
}

/**
 * A fake transport: records every curl option array it is handed and answers
 * from $answer (a closure of the options, or a fixed response).
 *
 * @param  list<array<int, mixed>>  $calls
 */
function ospPictures(array &$calls, ?\Closure $answer = null): OldServerPictures
{
    $answer ??= fn (): array => ['status' => 200, 'headers' => ['content-type' => 'image/jpeg'], 'body' => ospJpeg(), 'errno' => 0, 'error' => ''];

    return new OldServerPictures(transport: function (array $options) use (&$calls, $answer): array {
        $calls[] = $options;

        return $answer($options);
    });
}

function ospClean(): void
{
    foreach (['wp-content', 'img-cache', 'uploads'] as $dir) {
        if (is_dir(public_path($dir))) {
            File::deleteDirectory(public_path($dir));
        }
    }
}

function ospOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create(['name' => 'PX '.$role, 'email' => 'px-'.uniqid().'@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

beforeEach(function (): void {
    ospClean();
    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'https://kbeautybliss.com']);
    config(['app.url' => 'https://kbeautybliss.com']);
});

afterEach(fn () => ospClean());

/* ------------------------------------------------------------- the pin */

it('asks the OLD address with kbeautybliss.com pinned to it, TLS verified', function () {
    // Mutation: drop CURLOPT_RESOLVE (or point the URL at the IP) and this is
    // red -- that request goes to this server by DNS and 404s, the bug itself.
    ospProduct('https://kbeautybliss.com/'.OSP_PATH);
    $calls = [];

    $r = ospPictures($calls)->batch(['ip' => OSP_IP, 'pause_ms' => 0]);

    expect($r['fetched'])->toBe(1)->and($calls)->toHaveCount(1);
    $o = $calls[0];
    expect(parse_url($o[CURLOPT_URL], PHP_URL_HOST))->toBe('kbeautybliss.com')   // Host header and SNI
        ->and(parse_url($o[CURLOPT_URL], PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url($o[CURLOPT_URL], PHP_URL_PATH))->toBe('/'.OSP_PATH)
        ->and($o[CURLOPT_RESOLVE])->toContain('kbeautybliss.com:443:'.OSP_IP)
        ->and($o[CURLOPT_RESOLVE])->toContain('www.kbeautybliss.com:443:'.OSP_IP)
        ->and($o[CURLOPT_SSL_VERIFYPEER])->toBeTrue()
        ->and($o[CURLOPT_SSL_VERIFYHOST])->toBe(2)
        ->and($o[CURLOPT_FOLLOWLOCATION])->toBeFalse()
        ->and($o[CURLOPT_PROXY])->toBe('');   // no proxy between us and the pinned address
});

it('pins an IPv6 address in brackets and plain HTTP to port 80 only when asked', function () {
    ospProduct('/'.OSP_PATH);
    $calls = [];

    ospPictures($calls)->batch(['ip' => '2a02:4780:1::1', 'http' => true, 'pause_ms' => 0]);

    expect($calls[0][CURLOPT_URL])->toStartWith('http://kbeautybliss.com/')
        ->and($calls[0][CURLOPT_RESOLVE])->toContain('kbeautybliss.com:80:[2a02:4780:1::1]')
        ->and($calls[0][CURLOPT_SSL_VERIFYPEER])->toBeTrue();
});

it('stops on a certificate failure and never falls back to HTTP by itself', function () {
    ospProduct('/'.OSP_PATH, 'a');
    ospProduct('/wp-content/uploads/2024/11/b.jpg', 'b');
    $calls = [];

    $r = ospPictures($calls, fn (): array => ['status' => 0, 'headers' => [], 'body' => '', 'errno' => 60, 'error' => 'SSL certificate problem'])
        ->batch(['ip' => OSP_IP, 'pause_ms' => 0]);

    // Mutation: drop the TLS break and the second picture is asked for too.
    expect($r['tls_failed'])->toBeTrue()->and($r['more'])->toBeFalse()->and($calls)->toHaveCount(1)
        ->and($calls[0][CURLOPT_URL])->toStartWith('https://')
        ->and($r['message'])->toContain('plain HTTP');
});

/* ------------------------------------------------------- landing a file */

it('lands a missing picture at the same path and makes its img-cache copies', function () {
    ospProduct('https://www.kbeautybliss.com/'.OSP_PATH);
    $calls = [];

    ospPictures($calls)->batch(['ip' => OSP_IP, 'pause_ms' => 0]);

    expect(is_file(public_path(OSP_PATH)))->toBeTrue()
        ->and(getimagesize(public_path(OSP_PATH))[0])->toBe(500)
        // Mutation: drop ImageVariants::generate() and these are red.
        ->and(is_file(public_path('img-cache/200/'.OSP_PATH)))->toBeTrue()
        ->and(is_file(public_path('img-cache/400/'.OSP_PATH)))->toBeTrue()
        ->and(DB::table('media')->where('path', OSP_PATH)->exists())->toBeTrue()
        ->and(DB::table(OldServerPictures::TABLE)->value('state'))->toBe('fetched');
});

it('never overwrites a file that is already there', function () {
    ospProduct('/'.OSP_PATH);
    $calls = [];
    $p = ospPictures($calls);
    $p->scan();                                   // missing at scan time ...
    File::ensureDirectoryExists(dirname(public_path(OSP_PATH)));
    file_put_contents(public_path(OSP_PATH), 'ORIGINAL');   // ... copied by FTP since

    $p->batch(['ip' => OSP_IP, 'pause_ms' => 0, 'continue' => true]);

    // Mutation: remove the file_exists() check in fetchOne() and the transport is
    // called and link()/rename() decide; drop link() for rename() and it is replaced.
    expect(file_get_contents(public_path(OSP_PATH)))->toBe('ORIGINAL')->and($calls)->toBe([])
        ->and(DB::table(OldServerPictures::TABLE)->count())->toBe(0);

    // And at the moment of writing: the file appears between the request and the rename.
    $calls = [];
    $race = ospPictures($calls, function () {
        file_put_contents(public_path('wp-content/uploads/2024/11/race.jpg'), 'ORIGINAL');

        return ['status' => 200, 'headers' => ['content-type' => 'image/jpeg'], 'body' => ospJpeg(), 'errno' => 0, 'error' => ''];
    });
    expect($race->fetchOne('wp-content/uploads/2024/11/race.jpg', OSP_IP)['state'])->toBe('present')
        ->and(file_get_contents(public_path('wp-content/uploads/2024/11/race.jpg')))->toBe('ORIGINAL');
});

/* --------------------------------------------------------------- guards */

it('refuses a private, local or reserved address, and this server', function (string $ip) {
    ospProduct('/'.OSP_PATH);
    $calls = [];

    $r = ospPictures($calls)->batch(['ip' => $ip, 'pause_ms' => 0]);

    // Mutation: drop isPublicAddress() from ipRefusal() and the transport is called.
    expect($r['ok'])->toBeFalse()->and($calls)->toBe([])->and(is_file(public_path(OSP_PATH)))->toBeFalse();
})->with(['127.0.0.1', '10.0.0.5', '192.168.1.10', '172.16.4.4', '169.254.169.254', '100.64.0.1', '::1', '::ffff:127.0.0.1', '0.0.0.0', 'not-an-ip', 'kbeautybliss.com']);

it('refuses this server\'s own address, which would only fetch its own 404', function () {
    $_SERVER['SERVER_ADDR'] = '134.209.147.13';

    try {
        expect((new OldServerPictures)->ipRefusal('134.209.147.13'))->toContain('THIS server')
            ->and((new OldServerPictures)->ipRefusal(DomainSwitch::PREVIOUS_IP))->toBeNull();
    } finally {
        unset($_SERVER['SERVER_ADDR']);
    }
});

it('refuses a path outside wp-content/uploads and never asks for it', function (string $stored) {
    ospProduct($stored);
    $calls = [];

    ospPictures($calls)->batch(['ip' => OSP_IP, 'pause_ms' => 0]);

    // Mutation: drop the targetPath() refusal in scan() and these are fetched.
    expect($calls)->toBe([])->and(File::exists(public_path('etc')))->toBeFalse();
})->with([
    'traversal' => ['https://kbeautybliss.com/wp-content/uploads/../../../etc/x.jpg'],
    'escaped traversal' => ['https://kbeautybliss.com/wp-content/uploads/%2e%2e/%2e%2e/etc/x.jpg'],
    'php in the name' => ['https://kbeautybliss.com/wp-content/uploads/2024/11/shell.php.jpg'],
    'svg' => ['https://kbeautybliss.com/wp-content/uploads/2024/11/logo.svg'],
    'another site' => ['https://cdn.example.org/wp-content/uploads/2024/11/x.jpg'],
]);

it('fetchOne itself refuses anything not under wp-content/uploads', function () {
    $calls = [];
    $p = ospPictures($calls);

    expect($p->fetchOne('uploads/2024/x.jpg', OSP_IP)['state'])->toBe('refused')
        ->and($p->fetchOne('wp-content/uploads/../../.env', OSP_IP)['state'])->toBe('refused')
        ->and($p->fetchOne('wp-content/plugins/x.jpg', OSP_IP)['state'])->toBe('refused')
        ->and($calls)->toBe([]);
});

it('will not follow a redirect off the shop\'s own name', function () {
    ospProduct('/'.OSP_PATH);
    $calls = [];

    ospPictures($calls, fn (): array => ['status' => 301, 'headers' => ['location' => 'http://169.254.169.254/wp-content/uploads/x.jpg'], 'body' => '', 'errno' => 0, 'error' => ''])
        ->batch(['ip' => OSP_IP, 'pause_ms' => 0]);

    expect($calls)->toHaveCount(1)->and(DB::table(OldServerPictures::TABLE)->value('state'))->toBe('refused');
});

it('refuses what is not a picture and writes nothing', function (string $type, string $body) {
    ospProduct('/'.OSP_PATH);
    $calls = [];

    ospPictures($calls, fn (): array => ['status' => 200, 'headers' => ['content-type' => $type], 'body' => $body, 'errno' => 0, 'error' => ''])
        ->batch(['ip' => OSP_IP, 'pause_ms' => 0]);

    // Mutation: drop the sniff() comparison in land() and these land in the web root.
    expect(is_file(public_path(OSP_PATH)))->toBeFalse()
        ->and(DB::table(OldServerPictures::TABLE)->value('state'))->toBe('refused')
        ->and(glob(public_path('wp-content/uploads/2024/11/.kbb-oldpic-*')) ?: [])->toBe([]);
})->with([
    'html page' => ['text/html', '<!doctype html><html>Not found</html>'],
    'php dressed as jpeg' => ['image/jpeg', '<?php system($_GET["c"]); ?>'.str_repeat('x', 40)],
    'polyglot' => ['image/jpeg', "\xFF\xD8\xFF\xE0<?php echo 1; ?>".str_repeat('x', 40)],
    'svg' => ['image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'png bytes as jpeg' => ['image/jpeg', "\x89PNG\r\n\x1A\n".str_repeat('x', 40)],
]);

it('records a 404 on the old server as lost, with the product that needs a new picture', function () {
    ospProduct('/'.OSP_PATH, 'deep-vita-c');
    $calls = [];
    $p = ospPictures($calls, fn (): array => ['status' => 404, 'headers' => ['content-type' => 'text/html'], 'body' => 'nope', 'errno' => 0, 'error' => '']);

    $r = $p->batch(['ip' => OSP_IP, 'pause_ms' => 0]);
    $list = $p->missingList();

    expect($r['summary']['gone'])->toBe(1)->and($r['summary']['missing'])->toBe(1)
        ->and($list[0]['reason'])->toContain('give the product a new one')
        ->and(implode(' ', $list[0]['owners']))->toContain('deep-vita-c');
});

/* ------------------------------------------------------- steps & resume */

it('resumes after Stop onto exactly the pictures not yet fetched', function () {
    foreach (['a', 'b', 'c'] as $n) {
        ospProduct('/wp-content/uploads/2024/11/'.$n.'.jpg', 'px-'.$n);
    }
    $calls = [];
    $p = ospPictures($calls);

    $first = $p->batch(['ip' => OSP_IP, 'files' => 1, 'pause_ms' => 0]);
    expect($first['fetched'])->toBe(1)->and($first['more'])->toBeTrue()->and($first['summary']['remaining'])->toBe(2);

    $p->stop();
    $stopped = $p->batch(['ip' => OSP_IP, 'files' => 1, 'pause_ms' => 0, 'continue' => true]);

    // Mutation: drop the stopped check in batch() and this step fetches.
    expect($stopped['stopped'])->toBeTrue()->and($calls)->toHaveCount(1);

    $resumed = $p->batch(['ip' => OSP_IP, 'files' => 10, 'pause_ms' => 0]);

    $asked = array_map(fn ($o) => basename(parse_url($o[CURLOPT_URL], PHP_URL_PATH)), $calls);
    expect($resumed['fetched'])->toBe(2)->and($resumed['more'])->toBeFalse()
        ->and($asked)->toBe(['a.jpg', 'b.jpg', 'c.jpg'])    // none asked twice
        ->and($resumed['summary']['missing'])->toBe(0);
});

/* ---------------------------------------------------------- check mode */

it('Check counts every referenced missing picture by host and who names it, with no network', function () {
    $set = DB::table('banner_sets')->insertGetId(['name' => 'Hero', 'created_at' => now(), 'updated_at' => now()] + (Schema::hasColumn('banner_sets', 'slug') ? ['slug' => 'hero'] : []));
    DB::table('banner_cards')->insert(['banner_set_id' => $set, 'image' => 'https://extrabeauty.ae/wp-content/uploads/2025/01/hero.webp', 'created_at' => now(), 'updated_at' => now()]);
    ospProduct('https://kbeautybliss.com/'.OSP_PATH, 'cream');
    $gallery = ospProduct('https://kbeautybliss.com/wp-content/uploads/2024/11/present.jpg', 'gallery');
    $gallery->forceFill(['images' => ['https://www.kbeautybliss.com/wp-content/uploads/2024/11/g2.jpg']])->save();
    ospProduct('https://kbeautybliss.com/wp-content/uploads/2024/11/x.jpg', 'x')
        ->forceFill(['description' => '<p><img src="https://kbeautybliss.com/wp-content/uploads/2024/10/desc.png"></p>'])->save();
    // A JSON setting stores its slashes escaped: the sweep has to read through that.
    DB::table('settings')->insert(['key' => 'px_hero_slides', 'value' => json_encode([['image' => 'https://kbeautybliss.com/wp-content/uploads/2025/02/slide.jpg']])]);
    File::ensureDirectoryExists(public_path('wp-content/uploads/2024/11'));
    file_put_contents(public_path('wp-content/uploads/2024/11/present.jpg'), ospJpeg());

    $p = new OldServerPictures(transport: fn () => throw new RuntimeException('Check must not touch the network'));
    $s = $p->scan();

    // Mutation: drop sweep() and the banner and the slide are not counted (4, not 6 missing).
    expect($s['referenced'])->toBe(7)->and($s['present'])->toBe(1)->and($s['missing'])->toBe(6)
        ->and($s['by_host'])->toMatchArray(['kbeautybliss.com' => 4, 'www.kbeautybliss.com' => 1, 'extrabeauty.ae' => 1])
        ->and($s['warning'])->toBe('Do not cancel Hostinger until this shows 0 missing.');

    $owners = collect($p->missingList())->mapWithKeys(fn ($r) => [$r['path'] => implode(' ', $r['owners'])]);
    expect($owners['/wp-content/uploads/2025/01/hero.webp'])->toContain('banner')
        ->and($owners['/wp-content/uploads/2024/11/g2.jpg'])->toContain('products.images')
        ->and($owners['/wp-content/uploads/2024/10/desc.png'])->toContain('products.description')
        ->and($owners['/wp-content/uploads/2025/02/slide.jpg'])->toContain('setting');
});

it('counts a picture it would never write (an .svg) as present when it is on disk', function () {
    // Mutation: skip the on-disk check for refused names and this logo reads "missing" for ever.
    ospProduct('https://kbeautybliss.com/wp-content/uploads/2024/01/logo.svg');
    File::ensureDirectoryExists(public_path('wp-content/uploads/2024/01'));
    file_put_contents(public_path('wp-content/uploads/2024/01/logo.svg'), '<svg xmlns="http://www.w3.org/2000/svg"/>');

    expect((new OldServerPictures)->scan())->toMatchArray(['present' => 1, 'missing' => 0]);
});

it('Check through the command makes no network call either', function () {
    ospProduct('/'.OSP_PATH);
    app()->instance(OldServerPictures::class, new OldServerPictures(transport: fn () => throw new RuntimeException('no network in check')));

    $this->artisan('kbb:fetch-missing-pictures', ['--check' => true])
        ->expectsOutputToContain('MISSING: 1')
        ->expectsOutputToContain('Do not cancel Hostinger until this shows 0 missing.')
        ->assertSuccessful();
});

/* ------------------------------------------------------------ the admin */

it('is behind data.import and refuses everyone else', function () {
    MediaSideloadAdminRoutes::wire($this->app);

    foreach ([['GET', 'admin-api/urls-media/old-server'], ['POST', 'admin-api/urls-media/old-server'], ['GET', 'admin-api/urls-media/old-server.csv']] as [$m, $uri]) {
        expect(AdminCapabilities::forPath($m, $uri))->toBe('data.import');
    }

    expect(AdminCapabilities::roleCan('editor', 'data.import'))->toBeFalse();

    $this->postJson('/admin-api/urls-media/old-server', ['action' => 'check'])->assertUnauthorized();
    $this->actingAs(ospOwner('editor'), 'admin')->postJson('/admin-api/urls-media/old-server', ['action' => 'check'])->assertForbidden();
    $this->actingAs(ospOwner('editor'), 'admin')->getJson('/admin-api/urls-media/old-server')->assertForbidden();

    ospProduct('/'.OSP_PATH);
    $this->actingAs(ospOwner(), 'admin')->postJson('/admin-api/urls-media/old-server', ['action' => 'check'])
        ->assertOk()->assertJsonPath('missing', 1)->assertJsonPath('from_ip', DomainSwitch::PREVIOUS_IP);
    $this->actingAs(ospOwner(), 'admin')->postJson('/admin-api/urls-media/old-server', ['action' => 'fetch', 'ip' => '10.1.1.1'])
        ->assertStatus(422);
    $this->actingAs(ospOwner(), 'admin')->postJson('/admin-api/urls-media/old-server', ['action' => 'nope'])
        ->assertStatus(422);
});

it('is mounted on both screens exactly once, and the partial is included exactly once', function () {
    $app = file_get_contents(resource_path('views/admin/app.blade.php'));
    $dw = file_get_contents(resource_path('views/admin/partials/domain-switch-screen.blade.php'));

    expect(substr_count($app, "@include('admin.partials.old-pictures-panel')"))->toBe(1)
        ->and(substr_count($app, 'data-oldpics'))->toBe(1)
        ->and(substr_count($dw, 'data-oldpics'))->toBe(1)
        ->and(substr_count($dw, 'kbbOldPictures.mountAll'))->toBe(1)
        ->and(substr_count($app, 'kbbOldPictures.mountAll'))->toBe(1);
});
