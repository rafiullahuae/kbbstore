{{--
    The emailed invoice.

    Renders the SAME InvoiceDocument array the printable page renders, so the
    invoice in the customer's inbox and the invoice the owner prints cannot
    disagree about a figure. Money is at the currency's real precision, never
    the storefront's rounded display — see InvoiceDocument's header.

    Everything below goes through {{ }}. Names, addresses and gift messages are
    customer input and arrive raw by design; only the money, which Money::format()
    builds and escapes itself, uses {!! !!}.
--}}
@extends('emails.layout')

@section('body')
    {{-- The same heading the printable page prints, from the same array, for
         the same reason every other figure here comes from it: the invoice in
         the inbox and the invoice on the printer may not disagree about what
         kind of document they are. InvoiceDocument::docType() decides. --}}
    <div style="font-size:19px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;margin:0 0 4px;">{{ $doc['docType'] }}</div>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;margin:0 0 18px;">
        <tr>
            <td style="padding:10px 0 0;font-size:13.5px;color:#4b5563;line-height:1.7;vertical-align:top;">
                @if ($doc['invoiceReference'] !== '')
                    <div>Invoice <strong style="color:#1d1d1f;">{{ $doc['invoiceReference'] }}</strong></div>
                @endif
                <div>Order <strong style="color:#1d1d1f;">{{ $doc['orderNumber'] }}</strong></div>
                @if ($doc['invoicedAt'] !== '')
                    <div>Issued {{ $doc['invoicedAt'] }}</div>
                @endif
                @if ($doc['placedAt'] !== '')
                    <div>Ordered {{ $doc['placedAt'] }}</div>
                @endif
            </td>
            <td style="padding:10px 0 0;font-size:12.5px;color:#6b7280;line-height:1.6;text-align:right;vertical-align:top;">
                <div style="font-weight:700;color:#1d1d1f;font-size:13.5px;">{{ $doc['seller']['name'] }}</div>
                @foreach ($doc['seller']['addressLines'] as $line)
                    <div>{{ $line }}</div>
                @endforeach
                @if ($doc['seller']['trn'] !== '')
                    <div>TRN {{ $doc['seller']['trn'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;margin:0 0 20px;">
        <tr>
            <td width="50%" style="padding:0 12px 0 0;font-size:13px;color:#4b5563;line-height:1.6;vertical-align:top;">
                <div style="font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#9096a1;margin-bottom:3px;">Bill to</div>
                @forelse ($doc['billTo'] as $i => $line)
                    <div @if ($i === 0) style="font-weight:700;color:#1d1d1f;" @endif>{{ $line }}</div>
                @empty
                    <div>&mdash;</div>
                @endforelse
            </td>
            <td width="50%" style="padding:0;font-size:13px;color:#4b5563;line-height:1.6;vertical-align:top;">
                <div style="font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#9096a1;margin-bottom:3px;">Deliver to</div>
                @if ($doc['sameAddress'])
                    <div>Same as the billing address</div>
                @else
                    @forelse ($doc['shipTo'] as $i => $line)
                        <div @if ($i === 0) style="font-weight:700;color:#1d1d1f;" @endif>{{ $line }}</div>
                    @empty
                        <div>&mdash;</div>
                    @endforelse
                @endif
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">
        <tr>
            <th align="left" style="padding:0 6px 7px 0;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#9096a1;font-weight:700;border-bottom:1.5px solid #1d1d1f;">Item</th>
            <th align="right" style="padding:0 6px 7px;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#9096a1;font-weight:700;border-bottom:1.5px solid #1d1d1f;white-space:nowrap;">Qty</th>
            <th align="right" style="padding:0 6px 7px;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#9096a1;font-weight:700;border-bottom:1.5px solid #1d1d1f;white-space:nowrap;">Unit</th>
            <th align="right" style="padding:0 0 7px 6px;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:#9096a1;font-weight:700;border-bottom:1.5px solid #1d1d1f;white-space:nowrap;">Amount</th>
        </tr>
        @foreach ($doc['items'] as $item)
            <tr>
                <td style="padding:9px 6px 9px 0;border-bottom:1px solid #eceff3;font-size:14px;">
                    <div style="font-weight:600;">{{ $item['name'] }}</div>
                    @php
                        $sub = array_values(array_filter([
                            $item['brand'],
                            $item['variant'],
                            $item['sku'] !== '' ? 'SKU ' . $item['sku'] : '',
                        ], fn ($v) => $v !== ''));
                    @endphp
                    @if ($sub !== [])
                        <div style="font-size:12px;color:#9096a1;margin-top:2px;">{{ implode(' · ', $sub) }}</div>
                    @endif
                </td>
                <td align="right" style="padding:9px 6px;border-bottom:1px solid #eceff3;font-size:14px;color:#4b5563;white-space:nowrap;">{{ $item['quantity'] }}</td>
                <td align="right" style="padding:9px 6px;border-bottom:1px solid #eceff3;font-size:14px;color:#4b5563;white-space:nowrap;">{!! $item['unitHtml'] !!}</td>
                <td align="right" style="padding:9px 0 9px 6px;border-bottom:1px solid #eceff3;font-size:14px;white-space:nowrap;">{!! $item['lineHtml'] !!}</td>
            </tr>
        @endforeach
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;margin:8px 0 0;">
        @foreach ($doc['totals'] as $row)
            <tr>
                <td style="padding:{{ $row['strong'] ? '12px 0 0' : '5px 0' }};color:{{ $row['strong'] ? '#1d1d1f' : '#4b5563' }};font-size:{{ $row['strong'] ? '16px' : '14px' }};font-weight:{{ $row['strong'] ? '700' : '400' }};{{ $row['strong'] ? 'border-top:2px solid #1d1d1f;' : '' }}">
                    {{ $row['label'] }}
                </td>
                <td style="padding:{{ $row['strong'] ? '12px 0 0' : '5px 0' }};text-align:right;white-space:nowrap;color:{{ $row['strong'] ? '#1d1d1f' : '#4b5563' }};font-size:{{ $row['strong'] ? '16px' : '14px' }};font-weight:{{ $row['strong'] ? '700' : '400' }};{{ $row['strong'] ? 'border-top:2px solid #1d1d1f;' : '' }}">
                    {!! $row['html'] !!}
                </td>
            </tr>
        @endforeach
    </table>

    @if ($doc['vatNote'] !== null)
        {{-- A portion OF the total, never an addition to it. Decision D-64. --}}
        <p style="margin:7px 0 0;text-align:right;font-size:12.5px;color:#6b7280;">
            {{ $doc['vatNote']['label'] }}: {!! $doc['vatNote']['html'] !!}@if ($doc['vatNote']['trn'] !== '') · TRN {{ $doc['vatNote']['trn'] }}@endif
        </p>
    @endif

    {{-- "PAID BY" ONLY WHEN IT HAS BEEN PAID.

         This line read "Paid by {payment method}" for every invoice, including
         the ones it is most often sent for. An invoice is emailed by the
         operator from the order screen at whatever moment they choose, and this
         store's ordinary payment method is cash on delivery, where `paid_at` is
         deliberately never set until the money is actually collected
         (CashOnDelivery::start). So the commonest invoice this shop sends —
         cash on delivery, not yet delivered — told the customer in writing that
         they had already paid for it.

         The printable invoice has always had this right: it prints the PAID
         stamp behind `$doc['paid']`, the same flag used here. The two documents
         render the same InvoiceDocument array and now agree about the one fact
         an invoice is most often read for. --}}
    <p style="margin:20px 0 0;font-size:13.5px;color:#4b5563;">
        @if ($doc['paid'])
            Paid by {{ $doc['paymentLabel'] }}@if ($doc['paidAt'] !== '') on {{ $doc['paidAt'] }}@endif · {{ $doc['deliveryMethod'] }}
        @else
            Payment method {{ $doc['paymentLabel'] }} · {{ $doc['deliveryMethod'] }}
        @endif
    </p>

    @if ($doc['seller']['footer'] !== '')
        <p style="margin:14px 0 0;font-size:12.5px;color:#6b7280;white-space:pre-wrap;">{{ $doc['seller']['footer'] }}</p>
    @endif
@endsection
