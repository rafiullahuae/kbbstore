{{--
    The page a refused visitor sees. (Lane CT)

    App\Http\Middleware\BlockGate answers 403 with this for a blocked address
    (on the cart, the checkout, any form — or every page, when the owner has
    set the scope to the whole storefront) and for a bot asked to leave the
    cart or checkout.

    STANDALONE ON PURPOSE. No layout, no header, no menu, no product grid:
    the shop's layout runs queries and pulls the catalogue, and a page served
    to a flood of refused requests must cost nothing. Every word is __() —
    Arabic drafts ship in the same package — and the only variable printed is
    the reference, escaped, which is "B" and a block number or "R" and six hex
    characters: never the visitor's own address, so a screenshot posted
    somewhere does not publish it.
--}}
@php
    $kbbLang = app()->getLocale();
    $kbbRtl = $kbbLang === 'ar';
    $kbbStore = (string) app(\App\Services\SettingsService::class)->get('store_name', 'K-Beauty Bliss');
@endphp
<!doctype html>
<html lang="{{ $kbbLang }}" dir="{{ $kbbRtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ __('store.blocked.title') }} · {{ $kbbStore }}</title>
<style>
:root{color-scheme:light}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:#faf7f5;color:#2b2328;font:16px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.kb{width:100%;max-width:440px;background:#fff;border:1px solid #efe6e9;border-radius:18px;padding:28px 24px;text-align:center;box-shadow:0 18px 40px -24px rgba(43,35,40,.25)}
.kb-i{width:52px;height:52px;margin:0 auto 14px;border-radius:50%;display:grid;place-items:center;background:#fdeef2;color:#c2416b}
h1{font-size:20px;line-height:1.3;margin:0 0 8px}
p{margin:0 0 10px;color:#5d5258;font-size:15px}
.kb-ref{display:inline-block;margin-top:8px;font:600 12.5px ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#f6f1f3;border-radius:999px;padding:4px 12px;color:#5d5258}
a{color:#c2416b}
</style>
</head>
<body>
<main class="kb" role="main">
    <div class="kb-i" aria-hidden="true">
        <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M5.7 5.7l12.6 12.6"/></svg>
    </div>
    <h1>{{ $why === 'bot' ? __('store.blocked.bot_title') : __('store.blocked.title') }}</h1>
    <p>{{ $why === 'bot' ? __('store.blocked.bot_lead') : __('store.blocked.lead') }}</p>
    <p>{{ __('store.blocked.contact') }}</p>
    <span class="kb-ref">{{ __('store.blocked.reference', ['code' => $reference]) }}</span>
    @if ($home ?? true)
        <p style="margin-top:16px"><a href="{{ \App\Support\Url::to('/') }}">{{ __('store.blocked.home') }}</a></p>
    @endif
</main>
</body>
</html>
