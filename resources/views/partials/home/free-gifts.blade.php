{{--
    THE HOMEPAGE FREE GIFTS SECTION -- Marketing -> Free gifts -> Homepage section.

    The owner, 23 October: "i need just a simple one section ... super simple
    for users to understand" and "if we publish any range from the ranges, it
    should show auto here also". So: the heading, then one line per published
    range -- the amount, what it gives, the gift photos -- and a button. Every
    line comes from FreeGifts::home(), which reads the ranges the checkout
    reads, so a range published, edited or switched off in the admin is on
    the homepage at once and nothing here needs touching.

    Its styles are in this partial, as the gift box's are in its own: they
    load only on a homepage that draws the section, and the shop's stylesheet
    is not touched. Every string printed is escaped.
--}}
@php
    $fghImg = static function (array $g): string {
        $img = (string) $g['image'];
        if ($img === '') {
            return '<span class="fgh-t"><span class="fgh-ph" role="img" aria-label="' . e($g['name']) . '">' . e(mb_strtoupper(mb_substr($g['brand'] !== '' ? $g['brand'] : $g['name'], 0, 2))) . '</span></span>';
        }
        $set = \App\Support\ImageVariants::srcsetFor($img);
        return '<span class="fgh-t"><img src="' . e(\App\Support\ImageVariants::variantUrl($img, 200)) . '" alt="' . e(trim($g['brand'] . ' ' . $g['name'])) . '" width="52" height="52" loading="lazy" decoding="async"' . ($set !== '' ? ' srcset="' . e($set) . '" sizes="52px"' : '') . '>' . ((int) $g['quantity'] > 1 ? '<b>×' . (int) $g['quantity'] . '</b>' : '') . '</span>';
    };
@endphp
<section class="sec fgh" aria-labelledby="fgh-h"><style>.fgh .wrap{display:grid;justify-items:center}.fgh-card{width:100%;max-width:880px;background:#fff;border:1.5px solid #2e8f62;border-radius:18px;padding:22px 22px 24px;box-sizing:border-box;display:grid;gap:16px}.fgh-hd{display:grid;gap:4px;justify-items:center;text-align:center}.fgh-hd h2{margin:0;display:flex;align-items:center;gap:9px;font-size:clamp(20px,2.4vw,26px);line-height:1.2;color:#2a2228;letter-spacing:-.01em}.fgh-hd h2 svg{width:24px;height:24px;flex:none;color:#2e8f62}.fgh-hd p{margin:0;color:#756c74;font-size:14.5px}.fgh-rows{display:grid;gap:10px}.fgh-r{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:14px;align-items:center;background:#f4faf6;border-radius:14px;padding:12px 14px}.fgh-a{font-weight:800;font-size:14px;color:#fff;background:#2e8f62;border-radius:999px;padding:6px 12px;white-space:nowrap;font-variant-numeric:tabular-nums}.fgh-w{font-weight:700;font-size:15.5px;line-height:1.3;color:#2a2228;min-width:0}.fgh-p{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}.fgh-t{position:relative;display:block;width:52px;height:52px;border-radius:10px;background:#fff;border:1px solid rgba(42,34,40,.08);box-sizing:border-box;flex:none}.fgh-t img{display:block;width:100%;height:100%;object-fit:contain;border-radius:10px}.fgh-ph{display:grid;place-items:center;width:100%;height:100%;font-size:11px;font-weight:800;color:#8a7d86;background:#faf5f7;border-radius:10px}.fgh-t b{position:absolute;top:-6px;inset-inline-end:-6px;background:#2e8f62;color:#fff;font-size:10px;font-weight:800;border-radius:999px;min-width:18px;height:18px;padding:0 4px;box-sizing:border-box;display:grid;place-items:center;line-height:1}.fgh-ft{display:flex;justify-content:center}.fgh-btn{display:inline-block;background:#2a2228;color:#fff;border-radius:999px;padding:12px 28px;font-weight:700;font-size:14.5px;text-decoration:none}.fgh-btn:hover{background:#000}.fgh-btn:focus-visible{outline:2px solid #2e8f62;outline-offset:2px}@media(max-width:640px){.fgh-card{padding:18px 14px 20px;border-radius:16px}.fgh-r{grid-template-columns:minmax(0,1fr);gap:8px}.fgh-a{justify-self:start}.fgh-p{justify-content:flex-start}.fgh-t{width:46px;height:46px}}</style><div class="wrap">
  <div class="fgh-card">
    <div class="fgh-hd"><h2 id="fgh-h"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7M7.5 8a2.5 2.5 0 0 1 0-5C11 3 12 8 12 8s1-5 4.5-5a2.5 2.5 0 0 1 0 5"/></svg>{{ $fgh['title'] }}</h2>@if ($fgh['sub'] !== '')<p>{{ $fgh['sub'] }}</p>@endif</div>
    <div class="fgh-rows">
@foreach ($fgh['rows'] as $r)
      <div class="fgh-r"><span class="fgh-a">{{ $r['amount'] }}</span><span class="fgh-w">{{ $r['words'] }}</span><span class="fgh-p">{!! implode('', array_map($fghImg, array_slice($r['gifts'], 0, 8))) !!}</span></div>
@endforeach
    </div>
    @if ($fgh['btn'] !== '')<div class="fgh-ft"><a class="fgh-btn" href="{{ \App\Support\Url::to('/shop/') }}">{{ $fgh['btn'] }}</a></div>@endif
  </div>
</div></section>
