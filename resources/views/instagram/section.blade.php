{{--
    Instagram Profile — the section. Phase 21, Lane IG.

    Reached from App\Support\Shortcodes::instagram() and from NOWHERE ELSE, which is
    worth saying because a grep for this file's name finds nothing: the shortcode
    renders it by view name, exactly as it does resources/views/ugc/rail.blade.php
    and components/product-grid.

    ── EVERYTHING PRINTED HERE CAME FROM INSTAGRAM ──────────────────────────

    Which makes every value on this page remote user input, and rule 5's demands
    concrete:

      · every string goes through {{ }}. The caption, the username, the name, the
        alt text. There is no unescaped echo in this file at all — the only two
        braces with a bang in them are in this sentence.
      · a stored media path is printed RAW and not through Url::to(), which is the
        sibling module's answer (resources/views/ugc/rail.blade.php prints
        $tile['poster'] the same way) and is the correct one: Url::to() adds the base
        path AND the locale segment, so an /uploads/... path through it becomes
        /ar/uploads/... on the Arabic shop and 404s. The path is root-relative and the
        file is under the web root, which is what IgPath::directory() guarantees.
      · every URL was checked BEFORE it arrived here, at the boundary, not here:
        `permalink` through UgcPath::link() plus a host check in InstagramSync,
        `image` and `avatar` through IgPath::stored(), `embed` rebuilt from a
        shortcode that matched InstagramPost::SHORTCODE_RE. A value that failed is
        null and this template draws no element for it — which for `image` cannot
        happen, because InstagramFeed drops a tile with no picture.
      · the only thing printed unescaped anywhere is the inline SVG in the icons
        below, and each one is a literal in this file.

    ── AND NO NUMBER IS INVENTED ────────────────────────────────────────────

    docs/UGC-ENGAGEMENT.md's rule, and it is a `!== null` rather than a truthiness
    test on purpose: "A reel posted an hour ago genuinely has zero comments. Hiding
    an honest zero is the same defect pointed the other way." So 0 prints as 0 and
    null draws nothing at all — and if NEITHER number is known, the whole metrics
    element is absent rather than being an empty gradient over the picture.

    ── AND NOTHING HERE IS SIZED BY JAVASCRIPT ──────────────────────────────

    Rule 4. `width` and `height` are the REAL pixel dimensions of our own local file,
    read with getimagesize() at download time, so the browser reserves the right box
    from the first byte and layout shift is zero without anything being measured.
    `aspect-ratio` in the stylesheet does the cropping.
--}}
@php
    /** @var list<array<string, mixed>> $tiles */
    /** @var array<string, mixed> $profile */
    /** @var array<string, mixed> $conf */

    $layout = isset(\App\Services\InstagramSettings::LAYOUTS[(string) ($conf['layout'] ?? '')])
        ? (string) $conf['layout']
        : 'grid';

    $profileStyle = isset(\App\Services\InstagramSettings::PROFILE_STYLES[(string) ($conf['profile_style'] ?? '')])
        ? (string) $conf['profile_style']
        : 'card';

    $tap = isset(\App\Services\InstagramSettings::TAPS[(string) ($conf['tap'] ?? '')])
        ? (string) $conf['tap']
        : 'permalink';

    $showCounts = (bool) ($conf['counts'] ?? true);
    $showCaption = (bool) ($conf['caption'] ?? false);
    $showPlay = (bool) ($conf['play_badge'] ?? true);

    // An author-supplied heading wins; an explicitly empty one draws none. That is
    // why this is a null check and not a `?:` — '' from the shortcode is a decision.
    $heading = $headingOverride ?? (string) ($conf['heading'] ?? '');

    // The profile box is drawn only when there is a profile AND a style asking for
    // one. `inline` puts the avatar in the grid instead, so it draws no box either.
    $hasProfile = $profile !== [] && ($profile['avatar'] ?? null) !== null;
    $drawBox = $hasProfile && in_array($profileStyle, ['card', 'bar'], true);
    $drawInline = $hasProfile && $profileStyle === 'inline';
@endphp
@if ($withAssets)
    @include('instagram.assets')
@endif
<section class="igp" style="{{ \App\Services\InstagramSettings::cssVariables($conf) }}">
    @if ($heading !== '' || ($profile['checked'] ?? null) !== null)
        <div class="igp-h">
            @if ($heading !== '')<h2>{{ $heading }}</h2>@endif
            @if (($profile['checked'] ?? null) !== null)
                {{-- rel="noopener" is not optional on a target="_blank": without it the
                     opened page gets a handle on this one through window.opener. --}}
                <a href="{{ $profile['checked'] }}" target="_blank" rel="noopener nofollow">{{ '@' . $profile['username'] }}</a>
            @endif
        </div>
    @endif

    @if ($drawBox)
        <div class="igp-p is-{{ $profileStyle }}">
            {{-- Our own file, with its own dimensions. loading="lazy" on a profile
                 avatar is deliberate even though it is near the top: this section is
                 almost never above the fold, and a shop that puts it there loses
                 nothing measurable to one lazy 76px image. --}}
            <img src="{{ $profile['avatar'] }}"
                 alt="{{ $profile['name'] !== '' ? $profile['name'] : $profile['username'] }}"
                 width="152" height="152" loading="lazy" decoding="async">
            <div class="igp-pn">
                <b>{{ $profile['name'] !== '' ? $profile['name'] : '@' . $profile['username'] }}</b>
                {{-- A count is printed only when we HAVE one. `!== null`, so an
                     account with genuinely zero followers reads "0 followers"
                     rather than nothing. --}}
                @if ($profile['followers'] !== null || $profile['posts'] !== null)
                    <span>
                        @if ($profile['followers'] !== null){{ number_format($profile['followers']) }} followers@endif
                        @if ($profile['followers'] !== null && $profile['posts'] !== null) · @endif
                        @if ($profile['posts'] !== null){{ number_format($profile['posts']) }} posts@endif
                    </span>
                @endif
            </div>
            @if (($profile['checked'] ?? null) !== null)
                <a class="igp-pf" href="{{ $profile['checked'] }}" target="_blank" rel="noopener nofollow">Follow</a>
            @endif
        </div>
    @endif

    <div class="igp-t is-{{ $layout }}">
        @if ($drawInline)
            {{-- `inline`: the avatar becomes the first cell. A LINK and not a tile,
                 so it is obvious what it does, and it inherits the same square crop
                 from .igp-c as everything beside it. --}}
            <a class="igp-c" href="{{ $profile['checked'] ?? '#' }}" target="_blank" rel="noopener nofollow">
                <img src="{{ $profile['avatar'] }}"
                     alt="{{ $profile['name'] !== '' ? $profile['name'] : $profile['username'] }}"
                     width="152" height="152" loading="lazy" decoding="async">
            </a>
        @endif

        @foreach ($tiles as $tile)
            @php
                /*
                 * WHAT A TAP DOES, decided once per tile rather than in four places
                 * below. `embed` needs a shortcode we could parse; without one it
                 * falls back to the permalink, and without that the tile is not a
                 * link at all — never a dead <a href="#">, which is a control that
                 * lies about being one.
                 */
                $embed = $tap === 'embed' ? $tile['embed'] : null;
                $href = $embed === null && $tap !== 'nothing' ? $tile['permalink'] : null;
                $element = $embed !== null || $href !== null ? 'a' : 'div';
                $hasCounts = $showCounts && ($tile['likes'] !== null || $tile['comments'] !== null);
            @endphp
            <{{ $element }} class="igp-c{{ $showPlay && $tile['video'] ? ' is-video' : '' }}{{ $tile['carousel'] ? ' is-album' : '' }}"
                @if ($embed !== null)
                    {{-- The script reads this and sets an iframe src on TAP. It is a
                         constant with a checked shortcode in it; see the assets file. --}}
                    href="{{ $tile['permalink'] ?? $embed }}" data-ig-embed="{{ $embed }}"
                @elseif ($href !== null)
                    href="{{ $href }}" target="_blank" rel="noopener nofollow"
                @endif
            >
                <img src="{{ $tile['image'] }}"
                     alt="{{ $tile['caption'] !== '' ? \Illuminate\Support\Str::limit($tile['caption'], 90) : 'Instagram post' }}"
                     @if ($tile['width'] !== null && $tile['height'] !== null)
                         width="{{ $tile['width'] }}" height="{{ $tile['height'] }}"
                     @endif
                     loading="lazy" decoding="async">

                @if ($tile['carousel'])
                    {{-- A literal in this file, which is what makes printing it
                         unescaped correct: rule 5's "anything printed unescaped is a
                         constant, never a setting". --}}
                    <svg class="igp-alb" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                        <rect x="8" y="3" width="13" height="13" rx="2"/><path d="M3 8v11a2 2 0 0 0 2 2h11"/>
                    </svg>
                @endif

                @if ($showCaption && $tile['caption'] !== '')
                    <span class="igp-cap"><span>{{ $tile['caption'] }}</span></span>
                @endif

                @if ($hasCounts)
                    <span class="igp-m">
                        @if ($tile['likes'] !== null)
                            <span>
                                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 21s-7.5-4.6-9.3-9A5.3 5.3 0 0 1 12 6.5 5.3 5.3 0 0 1 21.3 12c-1.8 4.4-9.3 9-9.3 9z"/></svg>
                                {{ number_format($tile['likes']) }}<span class="igp-sr">&nbsp;likes</span>
                            </span>
                        @endif
                        @if ($tile['comments'] !== null)
                            <span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 11.5A8.4 8.4 0 0 1 12 20a9 9 0 0 1-4-.9L3 20l1.3-3.8A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/></svg>
                                {{ number_format($tile['comments']) }}<span class="igp-sr">&nbsp;comments</span>
                            </span>
                        @endif
                    </span>
                @endif
            </{{ $element }}>
        @endforeach
    </div>
</section>
