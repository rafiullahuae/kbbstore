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

    LANE BR4. The logo is printed only when "Show the brand logo" is on for a
    device (off by default, as the owner asked); off on both, there is no
    circle and no gap. A description longer than its lines' worth
    (BrandPanel::forBrand counts it, on the server) sits in .brw-ph__clamp,
    which the stylesheet cuts at two lines, with a real <button> after it:
    aria-expanded and aria-controls, both labels printed and one shown by the
    button's own state, so the script only flips aria-expanded and a class
    (tabs.js initReadMore). Nothing is measured, and nothing moves on load.
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
@if ($panel['logo'] ?? true)
@include('store.partials.brand-logo', ['brand' => $brand, 'ring' => $ring ?? false, 'ringHex' => $ringHex ?? null])
@endif
<h1 class="brw-ph__name" id="brw-ph-title">{{ $brand->t('name') }}</h1>
</div>
@if (($panel['description'] ?? '') !== '' && ($panel['more'] ?? false))
<div class="brw-ph__desc brw-desc"><div class="brw-ph__clamp" id="brw-ph-text">{!! $panel['description'] !!}</div><button class="brw-ph__more" type="button" aria-expanded="false" aria-controls="brw-ph-text" data-brw-more><span class="brw-ph__more-o">{{ __('store.brands.read_more') }}</span><span class="brw-ph__more-c">{{ __('store.brands.read_less') }}</span></button></div>
@elseif (($panel['description'] ?? '') !== '')
<div class="brw-ph__desc brw-desc">{!! $panel['description'] !!}</div>
@endif
@if ($cta ?? false)
<a class="brw-cta brw-ph__cta" href="{{ $brand->filterUrl() }}">{{ __('store.brands.shop_all', ['brand' => $brand->t('name')]) }}</a>
@endif
</div>
</section>
</div>
