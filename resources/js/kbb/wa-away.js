/*
 * The floating WhatsApp button steps aside while the footer's help strip is in
 * its corner of the screen. The owner, 9 October: "the support strip and the
 * floating whatsapp icon, must not overlap ... the floating whatsapp icon
 * function should hide immidiately temporary".
 *
 * NOTHING HERE MEASURES LAYOUT. One IntersectionObserver watches the strip
 * against the BOTTOM 14% of the viewport (rootMargin -86% top), which is
 * where the button sits; the browser reports the crossing, and one class on
 * <html> does the hiding in CSS (kbb.css, beside the install bar's identical
 * rule). The strip anywhere higher up the screen leaves the button alone.
 * No strip, no button, or no IntersectionObserver: nothing happens.
 * FooterWhatsAppStepsAsideTest.
 */
export function initWaAway() {
    const strip = document.querySelector('.kft-help');

    if (!strip || !document.getElementById('kbbWa') || typeof IntersectionObserver !== 'function') {
        return;
    }

    const root = document.documentElement;

    new IntersectionObserver((entries) => {
        root.classList.toggle('kft-near', entries[entries.length - 1].isIntersecting);
    }, { rootMargin: '-86% 0px 0px 0px' }).observe(strip);
}
