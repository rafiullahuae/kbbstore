@php use App\Support\Url; @endphp

{{--
    T-CHROME-6/7/8/9. Structure comes from NavigationService, so the menu is
    editable instead of hard-coded in a template. Markup classes are the theme's
    (.mbar / .navitem / .drop.mega / .mcol / .feat) so the ported CSS styles it
    without modification.

    highlight_color, new_tab: added alongside the Mega Menu admin screen.
    Visibility (guest/auth) is already resolved before this template ever
    runs — StoreComposer filters it in, so nothing here has to know or care
    which items were hidden.

    columns: desktop-only. An admin can set an exact column count on any
    parent item; left unset, the panel works out its own count from how
    many children it has, roughly 10 per column, rather than every panel
    using the same fixed count regardless of size. A 3-item dropdown and a
    30-item one both need to look intentional, not like the same box
    stretched or squeezed to fit. Mobile has no equivalent of this at all —
    it's an expand/collapse list, not a column layout — so nothing here
    touches it.
--}}
@php
    /*
     * Store & content -> "Mega Menu" (module key mega_menu).
     *
     * WHAT THIS GATE HIDES, AND WHY IT IS BOTH LINES BELOW AND NOT ONE.
     *
     * The switch was marked `live` in ModuleRegistry while the ONLY code
     * anywhere that read its key was MegaMenuApiController's `module_on` — a
     * flag the admin screen prints about itself. This bar rendered its panels
     * whatever the switch said, which is the same defect `seo_engine` and
     * `product_sorting` both carried and both had fixed.
     *
     * Off hides the caret AND the panel. Hiding only the panel leaves a "▾"
     * pointing at nothing; hiding only the caret leaves a panel that still
     * drops on hover. The top-level links are untouched either way, so turning
     * the module off costs a shopper the dropdowns and nothing else — the
     * registry's own hover card ("the panels that drop from the category bar")
     * is the description this implements.
     *
     * Resolved ONCE here rather than per item: this loop runs for every row in
     * the primary menu, and moduleEnabled() is a container resolve plus a cache
     * read each time.
     *
     * Default `true`, matching the registry row, and 2026_10_21_000000 aligns
     * the stored toggle for installs seeded `false` before anything read it.
     * Without that pair, making the gate real would strip a live store's header
     * dropdowns the moment the package applied.
     */
    $kbbMega = app(\App\Services\SettingsService::class)->moduleEnabled('mega_menu', true);
@endphp
<div class="mbar"><div class="wrap">
    @foreach ($kbbNav as $item)
        <div class="navitem">
            <a class="navlink" href="{{ Url::to($item['url'] ?? '/') }}"
                @if (! empty($item['highlight_color'])) style="background:{{ $item['highlight_color'] }};color:#fff;border-radius:8px;padding:4px 10px" @endif
                @if (! empty($item['new_tab'])) target="_blank" rel="noopener" @endif>
                {{ $item['label'] }}
                @if (! empty($item['badge']))
                    <span class="npill" style="background:#15a85a">{{ $item['badge'] }}</span>
                @endif
                @if ($kbbMega && ! empty($item['children']))
                    <span class="ind">▾</span>
                @endif
            </a>

            @if ($kbbMega && ! empty($item['children']))
                @php
                    $childCount = count($item['children']);
                    // Manual setting wins outright; otherwise roughly 10 rows
                    // per column, rounded up, minimum one column.
                    $columnCount = max(1, (int) ($item['columns'] ?? ceil($childCount / 10)));
                    $chunkSize = (int) ceil($childCount / $columnCount);
                    $columns = array_chunk($item['children'], max(1, $chunkSize));
                @endphp

                <div class="drop{{ $columnCount > 1 ? ' mega' : '' }}" style="{{ $columnCount > 1 ? '--mega-cols:' . count($columns) : '' }}">
                    @if ($columnCount > 1)
                        @foreach ($columns as $column)
                            <div class="mcol-group">
                                @foreach ($column as $entry)
                                    @if (! empty($entry['children']))
                                        <div class="mcol">
                                            <div class="colh">{{ $entry['label'] }}</div>
                                            @foreach ($entry['children'] as $link)
                                                <a href="{{ Url::to($link['url'] ?? '/') }}"
                                                    @if (! empty($link['highlight_color'])) style="background:{{ $link['highlight_color'] }};color:#fff;border-radius:6px" @endif
                                                    @if (! empty($link['new_tab'])) target="_blank" rel="noopener" @endif>
                                                    @if (! empty($link['icon']))<span class="di">{{ $link['icon'] }}</span>@endif
                                                    <span>{{ $link['label'] }}</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @else
                                        {{-- No children of its own — a bare column header with
                                             nothing underneath looks broken, so this renders as
                                             a plain clickable link instead, sitting in the grid
                                             next to the real columns. Mixing populated and empty
                                             items in the same group is the normal case, not an
                                             edge case, so this has to hold up either way. --}}
                                        <a class="mcol-link" href="{{ Url::to($entry['url'] ?? '/') }}"
                                            @if (! empty($entry['highlight_color'])) style="background:{{ $entry['highlight_color'] }};color:#fff;border-radius:6px" @endif
                                            @if (! empty($entry['new_tab'])) target="_blank" rel="noopener" @endif>
                                            @if (! empty($entry['icon']))<span class="di">{{ $entry['icon'] }}</span>@endif
                                            <span>{{ $entry['label'] }}</span>
                                        </a>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    @else
                        @foreach ($item['children'] as $link)
                            <a href="{{ Url::to($link['url'] ?? '/') }}"
                                @if (! empty($link['highlight_color'])) style="background:{{ $link['highlight_color'] }};color:#fff;border-radius:6px" @endif
                                @if (! empty($link['new_tab'])) target="_blank" rel="noopener" @endif>
                                @if (! empty($link['icon']))<span class="di">{{ $link['icon'] }}</span>@endif
                                <span>{{ $link['label'] }}</span>
                            </a>
                        @endforeach
                    @endif
                </div>
            @endif
        </div>
    @endforeach
</div></div>
