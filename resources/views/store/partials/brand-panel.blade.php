{{--
    The brand page's PANEL header (Lane BR2). App\Support\BrandPanel::forBrand()
    resolves $panel; this only draws it.

    ONE COPY OF EVERY WORD. The laptop and the phone are the same markup: the
    stylesheet (kbb-brand-header.css) lays it out by the width of .brw-phw, a
    size container, so on a phone the panel's box dissolves (display:contents)
    and its two children take grid rows of their own -- the logo and name in a
    pill on the banner, the description in a card below it. The <h1> is the
    brand's name and is printed once; so is the description.

    ESCAPING. The name is {{ }}. The description is {!! !!} because it is HTML,
    and it reaches here through RichText's allowlist (TitleHeader::
    brandDescription()). `class` is option keys BrandPanel checked against its
    own lists; `style` is clamped integers and #rrggbb colours under constant
    property names. The picture went through TitleHeader::safeImage().

    The picture is a real <img>, decorative -- the panel's words are the
    heading -- so its alt is empty. data-kbb-brand-header is where the quick
    editor's pencil sits and what it swaps after a save.
--}}
<div class="brw-phw" data-kbb-brand-header>
<section class="{{ $panel['class'] }}" style="{{ $panel['style'] }}" aria-labelledby="brw-ph-title">
<div class="brw-ph__media">
@if ($panel['image'] !== null)
<img class="brw-ph__img" src="{{ $panel['image'] }}" alt="" width="{{ \App\Support\TitleHeader::IMG_WIDTH }}" height="{{ \App\Support\TitleHeader::IMG_HEIGHT }}" decoding="async" fetchpriority="high">
@endif
</div>
<div class="brw-ph__panel">
<div class="brw-ph__id">
@include('store.partials.brand-logo', ['brand' => $brand, 'ring' => $ring ?? false, 'ringHex' => $ringHex ?? null])
<h1 class="brw-ph__name" id="brw-ph-title">{{ $brand->t('name') }}</h1>
</div>
@if (($panel['description'] ?? '') !== '')
<div class="brw-ph__desc brw-desc">{!! $panel['description'] !!}</div>
@endif
@if ($cta ?? false)
<a class="brw-cta brw-ph__cta" href="{{ $brand->filterUrl() }}">{{ __('store.brands.shop_all', ['brand' => $brand->t('name')]) }}</a>
@endif
</div>
</section>
</div>
