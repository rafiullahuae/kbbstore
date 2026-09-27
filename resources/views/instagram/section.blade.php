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
                        @if ($profile['followers'] !== null){{ trans_choice('store.instagram.followers', $profile['followers'], ['formatted' => number_format($profile['followers'])]) }}@endif
                        @if ($profile['followers'] !== null && $profile['posts'] !== null) · @endif
                        @if ($profile['posts'] !== null){{ trans_choice('store.instagram.posts', $profile['posts'], ['formatted' => number_format($profile['posts'])]) }}@endif
                    </span>
                @endif
            </div>
            @if (($profile['checked'] ?? null) !== null)
                <a class="igp-pf" href="{{ $profile['checked'] }}" target="_blank" rel="noopener nofollow">{{ __('store.instagram.follow') }}</a>
            @endif
        </div>
    @endif

    <div class="igp-t is-{{ $layout }}">
        @if ($drawInline)
            {{-- `inline`: the avatar becomes the first cell, and it inherits the same
                 square crop from .igp-c as everything beside it.

                 ── AND IT IS ONLY A LINK WHEN THERE IS SOMEWHERE TO GO ────────
                 This used to be `href="{{ $profile['checked'] ?? '#' }}"`, which is a
                 dead anchor — a control that lies about being one, which this file's
                 own tile comment forbids twenty lines below. `checked` is null
                 whenever the stored username failed InstagramFeed's handle alphabet,
                 so the case is reachable rather than theoretical. Drawn as a plain
                 cell instead: the avatar still shows, and nothing pretends to be
                 clickable. --}}
            @if (($profile['checked'] ?? null) !== null)
            <a class="igp-c" href="{{ $profile['checked'] }}" target="_blank" rel="noopener nofollow">
                <img src="{{ $profile['avatar'] }}"
                     alt="{{ $profile['name'] !== '' ? $profile['name'] : $profile['username'] }}"
                     width="152" height="152" loading="lazy" decoding="async">
            </a>
            @else
            <div class="igp-c">
                <img src="{{ $profile['avatar'] }}"
                     alt="{{ $profile['name'] !== '' ? $profile['name'] : $profile['username'] }}"
                     width="152" height="152" loading="lazy" decoding="async">
            </div>
            @endif
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
                $href = $embed !== null ? ($tile['permalink'] ?? $embed) : ($tap !== 'nothing' ? $tile['permalink'] : null);
                $hasCounts = $showCounts && ($tile['likes'] !== null || $tile['comments'] !== null);
                $label = $tile['caption'] !== '' ? \Illuminate\Support\Str::limit($tile['caption'], 90) : __('store.instagram.post_alt');
            @endphp
            {{--
                ── THE CELL IS ALWAYS A <div> AND THE LINK IS INSIDE IT ──────────

                This used to be an opening tag whose NAME was an echo of $element,
                computed as 'a' or 'div', with the closing tag written the same way.
                Two things were wrong with it and only one of them was cosmetic:

                  · StorefrontStringsAreKeyedTest reads every storefront Blade with
                    BladeProse to find English a shopper reads that does not go
                    through __(). A tag whose NAME is an echo is not a tag to that
                    reader, so the whole opening tag was scanned as a TEXT NODE and
                    reported as three separate untranslated strings — `class="igp-c`,
                    `href="..." data-ig-embed="..."` and the rel/target pair. It was
                    red on the first suite run this lane made.
                  · and an element whose tag name is a variable is an element whose
                    open and close can disagree. They cannot here, because both read
                    the same variable — but the next person to add a branch is one
                    edit away from `<a>…</div>`.

                So: one <div class="igp-c"> in every case, with a STRETCHED <a>
                inside it when there is somewhere to go. That keeps the property
                this file's header claims — one tile markup, one set of escaping,
                so a layout cannot be the one that drops it — and it keeps the
                "never a dead anchor" rule, because with no permalink and no embed
                there is simply no <a> element at all.

                The <a> is LAST so `.igp-c:focus-within` can light the caption
                without a sibling selector that depends on source order, and it is
                empty with an aria-label rather than wrapping the picture: wrapping
                would put the counts and the caption inside the link, so a screen
                reader would read the whole overlay as the link's name.
            --}}
            <div class="igp-c{{ $showPlay && $tile['video'] ? ' is-video' : '' }}{{ $tile['carousel'] ? ' is-album' : '' }}">
                <img src="{{ $tile['image'] }}"
                     alt="{{ $label }}"
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
                                {{ number_format($tile['likes']) }}<span class="igp-sr">&nbsp;{{ __('store.ugc.likes_label') }}</span>
                            </span>
                        @endif
                        @if ($tile['comments'] !== null)
                            <span>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 11.5A8.4 8.4 0 0 1 12 20a9 9 0 0 1-4-.9L3 20l1.3-3.8A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/></svg>
                                {{ number_format($tile['comments']) }}<span class="igp-sr">&nbsp;{{ __('store.ugc.comments_label') }}</span>
                            </span>
                        @endif
                    </span>
                @endif

                @if ($href !== null)
                    {{-- data-ig-embed is what the script reads on a tap. It is a
                         constant with a shortcode in it that matched
                         InstagramPost::SHORTCODE_RE; see the assets file. With the
                         `embed` tap chosen the href is STILL the permalink, so a
                         shopper whose JavaScript never ran gets the real post
                         rather than a bare iframe URL. --}}
                    <a class="igp-lk" href="{{ $href }}"
                       @if ($embed !== null) data-ig-embed="{{ $embed }}" @else target="_blank" rel="noopener nofollow" @endif
                       aria-label="{{ $tile['caption'] !== '' ? $label : __('store.instagram.open_post') }}"></a>
                @endif
            </div>
        @endforeach
    </div>
</section>
