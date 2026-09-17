{{-- The `legal_notice` module — ported in Lane EH, and previously the one
     checkout row on Store → Modules that said "Not ported yet".

     The switch is real: off, this file produces nothing at all, not a hidden
     element. On with no wording saved it also produces nothing, which is what
     an untouched store gets — see App\Support\CheckoutLegalNotice for why the
     text has no default and the module still ships on.

     Placed inside order-block, immediately above Place order, which is where
     the registry's own hover card has always said it goes: "the privacy and
     terms notice above the place-order button". --}}
@if ($settings->moduleEnabled('legal_notice', true))
@php($kbbLegal = \App\Support\CheckoutLegalNotice::html($settings))
@if ($kbbLegal !== null)
    <p class="kbb-legal">{!! $kbbLegal !!}</p>
@endif
@endif
