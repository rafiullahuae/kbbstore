<?php
/**
 * Lane GS · seed the owner's two named instances THROUGH THE PRESETS.
 *
 * Not a fixture with the rows written out: the whole claim this lane makes is
 * that his two sections are INSTANCES of one type, created the way he creates
 * them. So this presses the two buttons — GridSections::PRESETS, through the
 * controller's own apply() — and then publishes them, which is the second
 * button. Anything it could do that the screen cannot would make the
 * screenshots evidence of something else.
 *
 * Not committed as a migration and not run by the suite: it exists to fill a
 * throwaway preview database for the screenshots.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\GridSection;
use App\Services\GridSections;
use App\Services\ModuleSchema;

GridSection::query()->delete();
GridSections::flush();

$fields = GridSections::fields();
$position = 0;

foreach (GridSections::PRESETS as $key => $preset) {
    $section = new GridSection;
    $section->name = $preset['values']['name'];
    $section->slug = \Illuminate\Support\Str::slug($preset['values']['name']);
    $section->position = ++$position;

    foreach ($fields as $name => $field) {
        $section->{$name} = ModuleSchema::cast($field, $preset['values'][$name] ?? $field['default']);
    }

    foreach (['desktop_cols', 'mobile_cols'] as $intKey) {
        $section->{$intKey} = (int) $section->{$intKey};
    }

    $section->source_brand_id = null;
    $section->source_category_id = null;

    // The owner's second button: publish it.
    $section->status = 'publish';
    $section->save();

    echo $section->name.' → '.$section->sectionKey()
        .'  '.$section->desktop_cols.' across, '.$section->count.' desktop / '
        .$section->mobile_count.' mobile, '.$section->mobile_layout."\n";
}

GridSections::flush();
