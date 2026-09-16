{{--
    The money breakdown.

    Rows and labels are decided in OrderEmailPresenter::totals() so that this
    table and its plain-text twin cannot disagree about what was charged. Money is
    rendered at the currency's real precision, not the storefront's rounded
    display — see the presenter's header for why a receipt may not round.

    Recoloured to the store's own palette, and the total line now lands in the
    brand pink rather than near-black. Structurally unchanged: one row per figure,
    label left, figure right, nowrap so a price never breaks across two lines.
--}}
@php $c = $brand['colours'] ?? \App\Services\Mail\EmailBranding::PALETTE; @endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;margin:8px 0 0;">
    @foreach ($order['totals'] as $row)
        @php
            // Worked out once per row rather than six times inside the markup.
            // The old version repeated the same ternary six times across two
            // cells, which is how a total line ends up bold on one side only.
            $pad = $row['strong'] ? '13px 0 0' : '5px 0';
            $ink = $row['strong'] ? $c['ink'] : $c['ink2'];
            $size = $row['strong'] ? '17px' : '14px';
            $weight = $row['strong'] ? '700' : '400';
            $rule = $row['strong'] ? 'border-top:2px solid ' . $c['blush'] . ';' : '';
        @endphp
        <tr>
            <td style="padding:{{ $pad }};color:{{ $ink }};font-size:{{ $size }};font-weight:{{ $weight }};{{ $rule }}">
                {{ $row['label'] }}
            </td>
            <td align="right" style="padding:{{ $pad }};text-align:right;white-space:nowrap;color:{{ $row['strong'] ? $c['pinkDeep'] : $ink }};font-size:{{ $size }};font-weight:{{ $weight }};{{ $rule }}">
                {!! $row['html'] !!}
            </td>
        </tr>
    @endforeach
</table>

@if (($order['vatNote'] ?? null) !== null)
    {{-- A portion OF the total, never an addition to it (decision D-64), so it
         sits UNDER the rule rather than among the rows above it — the figures
         above still add up to what was charged, and this says how much of that
         was tax. Same figure and same wording as the checkout page showed, from
         the same App\Support\VatDisplay; the invoice restates the same fils in
         an accountant's phrasing. See OrderEmailPresenter::vatNote(). --}}
    <p style="margin:7px 0 0;text-align:right;font-size:12.5px;color:{{ $c['muted'] }};">
        {{ $order['vatNote']['label'] }}: {!! $order['vatNote']['html'] !!}
    </p>
@endif
