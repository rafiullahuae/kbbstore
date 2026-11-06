<?php

declare(strict_types=1);

/*
 * Lane SOT preview: switch the Super Sale banner to the template named by
 * SOT_TPL (super, m1 .. n3) the way the editor's picker does -- its words in
 * both languages, his colour overrides cleared -- and save it.
 *   SOT_TPL=m1 sh tools/sot-tinker.sh   -- never against a real database.
 */

use App\Services\SettingsService;
use App\Support\SuperSaleBanner;

$tpl = (string) getenv('SOT_TPL');
$settings = app(SettingsService::class);
$c = SuperSaleBanner::config($settings->all());
[$clean, $bad] = SuperSaleBanner::sanitize(SuperSaleBanner::withTemplate($c, $tpl));
if ($bad !== []) {
    throw new RuntimeException('refused: '.json_encode($bad));
}
$settings->set(SuperSaleBanner::KEY, $clean);
echo "template: {$clean['template']}\n";
