<?php

declare(strict_types=1);

/**
 * Lane ZM -- no page zoom when a field takes focus, anywhere.
 *
 * The owner: "upon input text in the fields the screen become zoomed in apple
 * devices ... fix this in all type of devices and for all input fields in our
 * whole website." iOS Safari zooms the page in when a field whose COMPUTED
 * font-size is under 16px takes focus, and does not zoom back out. Measured at
 * 390 before the fix: the footer newsletter 13.5px on every shop page, the
 * address book 14px, the 404 search 15px, the skin quiz 13.5px, the admin
 * sign-in 14px and 529 of 689 fields across shop, owner app and admin under
 * 16px (docs/zm-fields/fields.json).
 *
 * The fix is a touch-only, by-element floor in every stylesheet a field can be
 * drawn by -- never `maximum-scale` / `user-scalable=no`, which takes pinch
 * zoom away from everybody and fails Lighthouse's accessibility audit.
 */
const ZM_TOUCH = '@media (hover:none),(pointer:coarse)';
const ZM_FIELDS = ':where(input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=color]):not([type=file]):not([type=submit]):not([type=button]):not([type=reset]):not([type=image]),';

/** The body of the LAST touch block in a stylesheet, whitespace-normalised. */
function zmTouchBlock(string $css): string
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    $css = zmNorm($css);
    $at = strrpos($css, '@media (hover:none),(pointer:coarse){');
    expect($at)->not->toBeFalse('no touch block');

    // Walk braces to the block's own close.
    $depth = 0;
    for ($i = strpos($css, '{', $at); $i < strlen($css); $i++) {
        $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);
        if ($depth === 0) {
            return substr($css, $at, $i - $at + 1);
        }
    }

    return '';
}

function zmNorm(string $s): string
{
    return preg_replace('/\s*([{},:;])\s*/', '$1', preg_replace('/\s+/', ' ', $s));
}

it('floors every text field at 16px on a touch screen, in the shop stylesheet', function () {
    // Mutation: drop !important and the footer newsletter (`.kbb-home .nl .f
    // input`, a class rule) stays 13.5px -- the exact field the 820px block
    // above it already missed. Drop select/textarea and the address book's
    // country select and the quiz note zoom again.
    $block = zmTouchBlock((string) file_get_contents(resource_path('css/kbb/kbb.css')));

    expect($block)->toContain(zmNorm(ZM_FIELDS))
        ->toContain('select,textarea,[contenteditable]:not([contenteditable=false]))')
        ->toContain('{font-size:max(16px,calc(16px * var(--cop-tinput,1)))!important}');
});

it('keeps a field a setting draws LARGER than 16px at that size', function () {
    // The floor is :where() -- specificity zero -- so a re-assertion that names
    // its field wins. Mutation: unwrap the :where() and its nine :not() tests
    // make it (0,9,1): the header search set to 20px under Appearance -> Mobile
    // Header was put back to 16px, and in the owner app the price field shrank
    // from 17px to 16px (measured, before this was :where).
    $block = zmTouchBlock((string) file_get_contents(resource_path('css/kbb/kbb.css')));
    expect($block)->toStartWith('@media (hover:none),(pointer:coarse){:where(')
        // The checkout's Text sizes "Fields" slider still raises them: the
        // floor IS the rule kbb-checkout.css applies on a phone.
        ->toContain('var(--cop-tinput,1)')
        ->toContain('header .sbox .search-in input{font-size:max(16px,calc(var(--mh-stsize,16px) * max(1,var(--mh-sfit,1))))!important}');

    $checkout = zmNorm((string) file_get_contents(resource_path('css/kbb/kbb-checkout.css')));
    expect($checkout)->toContain('select{font-size:max(16px,calc(16px * var(--cop-tinput)))}');
});

it('floors the owner app without shrinking the fields it draws larger', function () {
    // Mutation: re-assert `.inp input` at 17px instead of leaving .inp out, and
    // it beats `.enrol .fld input{font-size:16px}`: the PIN field on the sign-in
    // screen grew from 16px to 17px and from 206px to 220px wide (measured).
    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    $block = zmTouchBlock($css);

    expect($block)->toContain(zmNorm(ZM_FIELDS))
        ->toContain('select,textarea,[contenteditable]:not([contenteditable=false])):where(:not(.inp *,.step *)){font-size:16px !important;}');

    // What is left out is already at or above the floor by its own rule.
    $flat = zmNorm($css);
    expect($flat)->toContain('.inp input,.inp select{flex:1;min-width:0;border:0;background:none;outline:0;font-size:17px;')
        ->toContain('.step input{flex:1;min-width:0;text-align:center;font:700 20px/1 var(--font);')
        ->toContain('.enrol .fld input{font-size:16px;');
});

it('puts the same floor in every document that carries its own stylesheet', function () {
    // The skin quiz, the /app preview and the admin's standalone screens do
    // not load kbb.css. Mutation: drop the include from the quiz and its
    // name/email/note fields are 13.5px again.
    $partial = (string) file_get_contents(resource_path('views/partials/no-focus-zoom.blade.php'));
    expect(zmNorm($partial))->toContain('<style>'.ZM_TOUCH.'{'.ZM_FIELDS.'select,textarea,[contenteditable]:not([contenteditable=false])){font-size:16px!important}}</style>');

    foreach (['store/skin-quiz', 'store/app', 'admin/login', 'admin/updates', 'admin/cleanup', 'admin/article-addresses'] as $view) {
        $src = (string) file_get_contents(resource_path("views/{$view}.blade.php"));
        expect(substr_count($src, "@include('partials.no-focus-zoom')"))->toBe(1, $view);
    }

    // The /app preview's search field is 22px; it keeps it.
    expect((string) file_get_contents(resource_path('views/store/app.blade.php')))
        ->toContain('@media (hover:none),(pointer:coarse){.search-ov input{font-size:22px!important}}');
});

it('puts the floor in the admin console exactly once, wired or not', function () {
    // The console's head is inside @verbatim, so it carries the rule as literal
    // CSS (an @include there is printed as TEXT -- it was, and it drew
    // "@include(...)" across the top of the admin). Pinned on the FINISHED
    // state: applied in memory when the integrator has not run tools/zm-wire.php.
    $edits = json_decode((string) file_get_contents(base_path('docs/zm-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
    $src = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    foreach ($edits as $e) {
        if (! str_contains($src, $e['replacement'])) {
            expect(substr_count($src, $e['anchor']))->toBe($e['count']);
            $src = str_replace($e['anchor'], $e['replacement'], $src);
        }
    }

    $partial = (string) file_get_contents(resource_path('views/partials/no-focus-zoom.blade.php'));
    $rule = trim(substr($partial, (int) strrpos($partial, '<style>')));   // the rule, not the docblock's mention of <style>
    expect($rule)->toEndWith('</style>')
        ->and(substr_count($src, $rule))->toBe(1)
        // Under the size AdminConsoleAssets extracts, so it stays inline and
        // wiring it changes no built asset.
        ->and(strlen($rule))->toBeLessThan(\App\Support\AdminConsoleAssets::MIN_BYTES);
});

it('never locks zoom in a viewport meta', function () {
    // The other way to stop the zoom, and the wrong one: maximum-scale=1 or
    // user-scalable=no stop pinch-zoom for everyone (shoppers zoom product
    // photos; Android honours it) and fail Lighthouse's meta-viewport audit.
    $metas = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')));
    foreach ($it as $f) {
        if (! str_ends_with($f->getFilename(), '.blade.php')) {
            continue;
        }
        preg_match_all('~name=["\\\\]*viewport["\\\\]*\s+content=["\\\\]*([^"\\\\>]+)~i', (string) file_get_contents($f->getPathname()), $mm);
        foreach ($mm[1] as $content) {
            $metas++;
            expect(strtolower($content))->not->toContain('maximum-scale')
                ->not->toContain('user-scalable');
        }
    }
    expect($metas)->toBeGreaterThan(10);

    $layout = (string) file_get_contents(resource_path('views/layouts/store.blade.php'));
    expect($layout)->toContain('<meta name="viewport" content="width=device-width,initial-scale=1">');
});

it('measured every field at 16px or more on a touch screen, and a laptop unchanged', function () {
    // docs/zm-fields/fields.json is tools/zm-measure.cjs's output, before and
    // after, on an iPhone (390, touch, DPR 3) and a laptop (1280, mouse). Each
    // row: [field, px before, px after, height before, height after, scale].
    $f = json_decode((string) file_get_contents(base_path('docs/zm-fields/fields.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($f['touch']['media'])->toBe(['hoverNone' => true, 'coarse' => true])
        ->and($f['laptop']['media'])->toBe(['hoverNone' => false, 'coarse' => false]);

    $rows = 0;
    $labels = array_keys($f['touch']['pages']);
    foreach ($f['touch']['pages'] as $page => $fields) {
        foreach ($fields as [$field, $before, $after, , , $scale]) {
            $rows++;
            expect($after)->toBeGreaterThanOrEqual(16, "{$page} {$field}");
            if ($before !== null) {
                expect($after)->toBeGreaterThanOrEqual($before, "{$page} {$field} shrank");
            }
            expect($scale === null || $scale === 1)->toBeTrue("{$page} {$field} scaled");
        }
    }
    expect($rows)->toBeGreaterThan(600);
    foreach (['checkout-guest', 'checkout-signed-in', 'account-guest', 'addresses', 'skin-quiz', 'ar-checkout', 'owner-enrol', 'owner sheet price', 'admin-login', 'admin store-settings'] as $want) {
        expect(collect($labels)->contains(fn ($l) => str_starts_with($l, $want)))->toBeTrue($want);
    }

    foreach ($f['laptop']['pages'] as $page => $fields) {
        foreach ($fields as [$field, $before, $after, $hb, $ha]) {
            if ($before !== null) {
                expect([$after, $ha])->toBe([$before, $hb], "{$page} {$field} moved on a laptop");
            }
        }
    }
});
