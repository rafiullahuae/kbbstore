{{--
    Design C's pastel product cards (Lane EC) — Lane ED's c-playful.html,
    card for card: a tint per card in turn, a white type sticker, the picture,
    the brand, the name, and the price pill with the old price struck through.

    $products  ProductFill::cards() as CampaignRenderer hands them: href already
               mapped (preview / click tracker), 'kind' the sticker word ('' =
               no sticker), 'short' the name without its brand, 'wasNum' the
               old price as a bare number, 'h138' the picture's height at 138.
    $cols      3  fluid hybrid: 3 across at 600, 2 across on a phone (the .hy
                  media query), 1 centred card where a client ignores <style>
                  — never wider than the screen, with or without the query
               2  a plain two-cell table: 2 across at every width, no query needed
               1  one centred card per row
    $tintClass true in the playful shell (t0…t5 turn each tint dark in dark
               mode); false in the standard shell, where "soft" does that job.

    Every colour and the font stack are EmailTheme constants; every word is
    printed through {{ }}; the price is plain text, escaped, its space made
    non-breaking.
--}}
@php
    $pcCols = in_array((int) ($cols ?? 3), [1, 2, 3], true) ? (int) $cols : 3;
    $pcRtl = ($locale ?? 'en') === 'ar';
    $pcFont = $pcRtl ? \App\Services\Marketing\EmailTheme::SANS_AR : \App\Services\Marketing\EmailTheme::SANS;
    $pcC = \App\Services\Marketing\EmailTheme::C;
    $pcTints = \App\Services\Marketing\EmailTheme::TINTS;
    $pcImgW = $pcCols === 1 ? 200 : 138;
    $pcTintClass = (bool) ($tintClass ?? true);
    $pcCard = static function (array $p, int $i) use ($pcFont, $pcC, $pcTints, $pcImgW, $pcRtl, $pcTintClass): string {
        $tint = $pcTints[$i % 6];
        $cls = $pcTintClass ? 't' . ($i % 6) : 'soft';
        $h = (int) round(($p['h138'] ?? 138) * $pcImgW / 138);
        $price = str_replace(' ', '&nbsp;', e((string) $p['price']));
        $was = ! empty($p['was']) ? e((string) ($p['wasNum'] ?? $p['was'])) : '';
        $sticker = ($p['kind'] ?? '') !== ''
            ? '<tr><td style="padding:12px 12px 0;text-align:' . ($pcRtl ? 'right' : 'left') . ';"><span style="display:inline-block;background:#FFFFFF;border-radius:99px;padding:4px 10px;font-family:' . $pcFont . ';font-size:11px;font-weight:700;color:' . $pcC['deep'] . ';">' . e($p['kind']) . '</span></td></tr>'
            : '<tr><td style="padding:12px 12px 0;font-size:0;line-height:0;">&nbsp;</td></tr>';
        $img = ! empty($p['img'])
            ? '<tr><td class="pc-pad" style="padding:8px 14px 0;"><a href="' . e($p['href']) . '"><img src="' . e($p['img']) . '" width="' . $pcImgW . '" height="' . $h . '" alt="' . e(trim(($p['brand'] ?? '') . ' ' . ($p['short'] ?? $p['name']))) . '" style="display:block;width:100%;max-width:' . $pcImgW . 'px;height:auto;margin:0 auto;border:0;border-radius:16px;"></a></td></tr>'
            : '';
        $brand = ($p['brand'] ?? '') !== '' ? '<div class="ink" style="font-size:12px;font-weight:800;color:' . $pcC['text'] . ';"' . ($pcRtl ? ' dir="ltr"' : '') . '>' . e($p['brand']) . '</div>' : '';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:0 6px 14px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $tint . '" class="' . $cls . '" style="background:' . $tint . ';border-radius:22px;">'
            . $sticker . $img
            . '<tr><td class="pc-pad" style="padding:10px 14px 0;text-align:center;font-family:' . $pcFont . ';">' . $brand
            . '<div class="ink2" style="margin-top:3px;font-size:13px;line-height:1.35;color:' . $pcC['text2'] . ';"' . ($pcRtl ? ' dir="auto"' : '') . '>' . e($p['short'] ?? $p['name']) . '</div></td></tr>'
            . '<tr><td align="center" style="padding:10px 12px 14px;font-family:' . $pcFont . ';">'
            . '<a href="' . e($p['href']) . '" style="display:inline-block;background:#FFFFFF;border-radius:99px;padding:7px 14px;font-size:14px;line-height:1.3;text-decoration:none;"><b dir="ltr" style="font-size:14px;color:' . $pcC['pink'] . ';font-weight:800;white-space:nowrap;">' . $price . '</b>'
            . ($was !== '' ? '&nbsp; <s dir="ltr" style="font-size:11.5px;color:' . $pcC['muted'] . ';white-space:nowrap;">' . $was . '</s>' : '') . '</a>'
            . '</td></tr></table></td></tr></table>';
    };
@endphp
<tr><td align="center" class="px" style="padding:4px 24px 0;font-size:0;text-align:center;">@if ($pcCols === 3)<!--[if mso]><table role="presentation" width="534" cellpadding="0" cellspacing="0" border="0" align="center"><tr><![endif]-->@foreach ($products as $p)@if ($loop->index && $loop->index % 3 === 0)<!--[if mso]></tr><tr><![endif]-->@endif<!--[if mso]><td width="178" valign="top"><![endif]--><div class="hy" style="display:inline-block;vertical-align:top;width:100%;max-width:178px;">{!! $pcCard($p, $loop->index) !!}</div><!--[if mso]></td><![endif]-->@endforeach<!--[if mso]></tr></table><![endif]-->@else<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:{{ $pcCols === 2 ? 534 : 300 }}px;margin:0 auto;">@foreach (array_chunk($products, $pcCols) as $pcRow)<tr>@foreach ($pcRow as $p)<td width="{{ $pcCols === 2 ? '50%' : '100%' }}" valign="top" style="width:{{ $pcCols === 2 ? '50%' : '100%' }};font-size:14px;">{!! $pcCard($p, $loop->parent->index * $pcCols + $loop->index) !!}</td>@endforeach @if (count($pcRow) < $pcCols)<td width="50%" style="width:50%;"></td>@endif</tr>@endforeach</table>@endif</td></tr>
