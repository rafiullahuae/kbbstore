{{--
    T-CHROME-2. Hidden when the Notification Bar module takes over, exactly as in
    the theme — the module renders its own bar at the top of the page and two
    stacked bars would look like a mistake.
--}}
@unless ($kbbSettings->moduleEnabled('notification_bar'))
    <div class="anno">
        ✨ Free UAE delivery over <b>AED {{ $kbbFreeShipThreshold ? number_format($kbbFreeShipThreshold / 100, 0) : 199 }}</b>
        · Pay later with Tabby &amp; Tamara · <b>100% authentic</b> K-beauty
    </div>
@endunless
