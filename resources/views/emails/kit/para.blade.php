{{--
    rj-email-kit.cjs para(): a paragraph of grey body text.

    $html  plain text or an HtmlString built from escaped parts
    $pad   the kit's padding, a constant from the calling view
    $size  font size in px (number)
    $center  true wraps the text in the kit's <div style="text-align:center">
--}}
<tr><td class="px" style="padding:{{ $pad ?? '18px 32px 0' }};font-family:{!! $k['sans'] !!};"><div class="ink2" style="font-size:{{ $size ?? 15 }}px;line-height:1.65;color:#5E545A;">@if ($center ?? false)<div style="text-align:center">{{ $html }}</div>@else{{ $html }}@endif</div></td></tr>
