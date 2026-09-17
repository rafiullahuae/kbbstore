@extends('layouts.store')
{{-- $pageTitle is computed in Store\RoutineController, not here.

     Not a style choice: Tests\Support\BladeProse blanks a directive and its
     arguments with a regex that matches at most three levels of nested
     brackets, and the expression this used to be — a ternary around a __() with
     an array argument around a second __() around a static call — is four. Past
     that depth the scanner stops seeing a directive and reads the REST OF THE
     LINE as prose a shopper reads, so StorefrontStringsAreKeyedTest fails on a
     line that is nothing but __() calls. The scanner is right to be simple and
     the fix is to keep a @section argument short. --}}
@section('title', $pageTitle)

{{--
    Phase 10 — Build my routine. /routines and /routines/{concern} (Lane FM).

    ONE FILE, TWO MODES, keyed on $routine — the same shape
    store/brands.blade.php uses for the directory and one brand's page, and for
    the same reason: the two share the breadcrumb, the heading block and every
    line of the stylesheet below, and splitting them would mean maintaining that
    CSS twice.

    ── WHAT MAY APPEAR ON THIS PAGE ────────────────────────────────────────────

    Nothing this file can write. Every product name, brand, price and photograph
    comes from a `products` row through the shop's own <x-product-card>, which
    is the same component the shop page and the home rails draw. There is no
    fixture here, no fallback product, no placeholder price and no saving.

    That is not a style preference. The previous lane deleted seventeen invented
    products, their invented prices and a 15% "bundle saving" from the skin
    quiz, and the rule it left behind is the rule this page is built to:
    recommend a real row or recommend a shape and link to the shop. A step this
    shop stocks nothing for prints the "not stocked yet" block — it is never
    quietly dropped, because four steps drawn as four steps look exactly like a
    four-step routine and nobody would ever find out.

    The TOTAL is arithmetic over those same rows and nothing else. It states
    which steps it covers and adds no discount; App\Support\WholeDirhams is the
    shop's money policy and Money::format() is what obeys it.

    ── NO JAVASCRIPT, DELIBERATELY ─────────────────────────────────────────────

    "Manual selection" — swapping a step — is an ordinary link carrying
    ?<role>=<product-slug>, exactly as /reviews does its filtering. So every
    state of this page has a real URL that can be shared, bookmarked, opened on
    another device, crawled and fetched in a test, and no state of it can render
    a product the server did not send. The <details> element opens the swap list
    with no script at all.

    ── CLASS PREFIX ────────────────────────────────────────────────────────────

    Every class here is `rtn-` and appears nowhere else in the storefront. The
    layout, the header, the cart panel and the product card are all on this page
    and all bring their own class names; a short prefix would restyle one of
    them from a distance, which is a defect the admin console has already paid
    for once.
--}}

@push('styles')
<style>
  .rtn-wrap{max-width:1080px;margin:0 auto;padding:22px 18px 72px;color:var(--ink,#2A2228)}
  .rtn-crumb{font-size:12px;color:var(--muted,#8C828A);margin-bottom:16px}
  .rtn-crumb a{color:inherit;text-decoration:none}
  .rtn-crumb a:hover{text-decoration:underline}
  .rtn-crumb span{margin:0 6px}
  .rtn-head h1{font-size:30px;line-height:1.15;letter-spacing:-.02em;margin:0 0 8px}
  .rtn-head p{margin:0;color:var(--ink-2,#5E545A);font-size:14.5px;max-width:640px;line-height:1.55}
  .rtn-offer{margin:20px 0 4px;padding:13px 16px;border:1px solid var(--blush,#FCE0E8);background:var(--pink-soft,#FFF0F4);border-radius:14px;display:flex;flex-wrap:wrap;gap:6px 14px;align-items:baseline}
  .rtn-offer b{font-size:14px}
  .rtn-offer small{font-size:12px;color:var(--ink-2,#5E545A)}
  .rtn-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px;margin-top:22px}
  .rtn-card{border:1px solid var(--line,rgba(42,34,40,.12));border-radius:16px;background:#fff;padding:18px;display:flex;flex-direction:column;gap:10px}
  .rtn-card h2{margin:0;font-size:17px;letter-spacing:-.01em}
  .rtn-card p{margin:0;font-size:13px;color:var(--ink-2,#5E545A);line-height:1.5}
  .rtn-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:2px}
  .rtn-chip{font-size:11px;padding:4px 9px;border-radius:99px;background:var(--cream,#FFF8F5);border:1px solid var(--line,rgba(42,34,40,.12));color:var(--ink-2,#5E545A)}
  .rtn-go{margin-top:auto;align-self:flex-start;font-size:13px;font-weight:600;text-decoration:none;color:var(--pink-deep,#C13E63);border-bottom:1px solid currentColor;padding-bottom:1px}
  .rtn-steps{margin-top:26px;display:flex;flex-direction:column;gap:16px}
  .rtn-step{border:1px solid var(--line,rgba(42,34,40,.12));border-radius:16px;background:#fff;overflow:hidden}
  .rtn-step-h{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border-bottom:1px solid var(--line,rgba(42,34,40,.12));background:var(--cream,#FFF8F5)}
  .rtn-n{flex:0 0 auto;width:26px;height:26px;border-radius:99px;background:var(--pink,#E0567B);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center}
  .rtn-step-h h3{margin:0;font-size:15px;letter-spacing:-.01em}
  .rtn-step-h .rtn-help{margin:2px 0 0;font-size:12.5px;color:var(--ink-2,#5E545A);line-height:1.45}
  .rtn-body{padding:16px;display:grid;grid-template-columns:minmax(0,220px) minmax(0,1fr);gap:16px;align-items:start}
  .rtn-body>*{min-width:0}
  .rtn-gap{padding:16px}
  .rtn-gap b{display:block;font-size:13.5px;margin-bottom:4px}
  .rtn-gap p{margin:0 0 10px;font-size:12.5px;color:var(--ink-2,#5E545A);line-height:1.5}
  .rtn-swap>b{display:block;font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--muted,#8C828A)}
  .rtn-alts{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-direction:column;gap:8px}
  .rtn-alts li{display:flex;flex-wrap:wrap;gap:4px 10px;align-items:baseline;font-size:13px;border-top:1px solid var(--line,rgba(42,34,40,.12));padding-top:8px;line-height:1.45}
  .rtn-alts li:first-child{border-top:0;padding-top:0}
  .rtn-alts .rtn-b{color:var(--muted,#8C828A);font-size:11.5px}
  .rtn-alts a{color:var(--pink-deep,#C13E63);font-weight:600;text-decoration:none}
  .rtn-alts em{font-style:normal;color:var(--muted,#8C828A);font-size:11.5px}
  .rtn-foot{margin-top:22px;padding:16px;border:1px solid var(--line,rgba(42,34,40,.12));border-radius:16px;background:#fff;display:flex;flex-wrap:wrap;gap:6px 16px;align-items:baseline}
  .rtn-foot b{font-size:15px}
  .rtn-foot small{font-size:12px;color:var(--ink-2,#5E545A)}
  .rtn-foot .rtn-reset{margin-left:auto;font-size:12.5px;color:var(--pink-deep,#C13E63)}
  .rtn-empty{margin-top:26px;border:1px dashed var(--line,rgba(42,34,40,.12));border-radius:16px;padding:28px;text-align:center;background:#fff}
  .rtn-empty h2{margin:0 0 8px;font-size:18px}
  .rtn-empty p{margin:0 auto 14px;max-width:460px;font-size:13.5px;color:var(--ink-2,#5E545A);line-height:1.55}
  @media (max-width:620px){
    .rtn-head h1{font-size:24px}
    .rtn-body{grid-template-columns:minmax(0,1fr)}
  }
</style>
@endpush

@section('content')
@php
    use App\Support\Money;
    use App\Support\RoutineConcerns;
    use App\Support\RoutineRoles;
    use App\Support\Url;

    $listUrl = Url::to('/routines/');
    $shopUrl = Url::to('/shop/');

    /** The concern's name, in the shopper's language. */
    $concernName = fn (string $slug) => __(RoutineConcerns::labelKey($slug));

    /** The routine's heading: what the owner typed, or the keyed default. */
    $routineTitle = fn (array $r) => $r['own_title']
        ?? __('store.routines.title_for', ['concern' => $concernName($r['concern'])]);

    $routineBlurb = fn (array $r) => $r['own_blurb']
        ?? __('store.routines.blurb_for', ['concern' => mb_strtolower($concernName($r['concern']))]);

    /**
     * The address of this routine with one step swapped.
     *
     * Built from the swaps that are ACTUALLY in effect rather than from the
     * incoming query string, so a link carrying a sold-out slug does not
     * propagate it into every other swap link on the page.
     */
    $swapUrl = function (array $r, string $role, string $slug) {
        $params = [];

        foreach ($r['steps'] as $step) {
            if ($step['chosen'] === null) {
                continue;
            }

            $value = $step['role'] === $role ? $slug : ($step['swapped'] ? $step['chosen']->slug : null);

            if ($value !== null) {
                $params[$step['role']] = $value;
            }
        }

        return Url::to('/routines/'.$r['concern'].'/').($params === [] ? '' : '?'.http_build_query($params));
    };

    /**
     * One coupon row, said in a sentence.
     *
     * Every number in it comes off the row. `percent` is stored ×100 so 1500 is
     * 15%; a code that does not divide evenly keeps its fraction rather than
     * being rounded into a different offer. Money::format() prints the fixed
     * kind and the minimum, which is what puts them in whole dirhams.
     */
    $offerLine = function (array $offer) {
        if ($offer['type'] === 'percent') {
            $percent = rtrim(rtrim(number_format($offer['amount'] / 100, 2, '.', ''), '0'), '.');

            return __('store.routines.offer_percent', ['code' => $offer['code'], 'percent' => $percent]);
        }

        if ($offer['amount'] > 0) {
            return __('store.routines.offer_amount', [
                'code' => $offer['code'],
                'amount' => strip_tags(Money::format($offer['amount'])),
            ]);
        }

        return __('store.routines.offer_shipping', ['code' => $offer['code']]);
    };

    $offerTerms = function (array $offer) {
        $terms = [];

        if (! empty($offer['minimum'])) {
            $terms[] = __('store.routines.offer_minimum', ['amount' => strip_tags(Money::format((int) $offer['minimum']))]);
        }

        if (! empty($offer['expires_at'])) {
            $terms[] = __('store.routines.offer_expires', ['date' => $offer['expires_at']->isoFormat('LL')]);
        }

        return $terms;
    };

    /* Site-wide scope draws ONE strip, above the list. Per-routine scope draws
       it inside each routine. Same value, same renderer — see
       BuildMyRoutine::offerScope() for the plan's open question this settles. */
    $siteOffer = ($offerScope ?? 'site') === 'site'
        ? (($routine['offer'] ?? null) ?: ($routines[0]['offer'] ?? null))
        : null;
@endphp

<div class="rtn-wrap">

    <nav class="rtn-crumb" aria-label="{{ __('store.breadcrumb.label') }}">
        <a href="{{ Url::to('/') }}">{{ __('store.breadcrumb.home') }}</a> <span>&rsaquo;</span>
        @if ($routine)
            <a href="{{ $listUrl }}">{{ __('store.routines.breadcrumb') }}</a> <span>&rsaquo;</span>
            <span aria-current="page">{{ $routineTitle($routine) }}</span>
        @else
            <span aria-current="page">{{ __('store.routines.breadcrumb') }}</span>
        @endif
    </nav>

    @if (! $routine)
        {{-- ─────────────────────────────── the list ─────────────────────────── --}}
        <div class="rtn-head">
            <h1>{{ __('store.routines.heading') }}</h1>
            <p>{{ __('store.routines.lead') }}</p>
        </div>

        @if ($siteOffer)
            <div class="rtn-offer">
                <b>{{ $offerLine($siteOffer) }}</b>
                @foreach ($offerTerms($siteOffer) as $term)
                    <small>{{ $term }}</small>
                @endforeach
            </div>
        @endif

        @if ($routines === [])
            <div class="rtn-empty">
                <h2>{{ __('store.routines.empty_heading') }}</h2>
                <p>{{ __('store.routines.empty_lead') }}</p>
                <a class="rtn-go" href="{{ $shopUrl }}">{{ __('store.routines.browse_shop') }}</a>
            </div>
        @else
            <div class="rtn-grid">
                @foreach ($routines as $r)
                    <article class="rtn-card">
                        <h2>{{ $routineTitle($r) }}</h2>
                        <p>{{ $routineBlurb($r) }}</p>
                        <div class="rtn-chips">
                            @foreach ($r['steps'] as $step)
                                <span class="rtn-chip">{{ __(RoutineRoles::labelKey($step['role'])) }}</span>
                            @endforeach
                        </div>
                        @if (($offerScope ?? 'site') === 'routine' && $r['offer'])
                            <div class="rtn-offer">
                                <b>{{ $offerLine($r['offer']) }}</b>
                                @foreach ($offerTerms($r['offer']) as $term)
                                    <small>{{ $term }}</small>
                                @endforeach
                            </div>
                        @endif
                        <a class="rtn-go" href="{{ Url::to('/routines/'.$r['concern'].'/') }}">{{ __('store.routines.open') }}</a>
                    </article>
                @endforeach
            </div>
        @endif
    @else
        {{-- ──────────────────────────── one routine ─────────────────────────── --}}
        <div class="rtn-head">
            <h1>{{ $routineTitle($routine) }}</h1>
            <p>{{ $routineBlurb($routine) }}</p>
        </div>

        @if (($offerScope ?? 'site') !== 'off' && $routine['offer'])
            <div class="rtn-offer">
                <b>{{ $offerLine($routine['offer']) }}</b>
                @foreach ($offerTerms($routine['offer']) as $term)
                    <small>{{ $term }}</small>
                @endforeach
            </div>
        @endif

        <div class="rtn-steps">
            @foreach ($routine['steps'] as $i => $step)
                <section class="rtn-step">
                    <div class="rtn-step-h">
                        <span class="rtn-n" aria-hidden="true">{{ $i + 1 }}</span>
                        <div>
                            <h3>{{ __('store.routines.step_number', ['number' => $i + 1]) }} &middot; {{ __(RoutineRoles::labelKey($step['role'])) }}</h3>
                            <p class="rtn-help">{{ __(RoutineRoles::helpKey($step['role'])) }}</p>
                        </div>
                    </div>

                    @if ($step['chosen'])
                        <div class="rtn-body">
                            <x-product-card :product="$step['chosen']" />

                            {{--
                                The swap list is open, not behind a disclosure.

                                It was a <details> first and the step read as a
                                product card with an empty half beside it: the
                                one thing on the page that makes this a routine
                                BUILDER rather than a recommendation was a word
                                the shopper had to think to click. Every entry
                                is a plain link carrying this step's slug, so
                                the list costs nothing to render open and the
                                page still works with scripting off.
                            --}}
                            <div class="rtn-swap">
                                <b>{{ __('store.routines.swap_open') }}</b>
                                @php
                                    $alts = array_values(array_filter(
                                        $step['candidates'],
                                        fn ($p) => $p->id !== $step['chosen']->id
                                    ));
                                @endphp
                                @if ($alts === [])
                                    <p class="rtn-help">{{ __('store.routines.swap_none') }}</p>
                                @else
                                    <ul class="rtn-alts">
                                        @foreach ($alts as $alt)
                                            <li>
                                                <a href="{{ $swapUrl($routine, $step['role'], $alt->slug) }}">{{ $alt->t('name') }}</a>
                                                @if ($alt->brand?->name)
                                                    <span class="rtn-b">{{ $alt->brand->t('name') }}</span>
                                                @endif
                                                <span class="rtn-b">{!! Money::format($alt->effectivePrice()) !!}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    @else
                        <div class="rtn-gap">
                            <b>{{ __('store.routines.gap_heading') }}</b>
                            <p>{{ __('store.routines.gap_lead') }}</p>
                            <a class="rtn-go" href="{{ $shopUrl }}">{{ __('store.routines.browse_shop') }}</a>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>

        @if ($routine['filled'] > 0)
            <div class="rtn-foot">
                <b>{{ trans_choice('store.routines.total_label', $routine['filled'], ['count' => $routine['filled']]) }}: {!! Money::format($routine['total']) !!}</b>
                <small>{{ __('store.routines.total_note') }}</small>
                @php $swapped = collect($routine['steps'])->contains(fn ($s) => $s['swapped']); @endphp
                @if ($swapped)
                    <a class="rtn-reset" href="{{ Url::to('/routines/'.$routine['concern'].'/') }}">{{ __('store.routines.reset') }}</a>
                @endif
            </div>
        @endif

        <p style="margin-top:18px"><a class="rtn-go" href="{{ $listUrl }}">{{ __('store.routines.back') }}</a></p>
    @endif

</div>
@endsection
