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
    <h2 class="kbb-fbt-title">{{ $settings->get('fbt_title', 'Complete your routine') }}</h2>
    <div class="kbb-fbt-items">
        @foreach ($bundle as $i => $p)
            @php
                $brand = $p->brand?->name ?? '';
                $price = $p->effectivePrice();
            @endphp
            <label class="kbb-fbt-item{{ 0 === $i ? ' is-main' : '' }}">
                <input type="checkbox" class="kbb-fbt-cb" value="{{ $p->id }}" data-price="{{ $price / 100 }}" checked>
                @if ($p->image)
                    <img src="{{ $p->image }}" alt="{{ $p->name }}" width="90" height="90" loading="lazy">
                @else
                    <span class="ph-fallback" style="background:{{ \App\Support\Gradient::for($brand . $p->name) }};width:90px;height:90px;display:grid;place-items:center;border-radius:10px">{{ \App\Support\Gradient::initials($brand ?: $p->name) }}</span>
                @endif
                <span class="kbb-fbt-name">{{ $p->name }}</span>
                <span class="kbb-fbt-price">{!! \App\Support\Money::format($price) !!}</span>
            </label>
            @if ($i < $bundle->count() - 1)<span class="kbb-fbt-plus" aria-hidden="true">+</span>@endif
        @endforeach
    </div>
    <div class="kbb-fbt-foot">
        <div class="kbb-fbt-total">Total: <b class="kbb-fbt-sum"></b></div>
        <button type="button" class="button kbb-fbt-add">Add selected to cart</button>
    </div>
</section>
@endif
