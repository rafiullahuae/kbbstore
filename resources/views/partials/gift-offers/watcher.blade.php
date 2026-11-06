{{--
    The exit popup's watcher (~600 bytes), printed ONCE per page by
    partials/drawers whenever the popup is on and this visitor may see it
    (GiftOffers::popupArmed(), no query) -- with or without a gift yet. It
    waits for exit intent and then looks for the gift's <template
    data-kgo-pop>, which the cart panel carries when the bag's range gives
    one (partials/gift-offers/popup). Before 2.60.647 the watcher lived inside
    the panel, so a product added on the page (the panel redrawn without
    running scripts) never armed it, and ANY form submit -- "Add to bag" is
    one -- paused it for the page; only Place order (#kbbCheckoutForm) does
    now. The owner saw no popup on his phone.
--}}
<script>(function(d,w){if(w.kbbGoB)return;w.kbbGoB=1;var S,L;try{S=w.sessionStorage;L=w.localStorage}catch(e){}function g(s,k){try{return s.getItem(k)}catch(e){return null}}function p(s,k,v){try{s.setItem(k,v)}catch(e){}}if(/\/(checkout\/(success|order-pay)|my-account|account|login)/.test(location.pathname))return;if(g(S,'kbbGoShown')||Date.now()-(+g(L,'kbbGoNo')||0)<864e5)return;var busy=0,hid=0;d.addEventListener('submit',function(e){if(e.target&&e.target.id==='kbbCheckoutForm')busy=1},true);if(/\/checkout\/?$/.test(location.pathname)){var a=function(){p(S,'kbbGoAct','1')};d.addEventListener('change',a,true);d.addEventListener('input',a,true)}function fire(why){var t=d.querySelector('template[data-kgo-pop]');if(!t||busy||g(S,'kbbGoShown'))return;if(t.getAttribute('data-aud')==='checkout'&&!g(S,'kbbGoAct'))return;p(S,'kbbGoShown','1');d.removeEventListener('mouseout',mo);d.removeEventListener('visibilitychange',vc);if(w.kbbGo&&w.kbbGo.open)return w.kbbGo.open(why);var s=d.createElement('script');s.src=t.getAttribute('data-js');s.async=true;s.onload=function(){w.kbbGo&&w.kbbGo.open(why)};d.body.appendChild(s)}function mo(e){if(!e.relatedTarget&&e.clientY<=0)fire('exit')}function vc(){if(d.visibilityState==='hidden')hid=1;else if(hid)fire('return')}d.addEventListener('mouseout',mo);d.addEventListener('visibilitychange',vc)})(document,window)</script>
