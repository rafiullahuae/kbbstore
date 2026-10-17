<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\GridSection;
use App\Services\GridSections;

/**
 * Appearance → Grid sections → Edit, laid out as steps.            (Lane HTE)
 *
 * The owner, on the editor of a section with "Show this section as tabs" on:
 * "the backend layout is very scattered, can i get the nice and easy to
 * understand and managed layout."
 *
 * What the defect looked like: one long form — This grid, Heading, Which
 * products (ignored by tabs, with a note saying so), then a Product tabs card
 * whose English/Arabic boxes were scattered over a six-column auto-fit grid,
 * the English heading in one card and its Arabic twin two cards further down.
 *
 * These cases RUN the screen's own script in node, against the real
 * GET /admin-api/grid-sections/{id} payload, with a stand-in document that
 * records what the screen paints into #content and every request it makes.
 * Nothing in the browser measures or is measured.
 *
 * MUTATION NOTES, each one run:
 *   - draw headingStep()'s Arabic box in its own row instead of the pair
 *       -> "pairs every English line with its Arabic twin" is red.
 *   - in stepsView() clear the hidden source fields from the draft
 *     (draft.values.source_brand_id = '') when tabs are on
 *       -> "hiding the per-grid source controls is presentation only" is red.
 *   - drop `placed[k] = 1` bookkeeping so a field is drawn twice
 *       -> "draws every other schema field exactly once" is red.
 */
function hteAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'HTE owner', 'email' => 'hte-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);
}

/** A section shown as tabs, drawing from ONE BRAND as an ordinary grid — so hiding that source has a value to lose. */
function hteSection(AdminUser $admin): GridSection
{
    $brand = Brand::firstOrCreate(['slug' => 'hte-brand'], ['name' => 'HTE Brand']);
    $n = GridSection::query()->count() + 1;

    $section = GridSection::create([
        'name' => 'HTE '.$n, 'slug' => 'hte-'.$n.'-'.uniqid(), 'status' => 'draft', 'position' => $n,
        'show_heading' => true, 'heading' => 'Loved most', 'subheading' => 'Picked by you',
        'source' => 'brand', 'source_brand_id' => $brand->id, 'include_children' => false, 'count' => 4, 'mobile_count' => 6,
        'desktop_layout' => 'grid', 'desktop_cols' => 4, 'mobile_layout' => 'carousel', 'mobile_cols' => 2,
        'skin' => '', 'card_label' => '', 'show_rank' => false,
        'show_view_all' => true, 'view_all_label' => 'See them all', 'view_all_url' => '/shop/',
    ]);

    test()->actingAs($admin, 'admin')->putJson('/admin-api/grid-sections/'.$section->id, [
        'product_tabs' => [
            'eyebrow' => 'Shop the edit', 'eyebrow_ar' => 'تسوقي', 'heading_ar' => 'الأكثر حبًا', 'sub_ar' => 'اختياركم', 'view_all_ar' => 'عرض الكل',
            'align' => 'left',
            'list' => [
                ['title' => 'Best Sellers', 'title_ar' => 'الأكثر مبيعًا', 'source' => 'bestsellers', 'url' => '/best-sellers'],
                ['title' => 'Trending K-Beauty', 'title_ar' => 'رائج', 'source' => 'trending', 'url' => ''],
                ['title' => 'New Arrivals', 'title_ar' => 'وصل حديثًا', 'source' => 'newest', 'url' => '/new-in'],
            ],
        ],
        'tab_style' => 'b',
    ])->assertOk();

    return $section->fresh();
}

/** The screen's one inline script, as the browser gets it. */
function hteScript(): string
{
    $blade = (string) file_get_contents(resource_path('views/admin/partials/grid-sections-screen.blade.php'));
    expect(substr_count($blade, '<script>'))->toBe(1);

    return (string) preg_replace('#^.*?<script>(.*?)</script>.*$#s', '$1', $blade);
}

/** Runs $body in node with the screen loaded; `run(steps)` opens the editor and returns {html, puts}. */
function hteNode(array $payload, string $body): array
{
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        test()->markTestSkipped('node is not on this machine');
    }

    $js = "const vm = require('node:vm');\n"
        .'const SRC = '.json_encode(hteScript()).";\n"
        .'const P = '.json_encode($payload).";\n"
        .<<<'JS'
const tick = () => new Promise(r => setImmediate(r));
async function run(steps) {
  const stubs = {}, calls = []; let tracker = null;
  const stub = sel => stubs[sel] || (stubs[sel] = {
    innerHTML: '', textContent: '', disabled: false,
    dataset: sel === '.side .nav-item.on' ? {go: 'gridsections'} : {},
    classList: {add(){}, remove(){}, toggle(){}}, setAttribute(){}, contains(){ return false; },
    click(){ return this.onclick && this.onclick(); }
  });
  const document = {readyState: 'complete', cookie: '', activeElement: null,
    querySelector: stub, querySelectorAll: () => [], addEventListener(){}};
  const window = {location: {pathname: '/admin'}, go(){}, kbbAddNavEntry(){}, toast(){}, confirm: () => true,
    kbbDrafts: {track(t){ tracker = t; }, flush(){}, ready(){}, saved(){}, drop(){}}};
  const fetch = async (url, o) => {
    const method = (o && o.method) || 'GET';
    calls.push({url, method, body: o && o.body ? JSON.parse(o.body) : null});
    const body = /\/grid-sections$/.test(url) ? {sections: [P.section], presets: []}
      : (/\/grid-sections\/\d+$/.test(url) ? (method === 'GET' ? P : {}) : {});
    return {ok: true, status: 200, json: async () => body};
  };
  vm.runInNewContext(SRC, {window, document, fetch, setTimeout: () => 0, clearTimeout(){}, console});
  tracker.open(P.section.id);
  for (let i = 0; i < 5; i++) await tick();
  const html = {};
  for (const [name, fn] of steps) { if (fn) fn(tracker); tracker.render(); html[name] = stubs['#content'].innerHTML; }
  stubs['#gss-save'].click();
  for (let i = 0; i < 5; i++) await tick();
  return {html, puts: calls.filter(c => c.method === 'PUT').map(c => c.body)};
}
JS
        ."\n(async () => {\n".$body."\n})().then(o => process.stdout.write(JSON.stringify(o)), e => process.stdout.write('ERR ' + e.stack));\n";

    $file = tempnam(sys_get_temp_dir(), 'kbbhte').'.cjs';
    file_put_contents($file, $js);
    $raw = (string) shell_exec('node '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    $out = json_decode($raw, true);
    expect($out)->toBeArray('node could not run the probe: '.$raw);

    return $out;
}

/** The <div class="gss-pr"> row that holds $needle. */
function hteRow(string $html, string $needle): string
{
    foreach (explode('<div class="gss-pr">', $html) as $chunk) {
        if (str_contains($chunk, $needle)) {
            return $chunk;
        }
    }

    return '';
}

beforeEach(function () {
    GridSections::flush();
});

it('shows the editor as five steps, pairs every English line with its Arabic twin, and folds all tabs but the first', function () {
    $admin = hteAdmin();
    $section = hteSection($admin);
    $payload = test()->actingAs($admin, 'admin')->getJson('/admin-api/grid-sections/'.$section->id)->assertOk()->json();

    $out = hteNode($payload, "return await run([['on', null]]);");
    $html = $out['html']['on'];

    // The five steps, in this order, each with its plain heading.
    $at = [];
    foreach (['basics' => 'Basics', 'look' => 'Look', 'heading' => 'Heading', 'products' => 'Products', 'tabs' => 'Tabs'] as $id => $title) {
        $pos = strpos($html, 'data-gss-step="'.$id.'"');
        expect($pos)->not->toBeFalse("step {$id} is missing")
            ->and(substr($html, $pos, 400))->toContain('<div class="gss-title">'.$title.'</div>');
        $at[] = $pos;
    }
    expect($at)->toBe(array_values(array_unique($at)))->and($at)->toBe(collect($at)->sort()->values()->all());

    // EN | AR: each English box shares its row with its Arabic twin and its Translate button.
    foreach ([
        'data-gss-t="eyebrow"' => 'eyebrow_ar',
        'data-gss-key="heading"' => 'heading_ar',
        'data-gss-key="subheading"' => 'sub_ar',
        'data-gss-key="view_all_label"' => 'view_all_ar',
    ] as $english => $arabic) {
        $row = hteRow($html, $english);
        expect($row)->toContain('data-gss-t="'.$arabic.'"')
            ->and($row)->toContain('data-gss-tr="'.$arabic.'"')
            ->and($row)->toContain('data-lang="English"')
            ->and($row)->toContain('data-lang="Arabic"');
    }

    // Tab style as two choice cards, alignment as a two-option segment, Left first.
    expect(substr_count($html, 'data-gss-t="@style"'))->toBe(2)
        ->and($html)->toContain('B · Rounded buttons')
        ->and(substr_count($html, 'data-gss-t="align"'))->toBe(2)
        ->and(strpos($html, '<span>Left</span>'))->toBeLessThan(strpos($html, '<span>Centre</span>'))
        ->and($html)->toMatch('/value="b" data-gss-t="@style" checked/')
        ->and($html)->toMatch('/value="left" data-gss-t="align" checked/');

    // Three tab cards, the first open, the others folded; Add tab under them.
    expect(substr_count($html, 'data-gss-tcard="'))->toBe(3)
        ->and($html)->toContain('<div class="gss-tab is-open" data-gss-tcard="0">')
        ->and($html)->toContain('<div class="gss-tab" data-gss-tcard="1">')
        ->and($html)->toContain('<div class="gss-tab" data-gss-tcard="2">')
        ->and($html)->toContain('data-gss-tname="1"> · Trending K-Beauty</span>')
        ->and($html)->toContain('+ Add tab');

    // The grid's own source is not drawn while tabs are on — one line says why — and the counts are.
    expect($html)->not->toContain('data-gss-key="source"')
        ->and($html)->not->toContain('data-gss-key="source_brand_id"')
        ->and($html)->toContain('“Which products” is not used while this section shows as tabs')
        ->and($html)->toContain('data-gss-key="count"')
        ->and($html)->toContain('data-gss-key="mobile_count"');

    // Every other schema field is drawn exactly once: nothing lost, nothing doubled.
    $hidden = ['source', 'source_brand_id', 'source_category_id', 'include_children', 'show_view_all', 'view_all_url'];
    foreach (array_diff(array_keys(GridSections::SCHEMA), $hidden) as $key) {
        expect(substr_count($html, 'data-gss-key="'.$key.'"'))->toBe(1, "{$key} drawn the wrong number of times");
    }

    // Tabs off: the grid's own source controls come back, with the brand it was set to.
    // Lane GAR (deliberately moved from "no Arabic column"): the owner, on an ordinary
    // grid's English heading on /ar -- "this heading on the homepage is not translated,
    // please do" -- so the English | Arabic table stays, each box with its Translate.
    $off = hteNode($payload, "return await run([['off', t => t.set('ptabs', 'null')]]);")['html']['off'];
    expect($off)->toContain('data-gss-key="source"')
        ->and($off)->toContain('data-gss-key="source_brand_id"')
        ->and($off)->toContain('data-gss-key="view_all_url"')
        ->and($off)->not->toContain('data-gss-step="tabs"');
    foreach (['data-gss-key="heading"' => 'heading_ar', 'data-gss-key="subheading"' => 'sub_ar', 'data-gss-key="view_all_label"' => 'view_all_ar', 'data-gss-t="eyebrow"' => 'eyebrow_ar'] as $english => $arabic) {
        $row = hteRow($off, $english);
        expect($row)->toContain('data-gss-t="'.$arabic.'"')
            ->and($row)->toContain('data-gss-tr="'.$arabic.'"')
            ->and($row)->toContain('data-lang="Arabic"');
    }
});

it('hiding the per-grid source controls is presentation only: the saved payload is the same for the same typing', function () {
    $admin = hteAdmin();
    $section = hteSection($admin);
    $payload = test()->actingAs($admin, 'admin')->getJson('/admin-api/grid-sections/'.$section->id)->assertOk()->json();

    // A: tabs on throughout (source controls never drawn). B: tabs switched off (drawn), then on again.
    $out = hteNode($payload, <<<'JS'
const edit = t => t.set('v.heading', 'Loved most, edited');
const a = await run([['edit', edit]]);
const b = await run([['off', t => t.set('ptabs', 'null')], ['on', t => t.set('ptabs', JSON.stringify(P.product_tabs))], ['edit', edit]]);
return {a: a.puts, b: b.puts};
JS);

    expect($out['a'])->toHaveCount(1)->and($out['b'])->toHaveCount(1)
        ->and($out['b'][0])->toBe($out['a'][0]);

    $put = $out['a'][0];
    $expected = [];
    foreach ($payload['tabs'] as $tab) {
        foreach ($tab['fields'] as $f) {
            $expected[$f['key']] = $f['value'];
        }
    }
    $expected['heading'] = 'Loved most, edited';

    // Every schema value goes back exactly as loaded — the hidden brand source included.
    expect($put['values'])->toBe($expected)
        ->and((string) $put['values']['source'])->toBe('brand')
        ->and((string) $put['values']['source_brand_id'])->toBe((string) $section->source_brand_id)
        ->and($put)->not->toHaveKey('product_tabs');

    // And the server stores it with the source intact.
    test()->actingAs($admin, 'admin')->putJson('/admin-api/grid-sections/'.$section->id, $put)->assertOk();
    $fresh = $section->fresh();
    expect($fresh->heading)->toBe('Loved most, edited')
        ->and($fresh->source)->toBe('brand')
        ->and((int) $fresh->source_brand_id)->toBe((int) $section->source_brand_id)
        ->and($fresh->tabs)->toBe($section->tabs);
});

it('gives an ordinary grid (tabs off) the English | Arabic heading table, and saves its Arabic without turning tabs on', function () {
    /*
     * Lane GAR. The owner, on an ordinary grid's English heading on /ar: "this
     * heading on the homepage is not translated, please do". With tabs off the
     * editor had no Arabic box at all ("With tabs off it is English only").
     *
     * MUTATION: draw headingStep()'s Arabic boxes only `on` again and the
     * heading_ar row is red; send `draft.ptabs` (null) as product_tabs and the
     * PUT carries no words — red; send tab_style with it and the "no
     * tab_style" line is red; let the server keep clean() and the stored words
     * are null — red.
     */
    $admin = hteAdmin();
    $brand = Brand::firstOrCreate(['slug' => 'hte-brand'], ['name' => 'HTE Brand']);
    $section = GridSection::create([
        'name' => 'GAR grid', 'slug' => 'gar-grid-'.uniqid(), 'status' => 'draft', 'position' => 1,
        'show_heading' => true, 'heading' => 'Recommended for you', 'subheading' => 'Picked for your skin',
        'source' => 'brand', 'source_brand_id' => $brand->id, 'include_children' => false, 'count' => 4, 'mobile_count' => 4,
        'desktop_layout' => 'grid', 'desktop_cols' => 4, 'mobile_layout' => 'grid', 'mobile_cols' => 2,
        'skin' => '', 'card_label' => '', 'show_rank' => false,
        'show_view_all' => false, 'view_all_label' => '', 'view_all_url' => '', 'tab_style' => 'b',
    ]);
    $payload = test()->actingAs($admin, 'admin')->getJson('/admin-api/grid-sections/'.$section->id)->assertOk()->json();
    expect($payload['product_tabs'])->toBeNull();

    $out = hteNode($payload, "return await run([['typed', t => t.set('words', JSON.stringify({heading_ar: 'مقترحة لكِ'}))]]);");
    $html = $out['html']['typed'];

    expect($html)->not->toContain('data-gss-step="tabs"');
    foreach (['data-gss-key="heading"' => 'heading_ar', 'data-gss-key="subheading"' => 'sub_ar', 'data-gss-key="view_all_label"' => 'view_all_ar', 'data-gss-t="eyebrow"' => 'eyebrow_ar'] as $english => $arabic) {
        $row = hteRow($html, $english);
        expect($row)->toContain('data-gss-t="'.$arabic.'"')
            ->and($row)->toContain('data-gss-tr="'.$arabic.'"');
    }
    expect(hteRow($html, 'data-gss-key="heading"'))->toContain('value="مقترحة لكِ"');

    expect($out['puts'])->toHaveCount(1);
    $put = $out['puts'][0];
    expect($put['product_tabs'])->toBe([
        'eyebrow' => '', 'eyebrow_ar' => '', 'heading_ar' => 'مقترحة لكِ', 'sub_ar' => '', 'view_all_ar' => '',
        'align' => 'center', 'list' => [],
    ])->and($put)->not->toHaveKey('tab_style');

    test()->actingAs($admin, 'admin')->putJson('/admin-api/grid-sections/'.$section->id, $put)->assertOk();
    $fresh = $section->fresh();
    expect($fresh->tabs['heading_ar'])->toBe('مقترحة لكِ')
        ->and(\App\Support\GridTabs::of($fresh))->toBeNull()
        ->and($fresh->tab_style)->toBe('b')
        ->and($fresh->source)->toBe('brand');

    // Reopened: the Arabic is in its box, and an untouched editor has nothing to save.
    $again = test()->actingAs($admin, 'admin')->getJson('/admin-api/grid-sections/'.$section->id)->assertOk()->json();
    $out = hteNode($again, "return await run([['open', null]]);");
    expect(hteRow($out['html']['open'], 'data-gss-key="heading"'))->toContain('value="مقترحة لكِ"')
        ->and($out['puts'])->toBe([]);
});
