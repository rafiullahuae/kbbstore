{{--
    Tabby & Tamara — a phone section of its own. Lane QA.

      "also i want one more section. Tabby and Tamara. two columns side by
       side : Split your purchase into monthly payments + Tabby logo AND
       Installments upto 6 months, no late fees! + tamara logo. no learn more
       buttons."

    ("upto" is written "up to" in the shipped wording; that is the only edit
    to his sentence.)

    Two small bordered cards in one row, the words on the inline-start side and
    the mark on the other. NO LINK: there is no "Learn more" and no anchor in
    this partial at all.

    ▲ WHICH CARDS: App\Services\ProductMobileSections::payLater() — his switch
      for each card AND the shop's own acceptance mark for that method
      (Appearance → Cart page → Trust row). Neither card, no wrapper, no bytes.

    ▲ THE WORDS ARE HIS, ESCAPED: `{{ }}` on ProductMobileSections::text(),
      which prints the InterfaceStrings key while the setting is still the
      shipped English, so /ar reads Arabic. The marks are PaymentMarkArt
      constants.

    ▲ PHONE ONLY. kbb-product.css hides `.pm-paylater` from 881px up — he asked
      for it on the phone and said the laptop is fine as it is.
--}}@php
    $kbbPl = app(\App\Services\ProductMobileSections::class);
    $kbbPlMethods = $kbbPl->payLater();
@endphp
@if ($kbbPlMethods !== [])
<div class="pdp-paylater pm-sec pm-paylater">@foreach ($kbbPlMethods as $kbbPlM)<div class="pdp-pl-card pdp-pl-{{ $kbbPlM }}"><span class="pdp-pl-text">{{ $kbbPl->text($kbbPlM.'_text') }}</span><span class="pdp-pl-logo">{!! \App\Support\PaymentMarkArt::payLater($kbbPlM) !!}</span></div>@endforeach</div><!--/pm-->
@endif
