@php
/*
    Section 6, Korean Skincare Tips & Guides.                        (Lane HA)

    Three equal columns on a laptop — the same 16:10 photo box and the same
    card height on all three (grid rows stretch, the card is a flex column) —
    and the first N stacked in one column on a phone (`hs-mc-N`). Each card is
    one crawlable <a> to the article; the cover is `posts.cover` through
    CoverImage::src(), as the journal draws it.

    (Lane HS) The category tag and the "6 min read" line are each behind a
    switch on Appearance → Homepage content → Blog, both OFF at the owner's
    request; with both off the meta row is not drawn at all, so no empty
    flex row keeps its height above the title.
*/
@endphp
<section class="sec {{ $bl['classes'] }} {{ $cls }}" style="{{ $bl['style'] }}"@if ($bl['title'] !== '') aria-labelledby="hs-blog-h"@endif><div class="wrap">
@include('partials.home.hs-head', ['hid' => 'hs-blog-h', 'h' => $bl])
<div class="hs-posts">
@foreach ($postRows as $post)
@php
    $hsCover = \App\Support\CoverImage::src($post->cover);
    $hsTitle = (string) $post->t('title');
@endphp
<a class="hs-post" href="{{ \App\Support\Url::to(\App\Support\UrlScheme::article($post->slug)) }}"><span class="hs-pim" style="background:{{ \App\Support\Gradient::for($hsTitle) }}">@if ($hsCover)<img src="{{ $hsCover }}" alt="{{ $hsTitle }}" width="640" height="400" loading="lazy" decoding="async">@endif</span><span class="hs-pcb">@if (($bl['tag'] && $post->tag) || $bl['read'])<span class="hs-pmeta">@if ($bl['tag'] && $post->tag)<span class="hs-pcat">{{ $post->tag }}</span>@endif @if ($bl['read'])<span class="hs-pmin">{{ trans_choice('store.home.read_minutes', $post->readMinutes()) }}</span>@endif</span>@endif<h3>{{ $hsTitle }}</h3><span class="hs-pex">{{ \Illuminate\Support\Str::limit(strip_tags((string) ($post->t('excerpt') ?: $post->t('body'))), 140) }}</span>@if ($bl['more'] !== '')<span class="hs-pmore">{{ $bl['more'] }} <i>{!! \App\Support\HomeSections::ARROW !!}</i></span>@endif</span></a>
@endforeach
</div>
@include('partials.home.hs-foot', ['h' => $bl])
{!! \App\Support\HomeSections::itemList($bl['title'] !== '' ? $bl['title'] : 'blog', $postRows->map(fn ($p) => ['url' => \App\Support\Url::to(\App\Support\UrlScheme::article($p->slug)), 'name' => (string) $p->t('title')])) !!}
</div></section>
