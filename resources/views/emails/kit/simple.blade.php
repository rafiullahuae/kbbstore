{{--
    The approved shape of every email that is not about one order's money —
    tools/rj-build-after.cjs simple(), ported (Lane EM): the topbar, one card
    (header, then the child's @section('kit_inner')), the footer.

    WHAT A CHILD VIEW SETS: $k (MailKit::for($brand)), $kitTitle,
    $kitPreheader, $kitWhy (plain), $kitNav (bool, default true: the header's
    Shop · Track order · My account; the two account-security emails drop it),
    $kitUnsubscribe (null, or the signed opt-out URL the Mailable was handed).
--}}
@extends('emails.kit.doc')

@section('kit')
@include('emails.kit.topbar')
@include('emails.kit.card-open')
@include('emails.kit.header', ['nav' => $kitNav ?? true])
@yield('kit_inner')
@include('emails.kit.card-close')
@include('emails.kit.footer', ['why' => $kitWhy, 'unsubscribeUrl' => $kitUnsubscribe ?? null])
@endsection
