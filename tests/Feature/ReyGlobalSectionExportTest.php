<?php

/**
 * The Rey "global sections" a product description pulls in by shortcode.
 *
 * THE DEFECT, AS IT LOOKED ON THE SHOP (1 October 2026): on kbeautybliss.com
 * the product page shows, under the description, a designed block -- "Gentle
 * Yet Effective Ingredients" over three pictures with QUERCETINOL / ANTI-SEBUM
 * P / 0.5% BHA beneath them and a sentence each. On extrabeauty.ae the same
 * place showed the literal text `[rey_global_section id="18159"]`. The block
 * is not in the description: it is its own WordPress post, built in Elementor
 * (layout in post meta `_elementor_data`, an HTML fallback in post_content),
 * and the description only names it. Exporter 1.9.1 carried the description
 * and therefore the shortcode, and carried nothing it pointed at.
 *
 * Plugin 1.10.0 writes content_blocks.csv and a shortcode census. Driven end to
 * end here: the REAL plugin over the harness's WordPress database with the
 * sections seeded (run-export.php --rey=1; the rows are described in
 * wordpress-plugin/harness/shop.php kbb_harness_rey_sections()). The importer
 * for the file is Lane PJ-B's ContentBlockImporter and is not exercised here.
 *
 * MUTATIONS, RUN (each red, then restored):
 *   - drop `(?<!\[)` from ids_in(): red -- the ESCAPED [[rey_global_section
 *     id="18162"]] in 4023's short description is read as a use, 18162 is
 *     exported at depth 1 with referenced_by 4023;
 *   - `$depth >= self::MAX_DEPTH` -> `$depth > self::MAX_DEPTH`: red, 18162
 *     (fourth level) is exported and the depth note disappears;
 *   - read post_content only in product_references(): red, referenced_by of
 *     18159 is `4022` -- 4021 names it from its short description;
 *   - drop `'` from the quote class in ids_in(): red, `id='18159'` stops
 *     matching and 4021 drops out of referenced_by again;
 *   - always include `trash` in product_statuses(): red, 18170 -- named only by
 *     a trashed product -- is exported on a default run;
 *   - remove the carried-ids clause from the posts stage's type_filter(): red,
 *     posts.csv carries 18159 / 18160 / 18161 a second time;
 *   - remove the `catalogue` check from carried_ids(): red, a Journal-only
 *     export drops the sections from posts.csv with nothing else carrying them.
 */

/** The harness database; see GeWpExporterTest::geWpDb() for why it is a variable. */
function rgsWpDb(): string
{
    $name = getenv('KBB_WP_DB');

    return is_string($name) && $name !== '' ? $name : 'kbb_ge_wp';
}

/**
 * Run the real plugin over the harness shop.
 *
 * @param  list<string>  $flags
 * @return array{dir: string, result: array<string, mixed>}
 */
function rgsExport(array $flags = ['--rey=1'], int $batch = 2): array
{
    $out = sys_get_temp_dir().'/kbb-rgs-'.bin2hex(random_bytes(4));
    $lines = [];

    exec(
        escapeshellcmd(PHP_BINARY).' '.escapeshellarg(base_path('wordpress-plugin/harness/run-export.php'))
            .' --storage=posts --out='.escapeshellarg($out).' --db='.rgsWpDb().' --batch='.$batch
            .' '.implode(' ', array_map('escapeshellarg', $flags)).' 2>&1',
        $lines,
        $status
    );

    if (3 === $status) {
        test()->markTestSkipped('no MySQL here: '.implode(' ', $lines));
    }

    expect($status)->toBe(0, 'export failed: '.implode("\n", $lines));

    return ['dir' => $out.'/export', 'result' => json_decode(implode("\n", $lines), true)];
}

/**
 * A CSV read as RFC 4180 -- plain_html carries newlines inside a quoted cell,
 * so a line-by-line read would split one section into several rows.
 *
 * @return array{header: list<string>, rows: list<array<string, string>>}
 */
function rgsCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    $header = fgetcsv($handle, 0, ',', '"', '');
    $rows = [];

    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $rows[] = array_combine($header, $row);
    }

    fclose($handle);

    return ['header' => $header, 'rows' => $rows];
}

/** @return array<int, array<string, string>> content_blocks.csv keyed by id */
function rgsBlocks(string $dir): array
{
    $out = [];

    foreach (rgsCsv($dir.'/content_blocks.csv')['rows'] as $row) {
        $out[(int) $row['id']] = $row;
    }

    return $out;
}

/** @return list<int> the ids a CSV's id column holds */
function rgsIds(string $path, string $column): array
{
    return array_map(static fn (array $row): int => (int) $row[$column], rgsCsv($path)['rows']);
}

function rgsNotes(string $dir): string
{
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    return implode("\n", $manifest['notes']);
}

/** The contract Lane PJ-B's importer reads. Renaming one of these breaks it. */
const RGS_COLUMNS = [
    'id', 'post_type', 'slug', 'title', 'status', 'modified',
    'shortcode', 'referenced_by', 'elementor_data', 'plain_html',
];

it('carries the section a description names, and the sections inside it, with the Elementor tree as stored', function () {
    $export = rgsExport();
    $csv = rgsCsv($export['dir'].'/content_blocks.csv');

    expect($csv['header'])->toBe(RGS_COLUMNS);

    $blocks = rgsBlocks($export['dir']);

    // The block from the shop, the "How To Use" section it embeds, and the
    // Elementor template THAT embeds -- each once, in id order.
    expect(array_keys($blocks))->toBe([18159, 18160, 18161]);

    $ingredients = $blocks[18159];

    expect($ingredients['post_type'])->toBe('rey-global-sections')
        ->and($ingredients['slug'])->toBe('ingredients-quercetinol')
        ->and($ingredients['title'])->toBe('Gentle Yet Effective Ingredients')
        ->and($ingredients['status'])->toBe('publish')
        // post_modified_gmt, not the local post_modified (13:14:15 in Dubai).
        ->and($ingredients['modified'])->toBe('2024-02-03 09:14:15')
        ->and($ingredients['shortcode'])->toBe('rey_global_section')
        // 4021 names it from its SHORT description, 4022 from its description.
        ->and($ingredients['referenced_by'])->toBe('4021|4022');

    // Selected by id, not by post type: 18161 is an Elementor library template,
    // and the old site rendered it through the same shortcode.
    expect($blocks[18161]['post_type'])->toBe('elementor_library');

    // A section only another section names is carried, with no product of its own.
    expect($blocks[18160]['referenced_by'])->toBe('')
        ->and($blocks[18161]['referenced_by'])->toBe('');

    // elementor_data is the meta value BYTE FOR BYTE -- escaped slashes and
    // all -- so the importer reads what Elementor wrote, not a re-encoding.
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.rgsWpDb().';charset=utf8mb4', 'kbb', 'kbb');
    $stored = $pdo->query("SELECT meta_value FROM wp_postmeta WHERE post_id = 18159 AND meta_key = '_elementor_data'")
        ->fetchColumn();

    expect($ingredients['elementor_data'])->toBe($stored);
    expect(str_contains($ingredients['elementor_data'], 'https:\/\/kbeautybliss.com\/wp-content\/uploads'))->toBeTrue();

    // And it is the real Elementor shape, with the three items in it.
    $tree = json_decode($ingredients['elementor_data'], true);
    $widgets = [];
    $walk = function ($node) use (&$walk, &$widgets) {
        if (is_array($node)) {
            if (($node['elType'] ?? null) === 'widget') {
                $widgets[] = $node['widgetType'].':'.($node['settings']['title_text'] ?? $node['settings']['title'] ?? '');
            }
            array_map($walk, $node);
        }
    };
    $walk($tree);

    expect($tree[0]['elType'])->toBe('section')
        ->and($widgets)->toContain('heading:Gentle Yet Effective Ingredients')
        ->and($widgets)->toContain('image-box:QUERCETINOL')
        ->and($widgets)->toContain('image-box:ANTI-SEBUM P')
        ->and($widgets)->toContain('heading:0.5% BHA');

    // The HTML fallback, newlines and all, in one cell.
    expect($ingredients['plain_html'])->toStartWith("<h2>Gentle Yet Effective Ingredients</h2>\n")
        ->and(substr_count($ingredients['plain_html'], "\n"))->toBe(4);

    // And the manifest counts exactly the rows the file holds.
    $manifest = json_decode((string) file_get_contents($export['dir'].'/manifest.json'), true);
    expect($manifest['files']['content_blocks.csv']['rows'])->toBe(3)
        ->and($manifest['counts']['content_blocks'])->toBe(3);
});

it('follows sections three levels down and no further, and names the one it left', function () {
    $export = rgsExport();
    $blocks = rgsBlocks($export['dir']);

    // 18161 names 18162 (a fourth level) and 18159 (a cycle). The cycle is not
    // a second row; the fourth level is not followed, and is SAID.
    expect($blocks)->not->toHaveKey(18162);
    expect(count(array_filter(array_keys($blocks), static fn (int $id): bool => $id === 18159)))->toBe(1);

    expect(rgsNotes($export['dir']))->toContain(
        'Sections nested more than 3 levels below a product are NOT in content_blocks.csv: id 18162 (inside section 18161).'
    );
});

it('does not read WordPress\'s escaped [[shortcode]] as a use', function () {
    $export = rgsExport();

    // 4023's short description says `Write [[rey_global_section id="18162"]]
    // to embed a section.` -- which the old site PRINTED. Nothing is exported
    // for it, and 4023 is not counted among the products using the tag.
    foreach (rgsBlocks($export['dir']) as $block) {
        expect(explode('|', $block['referenced_by']))->not->toContain('4023');
    }

    expect(rgsNotes($export['dir']))->toContain('[rey_global_section] in 2 products -- RESOLVED');
});

it('names an id a shortcode points at that does not exist, with what names it', function () {
    $notes = rgsNotes(rgsExport()['dir']);

    expect($notes)->toContain('A SHORTCODE NAMES A SECTION THAT DOES NOT EXIST')
        ->and($notes)->toContain('id 99999 (named by product 4022)')
        ->and($notes)->toContain('id 88888 (named by section 18160)');
});

it('takes a census of every shortcode in the descriptions, and says which it resolves', function () {
    $notes = rgsNotes(rgsExport()['dir']);

    /*
     * The four tags, most-used first: the one this export follows, and three
     * it does not -- a WordPress [caption], an [elementor-template], and
     * "[Limited edition]", which is text in brackets and no shortcode at all
     * and is flagged as unregistered rather than left to look like one.
     */
    expect($notes)->toContain(
        'SHORTCODES IN PRODUCT DESCRIPTIONS: 4 distinct tags across the description and short description of 4 products. '
        .'[rey_global_section] in 2 products -- RESOLVED: the posts it names are in content_blocks.csv; '
        .'[Limited] in 1 product -- NOT RESOLVED: the new shop receives it as text (no shortcode of that name is '
        .'registered on this site, so it may be ordinary text in square brackets, or belong to a plugin that is switched off); '
        .'[caption] in 1 product -- NOT RESOLVED: the new shop receives it as text; '
        .'[elementor-template] in 1 product -- NOT RESOLVED: the new shop receives it as text.'
    );

    // What the sections themselves hold, for whoever draws them.
    expect($notes)->toContain('By post type: 1 elementor_library, 2 rey-global-sections.')
        ->and($notes)->toContain('Elementor widgets they use: heading x3, icon-list x1, image x1, image-box x2, shortcode x2, text-editor x3.')
        ->and($notes)->toContain('They name 4 pictures by address inside `elementor_data`; media.csv does not list them')
        ->and($notes)->toContain('Shortcodes INSIDE the exported sections that this export does not resolve: [elementor-template] in section 18161.');
});

it('leaves out a section only a trashed product names, unless the trash is exported', function () {
    // The products stage's own rule: a product in the trash is not exported,
    // so neither is a section nothing else uses.
    expect(rgsBlocks(rgsExport()['dir']))->not->toHaveKey(18170);

    $withTrash = rgsBlocks(rgsExport(['--rey=1', '--include_trashed=1'])['dir']);

    expect($withTrash)->toHaveKey(18170)
        ->and($withTrash[18170]['referenced_by'])->toBe('4024')
        ->and($withTrash[18170]['elementor_data'])->toBe('');
});

it('stops writing a carried section into posts.csv and permalinks.csv -- and only when it is carried', function () {
    $dir = rgsExport()['dir'];

    /*
     * 18159-18161 used to be posts.csv rows that PostImporter refuses by name
     * as a type the shop has no screen for. Carried in content_blocks.csv now,
     * so they are not posts.csv or permalinks.csv rows as well. 18162 and 18170
     * are NOT carried (too deep; only the trash names it), so they stay exactly
     * where 1.9.1 put them.
     */
    $posts = rgsIds($dir.'/posts.csv', 'id');
    $links = rgsIds($dir.'/permalinks.csv', 'wc_id');

    foreach ([18159, 18160, 18161] as $carried) {
        expect($posts)->not->toContain($carried)
            ->and($links)->not->toContain($carried);
    }

    expect($posts)->toContain(18162)->toContain(18170)
        ->and($links)->toContain(18162)->toContain(18170);

    // A Journal-only export writes no content_blocks.csv, so nothing else
    // carries the sections and posts.csv keeps every one of them.
    $journal = rgsExport(['--rey=1', '--groups=content'])['dir'];

    expect(file_exists($journal.'/content_blocks.csv'))->toBeFalse();
    expect(rgsIds($journal.'/posts.csv', 'id'))->toContain(18159)->toContain(18160)->toContain(18161);
});

it('writes the same file one row per request as in one request', function () {
    // Every batch is a fresh runner, as every HTTP request is. One row per
    // request against everything in one must be the same bytes.
    $one = rgsExport(['--rey=1'], 1)['dir'];
    $all = rgsExport(['--rey=1'], 500)['dir'];

    expect(hash_file('sha256', $one.'/content_blocks.csv'))->toBe(hash_file('sha256', $all.'/content_blocks.csv'))
        ->and(rgsNotes($one))->toBe(rgsNotes($all));
});

it('reads a section id however the shortcode was written', function () {
    $script = sys_get_temp_dir().'/kbb-rgs-ids-'.bin2hex(random_bytes(4)).'.php';
    $includes = base_path('wordpress-plugin/kbb-exporter/includes');

    file_put_contents($script, '<?php
        define("ABSPATH", "/");
        require '.var_export($includes.'/class-kbb-export-wp.php', true).';
        require '.var_export($includes.'/class-kbb-export-stage.php', true).';
        require '.var_export($includes.'/stages/class-kbb-export-stage-content-blocks.php', true).';
        $cases = json_decode(stream_get_contents(STDIN), true);
        $out = [];
        foreach ($cases["ids"] as $text) { $out["ids"][] = KBB_Export_Stage_Content_Blocks::ids_in($text, "rey_global_section"); }
        foreach ($cases["tags"] as $text) { $out["tags"][] = KBB_Export_Stage_Content_Blocks::tags_in($text); }
        echo json_encode($out);
    ');

    $ids = [
        '[rey_global_section id="18159"]' => [18159],
        "[rey_global_section id='18159']" => [18159],
        '[rey_global_section id=18159]' => [18159],
        '[rey_global_section class="mt-0" id="18159" title="x"]' => [18159],
        // In a JSON string or a slashed value the quote is \".
        '[rey_global_section id=\"18159\"]' => [18159],
        // As an entity, and as the curly quotes a copy from a rendered page brings.
        '[rey_global_section id=&quot;18159&quot;]' => [18159],
        "[rey_global_section id=\u{201D}18159\u{2033}]" => [18159],
        '[rey_global_section id="1"][rey_global_section id="2"/] [rey_global_section id="1"]' => [1, 2],
        // Not `id=`, not this tag, not a use, and not an id at all.
        '[rey_global_section data-id="5" gs_id="6"]' => [],
        '[rey_global_sections id="7"]' => [],
        '[[rey_global_section id="8"]]' => [],
        '[rey_global_section]' => [],
    ];

    $tags = [
        'a [caption id="1"]x[/caption] [Limited edition] [[escaped]] [1] [embed]u[/embed] [gallery/] [caption]' => [
            'caption', 'Limited', 'embed', 'gallery',
        ],
        'no brackets here' => [],
    ];

    $process = proc_open(
        [PHP_BINARY, $script],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    fwrite($pipes[0], json_encode(['ids' => array_keys($ids), 'tags' => array_keys($tags)]));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($process);
    unlink($script);

    $got = json_decode((string) $stdout, true);

    expect($got)->toBeArray($stdout.$stderr);

    foreach (array_values($ids) as $i => $expected) {
        expect($got['ids'][$i])->toBe($expected, array_keys($ids)[$i]);
    }

    foreach (array_values($tags) as $i => $expected) {
        expect($got['tags'][$i])->toBe($expected, array_keys($tags)[$i]);
    }
});

it('changes nothing else on a shop with no shortcodes: one header-only file and a census that says none', function () {
    // The checked-in export of the ordinary harness shop. Every other file in
    // it is pinned byte for byte by GeWpExporterTest's regeneration.
    $dir = base_path('tests/Fixtures/kbb-export');

    expect(rgsCsv($dir.'/content_blocks.csv'))->toBe(['header' => RGS_COLUMNS, 'rows' => []]);

    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    expect($manifest['files']['content_blocks.csv']['rows'])->toBe(0)
        ->and(rgsNotes($dir))->toContain('SHORTCODES IN PRODUCT DESCRIPTIONS: none.');
});

it('is what tests/Fixtures/kbb-export-rey holds, byte for byte', function () {
    /*
     * The same export, checked in, so the importer side can be tested on
     * real plugin output where there is no MySQL. Regenerate with:
     *   php wordpress-plugin/harness/run-export.php --storage=posts --batch=7 --rey=1 --out=... --db=...
     * and copy the CSVs and manifest.json into tests/Fixtures/kbb-export-rey.
     */
    $dir = rgsExport(['--rey=1'], 7)['dir'];
    $fixture = base_path('tests/Fixtures/kbb-export-rey');

    $expected = array_map('basename', glob($fixture.'/*.csv') ?: []);
    $written = array_map('basename', glob($dir.'/*.csv') ?: []);

    expect($written)->toBe($expected);

    foreach ($expected as $name) {
        expect(hash_file('sha256', $dir.'/'.$name))->toBe(
            hash_file('sha256', $fixture.'/'.$name),
            "{$name} no longer matches tests/Fixtures/kbb-export-rey; regenerate it if the change is intended"
        );
    }

    $manifest = json_decode((string) file_get_contents($fixture.'/manifest.json'), true);

    expect($manifest['source']['plugin_version'])->toBe(
        json_decode((string) file_get_contents($dir.'/manifest.json'), true)['source']['plugin_version']
    )->and($manifest['notes'])->toBe(json_decode((string) file_get_contents($dir.'/manifest.json'), true)['notes']);
});
