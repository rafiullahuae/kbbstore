{{--
    The money breakdown.

    Rows and labels are decided in OrderEmailPresenter::totals() so that this
    table and its plain-text twin cannot disagree about what was charged. Money is
    rendered at the currency's real precision, not the storefront's rounded
    display — see the presenter's header for why a receipt may not round.
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;margin:6px 0 0;">
    @foreach ($order['totals'] as $row)
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
