<?php

declare(strict_types=1);

/**
 * THE HOMEPAGE SPOTTED SECTION OBEYS ITS ROW ON APPEARANCE → HOMEPAGE.
 *                                                                  (Lane HS)
 *
 * THE DEFECT, found by Lane HC: every other homepage section reads its row
 * through $sections->hidden('key') and $sections->classFor('key') in
 * store/home.blade.php. partials/home/spotted read neither, so on the shop:
 *   · switching the Spotted row's Phone (or Laptop) off changed nothing —
 *     the section still drew on that device;
 *   · switching it off on BOTH still drew it;
 *   · dragging it to another position left it where it was, because the
 *     `kbb-ord-N` class the order stylesheet targets was never on it.
 *
 * Asked of BOTH layouts — the static grid (shipped) and the carousel.
 *
 * MUTATIONS, each run and each red here:
 *   · `{{ $sptRow }}` removed from either <section>   → "phone only" / "moves"
 *   · `! $sptOff &&` removed from $sptGrid / $sptCards → "off on both"
 */

use App\Models\SpottedPost;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Services\SpottedSettings;

function shrSaveRow(string $key, array $change, ?array $first = null): void
{
    $svc = app(HomepageSections::class);
    $all = $svc->all();
    $keys = array_keys($all);

    if ($first !== null) {
        $keys = array_merge($first, array_values(array_diff($keys, $first)));
    } else {
        uasort($all, fn ($a, $b) => $a['order'] <=> $b['order']);
        $keys = array_keys($all);
    }

    $payload = [];
    foreach ($keys as $i => $k) {
        $payload[$k] = ($k === $key ? $change : [])
            + ['desktop' => $all[$k]['desktop'], 'mobile' => $all[$k]['mobile'], 'order' => $i, 'skin' => $all[$k]['skin'] ?? null];
    }

    $svc->save($payload);
    SettingsService::forgetMemo();
}

/** The Spotted <section> opening tag on the real homepage, or ''. */
function shrTag(\Tests\TestCase $t): string
{
    SpottedSettings::flush();
    $html = (string) $t->get('/')->assertOk()->getContent();

    return preg_match('#<section class="sec spt [^"]*"#', $html, $m) === 1 ? $m[0] : '';
}

function shrLayout(string $layout): void
{
    app(SpottedSettings::class)->save(['home_layout' => $layout]);
    SettingsService::forgetMemo();

    if ($layout === 'carousel') {
        SpottedPost::create([
            'image' => '/uploads/spotted/r.jpg', 'ig_url' => 'https://www.instagram.com/p/Row1/',
            'handle' => 'sara.glows', 'caption' => 'Torriden', 'sort' => 1, 'on_home' => true, 'on_page' => true,
        ]);
    }
}

it('hides on phones only when the row’s Phone switch is off', function (string $layout) {
    shrLayout($layout);
    expect(shrTag($this))->not->toBe('')->and(shrTag($this))->not->toContain('m-off');

    shrSaveRow('spotted', ['mobile' => false]);
    $tag = shrTag($this);

    expect($tag)->toContain(' m-off')->and($tag)->not->toContain('d-off');
})->with(['grid', 'carousel']);

it('hides on laptops only when the row’s Laptop switch is off', function (string $layout) {
    shrLayout($layout);
    shrSaveRow('spotted', ['desktop' => false]);
    $tag = shrTag($this);

    expect($tag)->toContain(' d-off')->and($tag)->not->toContain('m-off');
})->with(['grid', 'carousel']);

it('draws nothing at all when the row is off on both', function (string $layout) {
    shrLayout($layout);
    shrSaveRow('spotted', ['desktop' => false, 'mobile' => false]);

    expect(shrTag($this))->toBe('');
})->with(['grid', 'carousel']);

it('moves when the row is moved', function (string $layout) {
    shrLayout($layout);
    expect(shrTag($this))->not->toContain('kbb-ord-');

    shrSaveRow('spotted', [], ['spotted']);
    $order = app(HomepageSections::class)->all()['spotted']['order'];
    SpottedSettings::flush();
    $html = (string) $this->get('/')->getContent();

    expect(shrTag($this))->toMatch('/\bkbb-ord-'.$order.'\b/')
        ->and($html)->toContain('.kbb-home>.kbb-ord-'.$order.'{order:'.$order.'}');
})->with(['grid', 'carousel']);
