{{--
    A marketing campaign in look A — Lane EK, Growth & Marketing → Email
    Marketing. The approved marketing previews (docs/rj-email-previews/
    marketing/m1–m3) are tools/rj-build-after.cjs simple() around builder
    blocks; this is the same: the kit's simple layout (topbar, header, the
    blocks, the footer) with the blocks drawn by App\Services\Mail\Kit\
    KitBlocks from the kit's own partials, then the kit's 30px gap.

    $kitBlocksHtml  an HtmlString KitBlocks built: every piece of text in it
                    escaped by a kit partial, every URL scheme-checked
    $kitUnsubscribe the recipient's unsubscribe page (always set: a campaign
                    without the way out is not sent)
    $kitAudience    'customers' | 'subscribers', for the why-you-got-this line
--}}
@extends('emails.kit.simple')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitWhy = $kitAudience === 'subscribers'
        ? __('email.campaign.why_subscriber', ['store' => $storeName])
        : __('email.campaign.why_customer', ['store' => $storeName]);
@endphp
@section('kit_inner')
{{ $kitBlocksHtml }}
@include('emails.kit.gap', ['h' => 30])
@endsection
