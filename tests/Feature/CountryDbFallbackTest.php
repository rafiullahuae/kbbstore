<?php

declare(strict_types=1);

use App\Services\Security\CountryDb;
use Illuminate\Support\Facades\Http;

/*
 * Store → Security → Firewall → Data → "Download country database", 19 October
 * 2026. The owner: "whenever i download the countries list, it's not
 * downloading, and always i can see this warning" ("Not installed ... country
 * rules are not applied"). download() knew one address, download.db-ip.com,
 * and when that refused the server there was no country database at all -- so
 * every country rule (Protect / Block for China, Russia, Singapore, Hong Kong)
 * silently did nothing. Now: DB-IP, then the same data on jsDelivr, then the
 * copy shipped with the code.
 */

function cdbfDir(): string
{
    $dir = storage_path('framework/testing/cdbf-'.getmypid());
    @mkdir($dir, 0775, true);
    CountryDb::usePath($dir.'/country.bin');

    return $dir;
}

/** A valid DB-IP style CSV over 1 MB: consecutive /24s, every one in AE. */
function cdbfCsv(int $family): string
{
    $out = '';
    if ($family === 4) {
        for ($i = 0; $i < 60000; $i++) {
            $a = 10 + intdiv($i, 256 * 256); $b = intdiv($i, 256) % 256; $c = $i % 256;
            $out .= "$a.$b.$c.0,$a.$b.$c.255,AE\n";
        }
    } else {
        for ($i = 0; $i < 30000; $i++) {
            $h = dechex($i + 4096);
            $out .= "2a00:$h::,2a00:$h:ffff:ffff:ffff:ffff:ffff:ffff,AE\n";
        }
    }

    return $out;
}

afterEach(function () {
    $dir = storage_path('framework/testing/cdbf-'.getmypid());
    CountryDb::forget();
    foreach (glob($dir.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
});

it('installs from the jsDelivr mirror when download.db-ip.com refuses the server', function () {
    /* MUTATION: drop the mirror block in download() -> ok is false, red. */
    cdbfDir();
    Http::fake([
        'download.db-ip.com/*' => Http::response('Forbidden', 403),
        'cdn.jsdelivr.net/*ipv4.csv' => Http::response(cdbfCsv(4), 200),
        'cdn.jsdelivr.net/*ipv6.csv' => Http::response(cdbfCsv(6), 200),
    ]);

    $r = CountryDb::download();

    expect($r['ok'])->toBeTrue()
        ->and($r['source'])->toContain('cdn.jsdelivr.net')
        ->and(CountryDb::lookup(inet_pton('10.0.5.9')))->toBe('AE')
        ->and(CountryDb::verify()['ok'])->toBeTrue();
});

it('installs the copy shipped with the code when no download works', function () {
    /* MUTATION: drop the bundled block -> ok is false and the screen keeps
     * saying "Not installed", red. */
    cdbfDir();
    Http::fake(['*' => Http::response('Forbidden', 403)]);

    $r = CountryDb::download();

    expect($r['ok'])->toBeTrue()
        ->and($r['source'])->toContain('bundled copy')
        ->and($r['countries'])->toBeGreaterThan(200)
        ->and(CountryDb::lookup(inet_pton('94.200.10.1')))->toBe('AE')
        ->and(CountryDb::lookup(inet_pton('202.166.1.1')))->toBe('SG')
        ->and(CountryDb::lookup(inet_pton('8.8.8.8')))->toBe('US');
});

it('never replaces a good installed database with the older shipped copy', function () {
    /* MUTATION: drop the `! self::verify()['ok']` guard -> the installed file is
     * overwritten by the bundled one, red. */
    $dir = cdbfDir();
    file_put_contents($dir.'/in.csv', "10.0.0.0,10.255.255.255,FR\n2a00::,2a00:ffff:ffff:ffff:ffff:ffff:ffff:ffff,FR\n");
    CountryDb::build([$dir.'/in.csv'], CountryDb::path(), 20261001);
    $before = hash_file('sha256', CountryDb::path());
    Http::fake(['*' => Http::response('Forbidden', 403)]);

    $r = CountryDb::download();

    expect($r['ok'])->toBeFalse()
        ->and(hash_file('sha256', CountryDb::path()))->toBe($before);
});

it('ships a valid built copy', function () {
    $v = CountryDb::verify(resource_path(CountryDb::BUNDLED));

    expect($v['ok'])->toBeTrue()->and($v['v4'])->toBeGreaterThan(300000)->and($v['countries'])->toBeGreaterThan(200)
        ->and(filesize(resource_path(CountryDb::BUNDLED)))->toBeLessThan(8 * 1024 * 1024);
});
