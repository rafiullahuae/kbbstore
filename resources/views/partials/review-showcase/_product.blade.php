{{-- The product the review is about, with its picture (img-cache srcset), or
     "Review of our store" for a business review. $size is the CSS width the
     thumbnail is painted at, for `sizes`. --}}
@if ($c['product'])
<a class="rs-pr" href="{{ $c['product']['url'] }}">@if ($c['product']['img'])<img src="{{ $c['product']['img'] }}" @if ($c['product']['srcset'] !== '') srcset="{{ $c['product']['srcset'] }}" sizes="{{ $size ?? 44 }}px" @endif width="{{ $size ?? 44 }}" height="{{ $size ?? 44 }}" alt="" loading="lazy" decoding="async">@else<span class="rs-ph0"></span>@endif<span><small>{{ \App\Support\ReviewShowcase::w('reviewed') }}</small><em dir="auto">{{ $c['product']['name'] }}</em></span></a>
@else
<span class="rs-pr"><span class="rs-ph0" style="display:grid;place-items:center;color:var(--rs-pink)"><svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M4 9.5 5.5 4h13L20 9.5M4 9.5V20h16V9.5M4 9.5h16M9 20v-5h6v5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></span><span><small>{{ \App\Support\ReviewShowcase::w('reviewed') }}</small><em>{{ \App\Support\ReviewShowcase::w('store') }}</em></span></span>
@endif
