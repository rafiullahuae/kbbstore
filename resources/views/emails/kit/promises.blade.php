{{--
    rj-email-kit.cjs promises(): thin rows under the total — icon left, words
    beside it. The owner (3 Oct): "these boxes i need thin. left icon and text.
    and place under the total bill row."

    $promises  list of [icon, head, text]: icon a MailKit::ICONS key or one of
               'truck' | 'tick' | 'gift'; head and text plain (the calling
               view supplies the wording through __()).
--}}
@php $prIcons = ['truck' => '&#128666;', 'tick' => '&#10004;', 'gift' => '&#127873;'] + \App\Services\Mail\Kit\MailKit::ICONS; @endphp
<tr><td class="px" style="padding:18px 32px 0;font-family:{!! $k['sans'] !!};"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFF0F4" style="background:#FFF0F4;border-radius:14px;">@foreach ($promises as [$prIcon, $prHead, $prText])<tr><td style="padding:9px 14px;{{ $loop->first ? '' : 'border-top:1px solid #FCE0E8;' }}font-family:{!! $k['sans'] !!};"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
<td width="30" valign="middle" style="width:30px;"><div style="width:30px;height:30px;line-height:30px;border-radius:15px;background:#FFFFFF;color:#C13E63;font-size:15px;text-align:center;">{!! $prIcons[$prIcon] ?? '' !!}</div></td>
<td valign="middle" style="padding-left:12px;font-family:{!! $k['sans'] !!};font-size:13.5px;line-height:1.4;color:#5E545A;" class="ink2"><b class="ink" style="color:#2A2228;font-weight:700;">{{ $prHead }}</b> &middot; {{ $prText }}</td></tr></table></td></tr>@endforeach</table></td></tr>
