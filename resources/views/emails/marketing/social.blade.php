{{-- Social links (Lane MK): text pills, no icon images — they read with pictures off and cost no bytes. Hrefs already checked and mapped. --}}
@if ($links !== [])
<tr><td class="px" align="center" style="padding:24px 32px 0;font-family:{!! $k['sans'] !!};">@foreach ($links as $s)<a href="{{ $s['href'] }}" style="display:inline-block;margin:4px 4px;padding:8px 14px;border:1px solid #F0E4E9;border-radius:99px;font-size:12.5px;font-weight:700;color:#5E545A;text-decoration:none;">{{ $s['label'] }}</a>@endforeach</td></tr>
@endif
