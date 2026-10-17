{{--
    The language switcher (16 October 2026), in the footer's bottom row.

    The shop had no visible way to choose العربية or English (Lane AS audit). The
    header is locked (CLAUDE.md), so the footer carries it: each language named
    in its own script, the current one plain text, the other a link to THIS
    page's own address in that language -- Seo::readerAlternatePaths(), the same
    pair the page's hreflang links name, so the switch and Google agree.

    While Arabic is OFF the alternates are empty and this partial prints NOTHING
    -- not a space -- so the English shop is byte for byte what it was. Choosing
    العربية clears the "open English" choice the translate hand-off remembers
    (resources/js/kbb/translate-away.js clears it on any click to /ar).
--}}@php
    $kbbLsPath = '/' . ltrim((string) request()->path(), '/');
    if ($kbbLsPath !== '/' && ! str_contains(basename($kbbLsPath), '.')) { $kbbLsPath = rtrim($kbbLsPath, '/') . '/'; }
    try { $kbbLsAlts = \App\Support\Seo::readerAlternatePaths($kbbLsPath); } catch (\Throwable) { $kbbLsAlts = []; }
    $kbbLsNames = ['en' => 'English', 'ar' => 'العربية'];
    $kbbLsHere = \App\Support\Locale::current();
    $kbbLsHtml = '';
    if (count($kbbLsAlts) > 1) {
        foreach ($kbbLsAlts as $kbbLsCode => $kbbLsAlt) {
            if (! isset($kbbLsNames[$kbbLsCode])) { continue; }
            $kbbLsHtml .= $kbbLsCode === $kbbLsHere
                ? '<span lang="' . e($kbbLsCode) . '" aria-current="true">' . e($kbbLsNames[$kbbLsCode]) . '</span>'
                : '<a href="' . e(\App\Support\Url::raw($kbbLsAlt)) . '" lang="' . e($kbbLsCode) . '" hreflang="' . e($kbbLsCode) . '">' . e($kbbLsNames[$kbbLsCode]) . '</a>';
        }
    }
@endphp{{-- every piece above is e()-escaped; the names are constants --}}@if ($kbbLsHtml !== '')<nav class="kbb-lang"><span class="kbb-lang-c">{!! $kbbLsHtml !!}</span></nav>@endif