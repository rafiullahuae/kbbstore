{{--
    One menu row, recursing into its children.

    A branch with children becomes an expandable section; a leaf is a link.
    Children beyond the first level are laid out in the configured number of
    columns, which is what keeps 65 brands to a manageable height.
--}}
@php
    // Three levels is the deepest the design goes; beyond that a row renders
    // as a plain link. Without this, a menu row that somehow references its own
    // parent would recurse until the request dies.
    $children = $depth >= 2 ? [] : ($item['children'] ?? []);
    $hasKids = ! empty($children);
    $label = $item['label'] ?? '';
    $url = \App\Support\Url::to($item['url'] ?? '/');
    $hot = ! empty($item['badge']) || str_contains(mb_strtolower($label), 'sale');
    // Grandchildren keep their own rows; only leaf lists become columns.
    $leafOnly = $hasKids && collect($children)->every(fn ($c) => empty($c['children']));
@endphp

@if (! $hasKids)
    <a class="mm-it{{ $hot ? ' hot' : '' }}" href="{{ $url }}"
        @if (! empty($item['highlight_color'])) style="background:{{ $item['highlight_color'] }};color:#fff" @endif
        @if (! empty($item['new_tab'])) target="_blank" rel="noopener" @endif>{{ $label }}</a>
@else
    <div class="mm-node" data-depth="{{ $depth }}">
        <button class="mm-it mm-par" type="button"
            @if (! empty($item['highlight_color'])) style="background:{{ $item['highlight_color'] }};color:#fff" @endif>
            {{ $label }}
            <span class="mm-ct">{{ count($children) }}</span>
            <span class="mm-car" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg></span>
        </button>

        <div class="mm-kid">
            @if ($leafOnly)
                <div class="mm-c2">
                    @foreach ($children as $child)
                        <a class="mm-si{{ ! empty($child['badge']) ? ' hot' : '' }}" href="{{ \App\Support\Url::to($child['url'] ?? '/') }}"
                            @if (! empty($child['highlight_color'])) style="background:{{ $child['highlight_color'] }};color:#fff" @endif
                            @if (! empty($child['new_tab'])) target="_blank" rel="noopener" @endif>{{ $child['label'] ?? '' }}</a>
                    @endforeach
                </div>
            @else
                {{-- A mostly-flat group (brands, categories) stays a two-column
                     grid even once one item grows its own sub-menu — the whole
                     group used to fall back to a plain single-column list the
                     moment any one child had children, losing the grid for
                     every sibling too. Now each child renders as a grid cell
                     if it's a leaf, or as its own expandable row (spanning
                     both columns, with its own nested bar) if it has kids. --}}
                <div class="mm-c2">
                    @foreach ($children as $child)
                        @if (empty($child['children']))
                            <a class="mm-si{{ ! empty($child['badge']) ? ' hot' : '' }}" href="{{ \App\Support\Url::to($child['url'] ?? '/') }}"
                                @if (! empty($child['highlight_color'])) style="background:{{ $child['highlight_color'] }};color:#fff" @endif
                                @if (! empty($child['new_tab'])) target="_blank" rel="noopener" @endif>{{ $child['label'] ?? '' }}</a>
                        @else
                            @include('partials.mobile-menu-item', ['item' => $child, 'depth' => $depth + 1])
                        @endif
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endif
