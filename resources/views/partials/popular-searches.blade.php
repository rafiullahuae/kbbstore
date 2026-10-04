{{--
    Popular searches — SEO → Keywords (Lane KW). OFF unless the owner turns it
    on (Store → SEO Keywords → Sources → "Popular searches block"), and then
    only on a category or brand page whose last sync gave it links.

    The one place a keyword is VISIBLE, and it is visible on purpose: a short
    row of real links to this listing's best sellers, each labelled with that
    product's own keyword. Useful to a shopper, crawlable, honest — internal
    links with descriptive anchors are what Google asks for. Nothing here is
    hidden and nothing is repeated.

    Every label and href is escaped by {{ }}; hrefs come from root-relative
    paths that PageKeywords::links() validated, through Url::to().

    Included at column 0 and printing NOTHING at all when off, so the pages it
    sits in are byte-identical until the owner switches it on.
--}}@php $kbbPopular = \App\Services\Seo\Keywords\PageKeywords::popular($seoEntity ?? null); @endphp
@if ($kbbPopular !== [])
<nav class="kbb-popsearch" aria-label="{{ app()->getLocale() === 'ar' ? 'عمليات بحث شائعة' : 'Popular searches' }}">
<style>.kbb-popsearch{margin:28px 0 8px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}.kbb-popsearch b{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted,#6b7280);margin-inline-end:4px}.kbb-popsearch a{font-size:13px;border:1px solid var(--line,#e5e7eb);border-radius:999px;padding:5px 12px;color:inherit;text-decoration:none;max-width:100%;overflow-wrap:anywhere}.kbb-popsearch a:hover{border-color:currentColor}</style>
<b>{{ app()->getLocale() === 'ar' ? 'عمليات بحث شائعة' : 'Popular searches' }}</b>
@foreach ($kbbPopular as [$kbbLabel, $kbbHref])
<a href="{{ $kbbHref }}">{{ $kbbLabel }}</a>
@endforeach
</nav>
@endif
