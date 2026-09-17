{{--
    Reviews — ported verbatim from SR_Frontend::render() in
    dream-code-reviews/includes/class-sr-frontend.php.

    This replaces the theme's own review markup. The live site runs the plugin,
    which takes over the product page's review section entirely, and its 13KB
    stylesheet targets these sr-* classes. My earlier port used the theme's
    .rcard / .rgrid, so almost none of the review CSS applied — that is what made
    the section look broken.

    Classes, element order, inline styles and the JSON data block are the
    plugin's. Only the data expressions are translated to Eloquent.
--}}
@php
    /*
     * Read through App\Support\ReviewSettings rather than $settings->get()
     * with a default typed out beside each key.
     *
     * These seven keys were read HERE and written NOWHERE — no seeder, no
     * migration, no controller, no screen — because Review Settings was an
     * iframe to a file this repo has never shipped. Now that there is a screen
     * writing them, the defaults have to live in exactly one place or the
     * screen and the page can disagree about what "not set" means. Every value
     * below is identical to the literal this block used to carry; the clamping
     * is new and applies on read, so a hand-edited row cannot put the section
     * outside the range the screen would allow.
     */
    $sr = \App\Support\ReviewSettings::all($settings);

    $showStars = $sr['sr_show_stars'];
    $showTabs  = $sr['sr_show_tabs'];
    $allowSub  = $sr['sr_allow_submit'];
    $showDate  = $sr['sr_show_date'];
    $cols      = $sr['sr_grid_cols'];
    $allowPhotos = $sr['sr_allow_photos'];
    $maxPhotos = $sr['sr_max_photos'];
    $emptyText = $sr['sr_empty_text'];

    $star = fn (int $n) => implode('', array_map(
        fn ($i) => '<span class="' . ($i <= $n ? 'f' : '') . '">★</span>',
        range(1, 5)
    ));
@endphp

<section class="sr{{ $showStars ? '' : ' sr-nostars' }}" id="sr" style="--sr-cols:{{ $cols }}" data-product="{{ $product->id }}">
    <div class="sr-head"><div class="sr-eyebrow">{{ __('store.reviews.eyebrow') }}</div><h2 class="sr-title">{{ __('store.reviews.heading') }}</h2></div>

    <div class="sr-summary">
        @if ($showStars)
        <div class="sr-score">
            <div class="sr-avg">{{ number_format($summary['average'], 1) }}</div>
            <div class="sr-avg-stars">{!! $star((int) round($summary['average'])) !!}</div>
            <div class="sr-count">{{ trans_choice('store.reviews.review_count', (int) $summary['total']) }}</div>
        </div>
        <div class="sr-bars">
            @foreach ($summary['bars'] as $starVal => $bar)
                <div class="sr-bar-row"><span class="sr-bl">{{ $starVal }}★</span><span class="sr-bar"><span class="sr-bf" style="width:{{ $bar['pct'] }}%"></span></span><span class="sr-bc">{{ $bar['n'] }}</span></div>
            @endforeach
        </div>
        @else
        <div class="sr-score" style="flex:1"><div class="sr-count">{{ trans_choice('store.reviews.review_count', (int) $summary['total']) }}</div></div>
        @endif
    </div>

    @if ($allowSub)<button class="sr-write" type="button" data-sr-open>{{ __('store.reviews.write_button') }}</button>@endif

    @if ($showTabs)
    <div class="sr-filters">
        <button class="sr-chip on" data-f="all">{{ __('store.reviews.filter_all') }}</button>
        <button class="sr-chip" data-f="5">5★</button>
        <button class="sr-chip" data-f="4">4★</button>
        <button class="sr-chip" data-f="photos">{{ __('store.reviews.filter_with_photos') }}</button>
    </div>
    @endif

    <div class="sr-grid">
        @forelse ($reviews as $r)
            @php
                $imgs = is_array($r->images) ? array_values(array_filter($r->images)) : [];
                $nph  = count($imgs);
                $ini  = mb_strtoupper(mb_substr((string) $r->author_name, 0, 1));
                $date = ($showDate && $r->created_at) ? $r->created_at->format('d M Y') : '';
            @endphp
            <article class="sr-card{{ $r->verified ? ' sr-isvf' : '' }}" data-rating="{{ (int) $r->rating }}" data-photos="{{ $nph ? '1' : '0' }}">
                <div class="sr-ct">
                    <span class="sr-av">{{ $ini }}</span>
                    <div class="sr-cmeta">
                        <span class="sr-nm">{{ $r->author_name }}@if ($r->verified) <span class="sr-verified">{{ __('store.reviews.verified_badge') }}</span>@endif</span>
                        <span class="sr-cs">{!! $star((int) $r->rating) !!}</span>
                    </div>
                </div>
                @if ($r->title)<h4 class="sr-h">{{ $r->title }}</h4>@endif
                <p class="sr-tx">{{ $r->content }}</p>
                {{-- ?? null, because the demo reviews are fixture objects with no such
                     property and a plain object throws on one it does not have. --}}
                @if ($r->reply ?? null)
                    <div class="sr-reply"><b>{{ __('store.reviews.reply_from') }}</b> {{ $r->reply }}</div>
                @endif
                @if ($nph)
                    <div class="sr-pp {{ 1 === $nph ? 'one' : 'multi' }}"><span class="sr-pc">📷 {{ $nph }}</span>@foreach (array_slice($imgs, 0, 4) as $idx => $u)@php $more = ($nph > 4 && 3 === $idx) ? $nph - 4 : 0; @endphp<span class="sr-ph"@if ($more) data-more="+{{ $more }}"@endif><img src="{{ $u }}" alt="" loading="lazy"></span>@endforeach</div>
                @endif
                <div class="sr-cf"><span class="sr-dt">{{ $date }}</span>@if ($r->demo ?? false)<span class="sr-help sr-help-demo">👍 <span>{{ (int) $r->helpful }}</span></span>@else<button class="sr-help" data-id="{{ $r->id }}" type="button">👍 <span>{{ (int) $r->helpful }}</span></button>@endif</div>

                {{-- Full data for the popup.
                     The array is built above and passed as a single variable:
                     a multi-line array literal written inline in the directive
                     does not survive Blade's parser and compiles to invalid
                     PHP, taking the whole page down. NOTE: do not name Blade
                     directives inside comments — raw blocks are extracted
                     before comments are stripped, so a directive named here is
                     treated as real code. --}}
                @php
                    $srData = [
                        'name' => $r->author_name,
                        'ini' => $ini,
                        'vf' => (int) $r->verified,
                        'rate' => (int) $r->rating,
                        'date' => $date,
                        'title' => $r->title,
                        'text' => $r->content,
                        'imgs' => $imgs,
                    ];
                @endphp
                <script type="application/json" class="sr-data">@json($srData)</script>
            </article>
        @empty
            <div class="sr-empty">{{ $emptyText }}</div>
        @endforelse
    </div>

    @if ($reviews->count() > 4)<button class="sr-more" data-sr-more type="button">{{ __('store.reviews.load_more') }}</button>@endif

    {{-- detail popup --}}
    <div class="sr-modal" data-sr-modal hidden><div class="sr-mx-scrim" data-sr-mclose></div><div class="sr-mcard"><button class="sr-mx" type="button" data-sr-mclose aria-label="{{ __('store.reviews.close_label') }}">&times;</button><div class="sr-mbody" data-sr-mbody></div></div></div>

    @if ($allowSub)
    <div class="sr-sheet" data-sr-sheet hidden>
        <div class="sr-sc" data-sr-close></div>
        <div class="sr-scard">
            <div class="sr-grab"></div>
            <button class="sr-sx" type="button" data-sr-close aria-label="{{ __('store.reviews.close_label') }}">&times;</button>
            <h3 class="sr-stitle">{{ __('store.reviews.form_heading') }}</h3>
            <form class="sr-form" data-sr-form enctype="multipart/form-data">
                @csrf
                <div class="sr-fld"><label>{{ __('store.reviews.field_rating') }}</label><div class="sr-pick" data-sr-stars>@for ($i = 1; $i <= 5; $i++)<span data-v="{{ $i }}">★</span>@endfor</div><input type="hidden" name="rating" value="0" data-sr-rating></div>
                <div class="sr-r2"><div class="sr-fld"><label>{{ __('store.reviews.field_name') }}</label><input type="text" name="author_name" required maxlength="100" placeholder="{{ __('store.reviews.field_name_placeholder') }}"></div><div class="sr-fld"><label>{{ __('store.reviews.field_email') }} <small>{{ __('store.reviews.field_email_note') }}</small></label><input type="email" name="author_email" required maxlength="120" placeholder="{{ __('store.reviews.field_email_placeholder') }}"></div></div>
                <div class="sr-fld"><label>{{ __('store.reviews.field_title') }}</label><input type="text" name="title" maxlength="120" placeholder="{{ __('store.reviews.field_title_placeholder') }}"></div>
                <div class="sr-fld"><label>{{ __('store.reviews.field_review') }}</label><textarea name="content" rows="4" required placeholder="{{ __('store.reviews.field_review_placeholder') }}"></textarea></div>
                @if ($allowPhotos)
                <div class="sr-fld"><label>{{ __('store.reviews.field_photos') }} <small>{{ trans_choice('store.reviews.field_photos_hint', (int) $maxPhotos) }}</small></label><label class="sr-up"><input type="file" name="sr_photos[]" accept="image/png,image/jpeg,image/webp" multiple hidden data-sr-file>{{ __('store.reviews.photos_cta') }}</label><div class="sr-pv" data-sr-previews></div></div>
                @endif
                <div class="sr-fld sr-cap"><label>{{ __('store.reviews.field_captcha') }} <span data-sr-question>…</span></label><input type="text" name="captcha" inputmode="numeric" required placeholder="{{ __('store.reviews.field_captcha_placeholder') }}" autocomplete="off"><input type="hidden" name="captcha_token" data-sr-token></div>
                <div class="sr-hp"><input type="text" name="sr_website" tabindex="-1" autocomplete="off"></div>
                <button type="submit" class="sr-submit">{{ __('store.reviews.submit') }}</button>
                <div class="sr-msg" data-sr-msg></div>
            </form>
        </div>
    </div>
    @endif
</section>
