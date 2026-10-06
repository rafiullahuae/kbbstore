<?php
/*
 * Lane TP: write database/migrations/2027_09_05_200000_owner_policy_pages.php
 * from the owner's pasted text in docs/tp-source/<slug>.txt, for every slug in
 * App\Support\PastedPolicyText::SOURCES.
 *
 *     php tools/tp-make-migration.php
 *
 * OwnerPolicyPagesTest requires the migration's pages to equal what
 * PastedPolicyText makes of the paste, so a hand edit to either side goes red.
 */
require __DIR__.'/../vendor/autoload.php';

$pages = '';

foreach (App\Support\PastedPolicyText::SOURCES as $slug => $headings) {
    $r = App\Support\PastedPolicyText::toHtml((string) file_get_contents(__DIR__.'/../docs/tp-source/'.$slug.'.txt'), $headings);
    $pages .= "        '{$slug}' => [\n            'title' => ".var_export($r['title'], true).",\n            'content' => <<<'HTML'\n{$r['html']}\nHTML,\n        ],\n";
}

$template = (string) file_get_contents(__DIR__.'/tp-migration.stub');
file_put_contents(__DIR__.'/../database/migrations/2027_09_05_200000_owner_policy_pages.php', str_replace("        /* PAGES */\n", $pages, $template));
echo "written\n";
