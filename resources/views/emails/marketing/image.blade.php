{{-- Image (Lane MK): a picture, inset with rounded corners or full width. src/href already checked. --}}
@if ($img['full'])
<tr><td style="padding:18px 0 0;">@if ($img['href'] !== null)<a href="{{ $img['href'] }}" style="display:block;">@endif<img src="{{ $img['src'] }}" width="600" alt="{{ $img['alt'] }}" style="display:block;width:100%;max-width:600px;height:auto;">@if ($img['href'] !== null)</a>@endif</td></tr>
@else
<tr><td class="px" style="padding:22px 32px 0;">@if ($img['href'] !== null)<a href="{{ $img['href'] }}" style="display:block;">@endif<img src="{{ $img['src'] }}" width="536" alt="{{ $img['alt'] }}" style="display:block;width:100%;max-width:536px;height:auto;border-radius:14px;">@if ($img['href'] !== null)</a>@endif</td></tr>
@endif
