{{--
    THE EXIT POPUP ON THE SHOP (Lane GO). Built, and OFF by default (the
    owner: "build the popup function also but keep turned off by default").

    $goPop  GiftOffers::popup(): null while the popup is off (no query), for
            an admin, an empty basket, a basket already holding a gift, or a
            range that gives nothing -- and then this prints NOTHING and no
            script is ever loaded.

    With it: an inert <template> (the real popup markup and its styles, parsed
    but never rendered until needed) and a ~600-byte inline watcher. The
    watcher waits for real exit intent -- the mouse leaving through the top of
    the window on a computer; leaving the tab or app and coming back on a
    phone -- at most once per session, not for 24 hours after "Maybe later",
    never after a submit (Place order) and, with "Checkout" chosen as the
    audience, only after the shopper did something on the checkout. Only then
    is the popup script (resources/js/kbb/gift-offer-popup.js, ~2 KB) fetched,
    async. Nothing in the head, nothing render-blocking, nothing measured.
    Inside the cart panel's fragment, so a basket change redraws the gift for
    the new range.
--}}@if (! empty($goPop))<template data-kgo-pop data-r="{{ $goPop['range_id'] }}" data-aud="{{ $goPop['audience'] }}" data-min="{{ $goPop['minutes'] }}" data-claim="{{ \App\Support\Url::to('/gift-offer/popup') }}" data-ev="{{ \App\Support\Url::to('/gift-offer/event') }}" data-js="{{ $goPop['js'] }}">@include('partials.gift-offers.popup-css')@include('partials.gift-offers.popup-card', ['p' => $goPop])</template><script>(function(d,w){if(w.kbbGoB)return;w.kbbGoB=1;var S,L;try{S=w.sessionStorage;L=w.localStorage}catch(e){}function g(s,k){try{return s.getItem(k)}catch(e){return null}}function p(s,k,v){try{s.setItem(k,v)}catch(e){}}if(/\/(checkout\/(success|order-pay)|my-account|account|login)/.test(location.pathname))return;if(g(S,'kbbGoShown')||Date.now()-(+g(L,'kbbGoNo')||0)<864e5)return;var busy=0,hid=0;d.addEventListener('submit',function(){busy=1},true);if(/\/checkout\/?$/.test(location.pathname)){var a=function(){p(S,'kbbGoAct','1')};d.addEventListener('change',a,true);d.addEventListener('input',a,true)}function fire(why){var t=d.querySelector('template[data-kgo-pop]');if(!t||busy||g(S,'kbbGoShown'))return;if(t.getAttribute('data-aud')==='checkout'&&!g(S,'kbbGoAct'))return;p(S,'kbbGoShown','1');d.removeEventListener('mouseout',mo);d.removeEventListener('visibilitychange',vc);if(w.kbbGo&&w.kbbGo.open)return w.kbbGo.open(why);var s=d.createElement('script');s.src=t.getAttribute('data-js');s.async=true;s.onload=function(){w.kbbGo&&w.kbbGo.open(why)};d.body.appendChild(s)}function mo(e){if(!e.relatedTarget&&e.clientY<=0)fire('exit')}function vc(){if(d.visibilityState==='hidden')hid=1;else if(hid)fire('return')}d.addEventListener('mouseout',mo);d.addEventListener('visibilitychange',vc)})(document,window)</script>@endif
