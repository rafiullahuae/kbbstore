{{--
    Frequently Bought Together — ported from kbb_fbt_render() in the KBB Modules
    plugin. Classes are the plugin's: .kbb-fbt / .kbb-fbt-title / .kbb-fbt-items
    / .kbb-fbt-item.is-main / .kbb-fbt-cb / .kbb-fbt-name / .kbb-fbt-price /
    .kbb-fbt-plus / .kbb-fbt-foot / .kbb-fbt-total / .kbb-fbt-sum / .kbb-fbt-add

    The plugin ships its own CSS for this block, so no theme stylesheet is
    involved. Off by default, matching the module's own default.
--}}
{{-- Fully qualified class names, no `use`: this partial is conditionally
     included, and a `use` inside an @if compiles to a PHP `use` inside a
     conditional block, which is a parse error. --}}
@if ($settings->moduleEnabled('frequently_bought', false) && $bundle->isNotEmpty())

<section class="kbb-fbt">
    <h2 class="kbb-fbt-title">{{ $settings->get('fbt_title', __('store.fbt.title')) }}</h2>
    <div class="kbb-fbt-items">
        @foreach ($bundle as $i => $p)
            @php
                $brand = $p->brand?->t('name') ?? '';
                $name  = $p->t('name');
                $seed  = ($p->brand?->name ?? '') . $p->name;
                $price = $p->effectivePrice();
            @endphp
            <label class="kbb-fbt-item{{ 0 === $i ? ' is-main' : '' }}">
                <input type="checkbox" class="kbb-fbt-cb" value="{{ $p->id }}" data-price="{{ $price / 100 }}" checked>
                @if ($p->image)
                    @php $fbtSrcset = \App\Support\ImageVariants::srcsetFor($p->image); @endphp
                    <img src="{{ $p->image }}" alt="{{ $name }}" width="90" height="90" loading="lazy"
                         @if ($fbtSrcset !== '') srcset="{{ $fbtSrcset }}" sizes="{{ \App\Support\ImageVariants::fbtSizesAttribute() }}" @endif>
                @else
                    <span class="ph-fallback" style="background:{{ \App\Support\Gradient::for($seed) }};width:90px;height:90px;display:grid;place-items:center;border-radius:10px">{{ \App\Support\Gradient::initials($brand ?: $name) }}</span>
                @endif
                <span class="kbb-fbt-name">{{ $name }}</span>
                <span class="kbb-fbt-price">{!! \App\Support\Money::format($price) !!}</span>
            </label>
            @if ($i < $bundle->count() - 1)<span class="kbb-fbt-plus" aria-hidden="true">+</span>@endif
        @endforeach
    </div>
    <div class="kbb-fbt-foot">
        <div class="kbb-fbt-total">{!! __('store.fbt.total', ['amount' => '<b class="kbb-fbt-sum"></b>']) !!}</div>
        <button type="button" class="button kbb-fbt-add">{{ __('store.fbt.add_selected') }}</button>
    </div>
</section>
@endif
