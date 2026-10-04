{{--
    rj-build-after.cjs m1 (Autumn Glow Edit): a headline with no icon — the
    serif line "Hi Aisha, your glow edit is here" and its lead. Lane EK, the
    builder's Heading block with the icon set to none. $title, $lead plain
    text, escaped here.
--}}
<tr><td class="px" align="center" style="padding:28px 32px 0;font-family:{!! $k['sans'] !!};"><div class="ink" style="font-family:Georgia,'Times New Roman',Times,serif;font-size:26px;line-height:1.25;font-weight:700;color:{{ $k['text'] }};text-align:center;">{{ $title }}</div>
@if (($lead ?? '') !== '')<div class="ink2" style="margin:10px auto 0;max-width:460px;font-size:15px;line-height:1.65;color:#5E545A;text-align:center;">{{ $lead }}</div>@endif
</td></tr>
