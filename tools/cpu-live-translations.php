<?php
/*
 * Lane CPU -- give the arf-preview database a LIVE-SIZED Arabic catalogue: a
 * published translation for every product's name and short description, every
 * brand's and category's name and description, every menu label and every blog
 * post's title and excerpt (and a long description for every product, which
 * stays out of the map). The live shop has the catalogue translated
 * (2027_11_03_100000_arabic_content_catalogue writes it by live slug); the
 * synthetic slugs of tools/cpu-snapshot.php match none of those, so without
 * this the preview's map holds the interface strings only. PREVIEW ONLY.
 *   php tools/cpu-live-translations.php <preview.sqlite>
 */
$p = new PDO('sqlite:'.$argv[1]);
$p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$now = date('Y-m-d H:i:s');
$ins = $p->prepare('insert or ignore into translations (locale, "group", item_id, field, value, status, source, source_hash, reviewed_at, created_at, updated_at) values (\'ar\', ?, ?, ?, ?, \'published\', \'machine\', ?, ?, ?, ?)');
$word = static fn (int $n) => implode(' ', array_slice(['سيروم', 'كريم', 'تونر', 'مرطب', 'واقي', 'الشمس', 'للبشرة', 'الحساسة', 'منظف', 'رغوة', 'قناع', 'ورقي', 'مكثف', 'لطيف', 'يومي'], $n % 7, 4 + $n % 5));
$p->beginTransaction();
$n = 0;
$add = static function (string $g, int $id, string $f, string $v) use ($ins, $now, &$n): void { $ins->execute([$g, $id, $f, $v, sha1($v), $now, $now, $now]); $n++; };
foreach ($p->query('select id from products') as $r) {
    $add('products', (int) $r['id'], 'name', $word((int) $r['id']).' '.$r['id']);
    $add('products', (int) $r['id'], 'short_description', 'تركيبة '.$word((int) $r['id'] + 3).' لجميع أنواع البشرة.');
    $add('products', (int) $r['id'], 'description', '<p>'.str_repeat($word((int) $r['id']).'. ', 60).'</p>');
}
foreach (['brands', 'categories'] as $g) {
    foreach ($p->query("select id from $g") as $r) {
        $add($g, (int) $r['id'], 'name', $word((int) $r['id']));
        $add($g, (int) $r['id'], 'description', 'وصف '.$word((int) $r['id'] + 1));
    }
}
foreach ($p->query('select id from menu_items') as $r) {
    $add('menu_items', (int) $r['id'], 'label', $word((int) $r['id']));
}
foreach ($p->query('select id from posts') as $r) {
    $add('posts', (int) $r['id'], 'title', $word((int) $r['id']));
    $add('posts', (int) $r['id'], 'excerpt', $word((int) $r['id'] + 2));
}
$p->commit();
echo "cpu-live-translations: $n rows written; ar published now ", $p->query("select count(*) from translations where locale='ar' and status='published'")->fetchColumn(), "\n";
