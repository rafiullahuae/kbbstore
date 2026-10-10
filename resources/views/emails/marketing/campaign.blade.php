{{--
    A marketing campaign in look A — Lane MK (Growth & Marketing → Marketing
    Emails). The SAME kit every customer email uses (emails/kit/*), so a
    campaign and an order email are one design: the doc shell, the topbar,
    the header, the hero, the product grid, the coupon, the button and the
    footer with its addresses, unsubscribe link and "View this email in your
    browser" at the very bottom (the owner's D5).

    THE VIEW PRINTS ONLY WHAT App\Services\Marketing\CampaignRenderer HANDS IT.
    Every row is a block the renderer already validated (Blocks::clean) and
    resolved; text arrives as an HtmlString built by Blocks::marks() from
    escaped parts, everything else through {{ }}. Every href was scheme-checked
    by Blocks::safeUrl() before the renderer mapped it to the click tracker.

    $markers (the builder's preview only) wraps each block's rows in a
    <tbody data-mkb="n"> so the builder can outline and select a block. A
    tbody is valid inside a table; a sent email never carries one.
--}}
@extends('emails.kit.doc')

@section('kit')
@php
    $mkOpen = static fn (int $i) => $markers ? '<tbody data-mkb="' . $i . '">' : '';
    $mkClose = $markers ? '</tbody>' : '';
@endphp
@if ($topbar !== null){!! $mkOpen($topbar) !!}@include('emails.kit.topbar'){!! $mkClose !!}@endif
@include('emails.kit.card-open')
@foreach ($rows as $row)
{!! $mkOpen($row['i']) !!}
@switch($row['type'])
@case('mini_header')
@include('emails.kit.header', ['nav' => $row['nav']])
@break
@case('hero_image')
@include('emails.marketing.hero-image', ['img' => $row])
@break
@case('heading')
@if ($row['style'] === 'hero' && $row['icon'] !== 'none' && $row['align'] === 'center')
@include('emails.kit.hero', ['icon' => $row['icon'], 'tone' => $row['tone'], 'eyebrow' => $row['eyebrow'], 'title' => $row['title'], 'lead' => $row['lead']])
@else
@include('emails.marketing.heading', ['h' => $row])
@endif
@break
@case('text')
@include('emails.kit.para', ['html' => $row['html'], 'pad' => $row['pad'], 'size' => $row['size'], 'center' => $row['center']])
@break
@case('button')
@include('emails.kit.button', ['label' => $row['label'], 'href' => $row['href'], 'align' => $row['align'], 'ghost' => $row['ghost'], 'dark' => $row['dark'] ?? false])
@break
@case('product_grid')
@if ($row['title'] !== '')@include('emails.kit.section-title', ['text' => $row['title'], 'pad' => '26px 32px 0'])@endif
@if (($row['layout'] ?? 'standard') === 'playful')
@include('emails.marketing.playful-cards', ['products' => $row['products'], 'cols' => $row['cols'], 'tintClass' => false])
@else
@include('emails.kit.product-grid', ['products' => $row['products'], 'cols' => $row['cols'], 'cta' => $row['cta']])
@endif
@break
@case('product_row')
@if ($row['title'] !== '')@include('emails.kit.section-title', ['text' => $row['title'], 'pad' => '26px 32px 0'])@endif
@include('emails.marketing.product-row', ['products' => $row['products'], 'cta' => $row['cta']])
@break
@case('coupon')
@include('emails.kit.coupon', ['code' => $row['code'], 'line' => $row['line'], 'expires' => $row['expires']])
@break
@case('image')
@include('emails.marketing.image', ['img' => $row])
@break
@case('columns')
@include('emails.marketing.columns', ['cols' => $row])
@break
@case('divider')
<tr><td class="px" style="padding:22px 32px 0;"><div class="line" style="height:1px;line-height:1px;font-size:0;background:#F0E4E9;">&nbsp;</div></td></tr>
@break
@case('spacer')
@include('emails.kit.gap', ['h' => $row['h']])
@break
@case('badges')
@include('emails.kit.promises', ['promises' => array_map(static fn (array $c) => [['bolt' => 'clock', 'sparkles' => 'spark'][$c['icon']] ?? $c['icon'], $c['bold'], (string) preg_replace('/^[\s,.;:،]+/u', '', $c['text'])], $row['items'])])
@break
@case('social')
@include('emails.marketing.social', ['links' => $row['links']])
@break
@endswitch
{!! $mkClose !!}
@endforeach
@include('emails.kit.gap', ['h' => 30])
@include('emails.kit.card-close')
@if ($footer !== null){!! $mkOpen($footer) !!}@include('emails.kit.footer', ['why' => $why, 'unsubscribeUrl' => $unsubscribe]){!! $mkClose !!}@endif
@endsection
