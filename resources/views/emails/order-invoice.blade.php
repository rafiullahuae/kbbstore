{{--
    The emailed invoice in look A — Lane EM, from the owner's approved
    preview 11.

    ONE DELIBERATE DIFFERENCE FROM THE PREVIEW: it says "attached as a PDF",
    and this shop attaches nothing — the invoice IS the body (App\Mail\
    OrderInvoice explains why). So the headline is "Your invoice" and the
    body carries everything the printed invoice carries: the lines, the
    money, who it is billed to and delivered to, and the seller with its TRN.

    Everything is {{ }}. The seller's name, address, TRN and footer are the
    owner's settings and are printed as text, one escaped line per <br>.
--}}
@extends('emails.kit.doc')
@php
    $k = \App\Services\Mail\Kit\MailKit::for($brand ?? []);
    $kitTitle = $kitTitle ?? __('email.kit.invoice_title');
    $kitPreheader = $doc['invoiceReference'] !== ''
        ? __('email.kit.invoice_lead', ['reference' => $doc['invoiceReference'], 'number' => $doc['orderNumber']])
        : __('email.kit.invoice_lead_noref', ['number' => $doc['orderNumber']]);
    $kitLead = $doc['invoiceReference'] !== ''
        ? str_replace('%%REF%%', '<b>' . e($doc['invoiceReference']) . '</b>', e(__('email.kit.invoice_lead', ['reference' => '%%REF%%', 'number' => $doc['orderNumber']])))
        : e(__('email.kit.invoice_lead_noref', ['number' => $doc['orderNumber']]));
    $kitLines = \App\Services\Mail\Kit\KitOrder::lines(['items' => array_map(static fn (array $i) => ['name' => $i['nameForCustomer']] + $i, $doc['items'])]);
    $kitPayLine = $doc['paid']
        ? ($doc['paidAt'] !== '' ? __('email.invoice.paid_by_on', ['method' => $doc['paymentLabel'], 'date' => $doc['paidAt']]) : __('email.invoice.paid_by', ['method' => $doc['paymentLabel']]))
        : __('email.invoice.payment_method', ['method' => $doc['paymentLabel']]);
    $kitJoin = static fn (array $lines) => new \Illuminate\Support\HtmlString($lines === [] ? '&mdash;' : implode('<br>', array_map('e', $lines)));
    $kitSeller = array_values(array_filter(array_merge(
        [$doc['seller']['name']],
        $doc['seller']['addressLines'],
        [$doc['seller']['trn'] !== '' ? __('email.invoice.trn', ['trn' => $doc['seller']['trn']]) : ''],
    ), static fn ($l) => trim((string) $l) !== ''));
    $kitChipText = implode(' · ', array_values(array_filter([
        $doc['invoiceReference'] !== '' ? __('email.invoice.reference', ['reference' => $doc['invoiceReference']]) : '',
        $doc['invoicedAt'] !== '' ? __('email.invoice.issued', ['date' => $doc['invoicedAt']]) : '',
    ], static fn ($v) => $v !== '')));
    $kitChipExtra = $kitChipText === '' ? null : new \Illuminate\Support\HtmlString('<div style="margin-top:12px;font-size:13px;color:#5E545A;font-family:' . $k['sans'] . ';">' . e($kitChipText) . '</div>');
    $kitGrandNote = trim($kitPayLine . ($doc['vatNote'] !== null ? ' · ' . $doc['vatNote']['label'] . ': ' . $doc['vatNote']['plain'] : ''));
@endphp

@php $kitTemplate = 'order_invoice'; @endphp
@section('kit')
@include('emails.kit.topbar')
@include('emails.kit.card-open')
@include('emails.kit.header')
@kitsec('hero')
@include('emails.kit.hero', ['icon' => 'mail', 'tone' => 'ink', 'eyebrow' => $doc['docType'], 'title' => __('email.kit.invoice_title'), 'lead' => new \Illuminate\Support\HtmlString($kitLead)])
@endkitsec
@kitsec('chip')
@include('emails.kit.order-chip', ['chipNumber' => $doc['orderNumber'], 'chipPlaced' => $doc['placedAt'], 'chipTotal' => $doc['totalPlain'], 'chipExtra' => $kitChipExtra])
@endkitsec
@kitsec('items')
@include('emails.kit.section-title', ['text' => __('email.kit.your_items')])
@include('emails.kit.items', ['lines' => $kitLines, 'showPrice' => true])
@endkitsec
@kitsec('totals')
@include('emails.kit.totals', ['rows' => \App\Services\Mail\Kit\KitOrder::rows($doc), 'grand' => [__('email.totals.total'), $doc['totalPlain'], $kitGrandNote]])
@endkitsec
@kitsec('info')
@include('emails.kit.info-pair', ['left' => [__('email.invoice.bill_to'), $kitJoin($doc['billTo'])], 'right' => [__('email.invoice.deliver_to'), $doc['sameAddress'] ? __('email.invoice.same_as_billing') : $kitJoin($doc['shipTo']), __('email.totals.delivery'), $doc['deliveryMethod']]])
@endkitsec
@kitsec('seller')
@include('emails.kit.para', ['html' => $kitJoin($kitSeller), 'pad' => '22px 32px 0', 'size' => 12.5])
@if ($doc['seller']['footer'] !== '')
@include('emails.kit.para', ['html' => $kitJoin(preg_split('/\R/', $doc['seller']['footer']) ?: []), 'pad' => '10px 32px 0', 'size' => 12.5])
@endif
@endkitsec
@kitsec('help')
@include('emails.kit.help')
@endkitsec
@kitsec('signoff')
@include('emails.kit.signoff')
@endkitsec
@include('emails.kit.card-close')
@include('emails.kit.footer', ['why' => __('email.kit.why_order', ['site' => $k['site']]), 'unsubscribeUrl' => null])
@endsection
