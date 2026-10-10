@extends('layouts.store')
@php $seoCtx = ['noindex' => true, 'url' => \App\Support\Url::to('/review-order/')]; @endphp
@section('title', __('store.review_request.title'))

@push('styles')
{{--
    Lane RV — the review request page's own few rules, inline and on this page
    only: no new stylesheet, no request, nothing on any other page. Sized with
    the shop's tokens (--pink, --ink, --line …) so it is the shop's own look.
    THE STARS ARE RADIOS: the five inputs run 5 → 1 in the markup inside a
    row-reverse flex box, so "this one and every one after it" (~) is "this one
    and every star to its left" on screen. :has() names the chosen word where
    the browser supports it; where it does not, the stars still work.
--}}
<style>
.rr{padding:26px 16px 60px}
.rr-wrap{max-width:640px;margin:0 auto;display:grid;gap:14px}
.rr-head h1{margin:0;font-size:clamp(24px,5.4vw,30px);line-height:1.2;letter-spacing:-.01em;color:var(--ink)}
.rr-head .lede{margin:8px 0 0;font-size:14px;line-height:1.55;color:var(--muted)}
.rr-card{background:#fff;border:1px solid var(--line);border-radius:20px;padding:16px;box-shadow:var(--sh-s)}
.rr-prod{display:flex;gap:14px;align-items:center;min-width:0}
.rr-prod img,.rr-noimg{flex:0 0 72px;width:72px;height:72px;border-radius:14px;object-fit:cover;background:var(--pink-soft)}
.rr-prod > div{min-width:0}
.rr-brand{display:block;font-size:10.5px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--pink-deep)}
.rr-name{display:block;margin-top:2px;font-size:15.5px;font-weight:600;line-height:1.3;color:var(--ink);overflow-wrap:anywhere}
.rr-var{display:block;margin-top:2px;font-size:12.5px;color:var(--muted)}
.rr-ver{display:inline-block;margin-top:6px;font-size:11.5px;font-weight:600;color:var(--kbb-save,#1F7A50);background:#E9F7EF;border-radius:99px;padding:2px 9px}
.rr-state{margin:12px 0 0;font-size:13px;line-height:1.5;color:var(--ink-2);background:var(--cream);border-radius:12px;padding:9px 12px}
.rr-state.ok{color:#1B6B41;background:#E9F7EF}
.rr-stars{border:0;margin:14px 0 2px;padding:0;min-width:0}
.rr-stars legend{font-size:12.5px;font-weight:600;color:var(--ink-2);padding:0;margin-bottom:6px}
.rr-row{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:2px}
.rr-row input{position:absolute;opacity:0;width:1px;height:1px;margin:0;pointer-events:none}
.rr-row label{display:block;width:44px;height:44px;cursor:pointer;border-radius:10px}
.rr-row svg{display:block;width:38px;height:38px;margin:3px;fill:#EFE4E8;transition:fill .12s var(--ease),transform .12s var(--ease)}
.rr-row input:checked ~ label svg,.rr-row label:hover svg,.rr-row label:hover ~ label svg{fill:var(--rr-star,#F2A900)}
.rr-row label:active svg{transform:scale(.92)}
.rr-row input:focus-visible + label{outline:2px solid var(--pink);outline-offset:1px}
.rr-word{min-height:18px;font-size:12.5px;font-weight:600;color:var(--ink-2)}
@supports selector(:has(*)){
  .rr-stars:has(input[value="1"]:checked) .rr-word::after{content:attr(data-w1)}
  .rr-stars:has(input[value="2"]:checked) .rr-word::after{content:attr(data-w2)}
  .rr-stars:has(input[value="3"]:checked) .rr-word::after{content:attr(data-w3)}
  .rr-stars:has(input[value="4"]:checked) .rr-word::after{content:attr(data-w4)}
  .rr-stars:has(input[value="5"]:checked) .rr-word::after{content:attr(data-w5)}
}
.rr-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.rr-f{display:block;margin-top:12px;font-size:12.5px;font-weight:600;color:var(--ink-2)}
.rr-f input:not([type=file]),.rr-f textarea{display:block;box-sizing:border-box;width:100%;margin-top:5px;border:1px solid var(--line);border-radius:12px;padding:11px 12px;font:inherit;font-size:16px;font-weight:400;color:var(--ink);background:#fff}
.rr-f textarea{min-height:96px;resize:vertical;line-height:1.5}
.rr-f input:focus,.rr-f textarea:focus{outline:2px solid rgba(198,57,95,.35);border-color:var(--pink)}
.rr-f input[type=file]{display:block;margin-top:6px;max-width:100%;font-size:13px}
.rr-f small,.rr-note{display:block;margin-top:5px;font-size:12px;font-weight:400;color:var(--muted)}
.rr-err{display:block;margin-top:5px;font-size:12.5px;font-weight:600;color:#B3243F}
.rr-submit{width:100%;justify-content:center;min-height:50px;font-size:15px}
.rr-thanks{text-align:center;background:linear-gradient(135deg,#FFF0F4,#FFF8F5);border:1px solid var(--blush);border-radius:20px;padding:22px 18px}
.rr-thanks h2{margin:0;font-size:22px;color:var(--ink)}
.rr-thanks p{margin:6px 0 14px;font-size:14px;color:var(--ink-2);line-height:1.5}
.rr-hp{position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden}
@media (min-width:700px){.rr{padding:44px 16px 80px}.rr-card{padding:20px 22px}.rr-prod img,.rr-noimg{flex-basis:88px;width:88px;height:88px}}
</style>
@endpush

@section('content')
{{--
    The review page the review request email's stars open (Lane RV). Every
    string is printed through {{ }}; nothing here is a setting printed raw. The
    links on it carry data-no-prefetch, and its own address is outside every
    InstantNav prefix, so it is never fetched ahead.
--}}
@php $rrWords = [1 => __('store.review_request.rate_1'), 2 => __('store.review_request.rate_2'), 3 => __('store.review_request.rate_3'), 4 => __('store.review_request.rate_4'), 5 => __('store.review_request.rate_5')]; @endphp
<div class="auth rr" style="--rr-star:{{ app(\App\Services\Reviews\ReviewRequestSettings::class)->starHex() }}">
<div class="rr-wrap">
    @if ($thanks)
        <div class="rr-thanks" role="status">
            <h2>{{ __('store.review_request.thanks_title') }}</h2>
            <p>{{ __('store.review_request.thanks_lead') }}</p>
            <a class="btn btn-primary" href="{{ \App\Support\Url::to('/shop/') }}" data-no-prefetch>{{ __('store.review_request.thanks_shop') }}</a>
        </div>
    @endif

    <header class="rr-head">
        <h1>{{ $firstName !== '' ? __('store.review_request.heading', ['name' => $firstName]) : __('store.review_request.heading_plain') }}</h1>
        <p class="lede">{{ $open ? __('store.review_request.lead') : __('store.review_request.closed_lead') }}</p>
    </header>

    @if ($errors->has('reviews'))
        <div class="auth-err" role="alert">{{ $errors->first('reviews') }}</div>
    @endif

    <form method="post" action="{{ \App\Support\Url::to('/review-order/') }}" enctype="multipart/form-data" id="rr-form" class="rr-wrap" style="gap:14px">
        @csrf
        <div class="rr-hp" aria-hidden="true"><input type="text" name="sr_website" value="" tabindex="-1" autocomplete="off"></div>

        @foreach ($cards as $i => $card)
            @php
                $rrDone = $card['status'] !== null && $card['status'] !== 'pending';
                $rrRating = (int) old('reviews.' . $card['id'] . '.rating', $card['rating']);
            @endphp
            <section class="rr-card" id="rr-{{ $card['id'] }}">
                <div class="rr-prod">
                    @if ($card['image'] !== null)
                        <img src="{{ $card['image'] }}"@if ($card['srcset'] !== '') srcset="{{ $card['srcset'] }}" sizes="88px"@endif alt="{{ $card['name'] }}" width="88" height="88" @if ($i > 1) loading="lazy" @endif decoding="async">
                    @else
                        <span class="rr-noimg" aria-hidden="true"></span>
                    @endif
                    <div>
                        @if ($card['brand'] !== '')<span class="rr-brand">{{ $card['brand'] }}</span>@endif
                        <a class="rr-name" href="{{ $card['url'] }}" data-no-prefetch><span dir="auto">{{ $card['name'] }}</span></a>
                        @if ($card['variant'] !== '')<span class="rr-var"><span dir="auto">{{ $card['variant'] }}</span></span>@endif
                        <span class="rr-ver">✓ {{ __('store.review_request.verified') }}</span>
                    </div>
                </div>

                @if ($rrDone)
                    <p class="rr-state ok">{{ __('store.review_request.published') }}</p>
                @elseif ($open)
                    @if ($card['status'] === 'pending')
                        <p class="rr-state">{{ __('store.review_request.pending') }}</p>
                    @endif
                    <fieldset class="rr-stars">
                        <legend>{{ __('store.review_request.rating_label') }}</legend>
                        <div class="rr-row">
                            @foreach ([5, 4, 3, 2, 1] as $n)
                                <input type="radio" id="rr-{{ $card['id'] }}-{{ $n }}" name="reviews[{{ $card['id'] }}][rating]" value="{{ $n }}" @checked($rrRating === $n)>
                                <label for="rr-{{ $card['id'] }}-{{ $n }}" title="{{ $rrWords[$n] }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.6l2.9 5.9 6.5.9-4.7 4.6 1.1 6.5L12 17.4l-5.8 3.1 1.1-6.5L2.6 9.4l6.5-.9z"/></svg><span class="rr-sr">{{ trans_choice('email.kit.stars', $n, ['count' => $n]) }} — {{ $rrWords[$n] }}</span></label>
                            @endforeach
                        </div>
                        <div class="rr-word" aria-hidden="true" data-w1="{{ $rrWords[1] }}" data-w2="{{ $rrWords[2] }}" data-w3="{{ $rrWords[3] }}" data-w4="{{ $rrWords[4] }}" data-w5="{{ $rrWords[5] }}"></div>
                    </fieldset>

                    <label class="rr-f">{{ __('store.review_request.title_label') }}
                        <input type="text" name="reviews[{{ $card['id'] }}][title]" maxlength="120" value="{{ old('reviews.' . $card['id'] . '.title', $card['title']) }}" placeholder="{{ __('store.review_request.title_ph') }}">
                    </label>
                    <label class="rr-f">{{ __('store.review_request.content_label') }}
                        <textarea name="reviews[{{ $card['id'] }}][content]" maxlength="5000" rows="4" placeholder="{{ __('store.review_request.content_ph') }}">{{ old('reviews.' . $card['id'] . '.content', $card['content']) }}</textarea>
                        @error('reviews.' . $card['id'] . '.content')<span class="rr-err" role="alert">{{ $message }}</span>@enderror
                    </label>
                    @if ($photoMax > $card['photos'])
                        <label class="rr-f">{{ __('store.review_request.photos_label') }}
                            <input type="file" name="photos[{{ $card['id'] }}][]" accept="image/jpeg,image/png,image/webp" multiple>
                            <small>{{ __('store.review_request.photos_note', ['count' => $photoMax - $card['photos']]) }}</small>
                        </label>
                        @error('photos.' . $card['id'])<span class="rr-err" role="alert">{{ $message }}</span>@enderror
                    @endif
                @endif
            </section>
        @endforeach

        @if ($open)
            <section class="rr-card">
                <label class="rr-f" style="margin-top:0">{{ __('store.review_request.name_label') }}
                    <input type="text" name="author_name" maxlength="100" required value="{{ old('author_name', $authorName) }}" autocomplete="name">
                    <small>{{ __('store.review_request.name_note') }}</small>
                    @error('author_name')<span class="rr-err" role="alert">{{ $message }}</span>@enderror
                </label>
            </section>
            <p class="rr-note" style="margin:0;text-align:center">{{ __('store.review_request.skip_note') }}</p>
            <button type="submit" class="btn btn-primary rr-submit">{{ __('store.review_request.submit') }}</button>
        @endif
    </form>
</div>
</div>
@endsection
