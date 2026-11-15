<?php
/* Lane LN15: each fetched page's Description body as this branch draws it on an English page.

   The live page's body is already past trimBlankEdges() / markBlankLines() (both no-ops on their
   own output), so the branch's render is that body through RichText::markHeadings(). Writes
   <out>/en-<slug>.html for tools/ln15-gaps.cjs (ITEMS "body") and prints how many bodies changed.

   php tools/ln15-after.php <tabs.json> <outdir> */
require __DIR__ . '/../vendor/autoload.php';

$tabs = json_decode((string) file_get_contents($argv[1]), true);
$out = $argv[2];
@mkdir($out, 0777, true);
$changed = 0;
foreach ($tabs as $slug => $r) {
    $body = $r['en'][0]['body'] ?? null;
    if ($body === null) {
        continue;
    }
    $after = App\Support\RichText::markHeadings($body);
    $changed += $after !== $body ? 1 : 0;
    file_put_contents("$out/en-$slug.html", $after);
}
echo count($tabs), " bodies, $changed with headings marked\n";
