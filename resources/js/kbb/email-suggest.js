/**
 * "Did you mean …?" for an email address: the full corrected address, or null.
 * (Lane EH, from Lane CE's admin hint.)
 *
 * THE SAME FUNCTION AS THE ADMIN'S, NOT A SECOND OPINION. The block between the
 * markers below is Lane CE's kxeSuggest, copied byte for byte (indentation
 * aside) from resources/views/admin/partials/customer-contact-edit.blade.php,
 * where it runs inline in the console. Two copies, because the console partial
 * is inline script and this one is a module the shop's bundle imports; ONE
 * answer, because CheckoutEmailTypoHintTest cuts both blocks out by their
 * markers, requires them to be identical, and runs both through node over
 * CustomerContactTypoHintTest's whole table. Change one and not the other and
 * the build goes red.
 *
 * The owner: "hints are fine, but must be accurate." The rules are Lane CE's
 * and are explained beside them: an allowlist of real providers is the only
 * place a hint points, real country variants and real neighbours are never
 * "corrected", and an unknown or ambiguous domain gets no opinion at all.
 *
 * Loaded only by email-fix.js, which checkout.js imports dynamically and only
 * when the page carries the switch's marker -- a shop with the switch off
 * never fetches this file.
 */

/*kxe-suggest-start*/
/* Real mail providers common in the UAE / GCC: the ONLY places a hint points. */
var KXE_PROVIDERS = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'me.com', 'msn.com', 'aol.com',
  'yahoo.co.uk', 'hotmail.co.uk', 'emirates.net.ae', 'eim.ae', 'ymail.com', 'rocketmail.com', 'protonmail.com', 'proton.me',
  'mail.ru', 'yandex.ru', 'gmx.com'];
/* Real domains that sit near a provider and must never be "corrected":
   the providers' own country variants, and real neighbours (mail.com is a
   provider one letter from gmail.com). */
var KXE_KEEP = ['yahoo.co.in', 'yahoo.co.jp', 'yahoo.co.id', 'yahoo.co.nz', 'yahoo.co.za', 'yahoo.co.kr', 'yahoo.co.th',
  'yahoo.com.au', 'yahoo.com.sg', 'yahoo.com.ph', 'yahoo.com.my', 'yahoo.com.br', 'yahoo.com.hk', 'yahoo.com.tw', 'yahoo.com.ar', 'yahoo.com.mx', 'yahoo.com.vn',
  'yahoo.fr', 'yahoo.de', 'yahoo.es', 'yahoo.it', 'yahoo.ca', 'yahoo.in', 'yahoo.ie', 'yahoo.gr', 'yahoo.se', 'yahoo.dk', 'yahoo.no', 'yahoo.pl',
  'hotmail.fr', 'hotmail.de', 'hotmail.it', 'hotmail.es', 'hotmail.be', 'hotmail.nl', 'hotmail.ca', 'hotmail.se', 'hotmail.no', 'hotmail.dk', 'hotmail.gr', 'hotmail.ch', 'hotmail.co.jp', 'hotmail.co.za', 'hotmail.com.au', 'hotmail.com.br', 'hotmail.com.tr',
  'outlook.sa', 'outlook.fr', 'outlook.de', 'outlook.es', 'outlook.it', 'outlook.be', 'outlook.ie', 'outlook.in', 'outlook.jp', 'outlook.kr', 'outlook.co.id', 'outlook.co.il', 'outlook.co.th', 'outlook.com.au', 'outlook.com.br', 'outlook.com.tr', 'outlook.com.vn', 'outlook.com.ar', 'outlook.ph', 'outlook.sg', 'outlook.my', 'outlook.at', 'outlook.cz', 'outlook.dk', 'outlook.pt', 'outlook.cl', 'outlook.hu', 'outlook.lv', 'outlook.sk',
  'live.co.uk', 'live.fr', 'live.de', 'live.it', 'live.nl', 'live.be', 'live.ca', 'live.se', 'live.no', 'live.dk', 'live.ie', 'live.at', 'live.cl', 'live.in', 'live.com.au', 'live.com.mx', 'live.com.ar', 'live.com.pt', 'live.co.za',
  'gmx.de', 'gmx.net', 'gmx.at', 'gmx.ch', 'gmx.fr', 'gmx.us', 'gmx.co.uk', 'googlemail.com', 'mail.com', 'email.com', 'yandex.com', 'yandex.ua', 'yandex.kz', 'yandex.by', 'inbox.ru', 'list.ru', 'bk.ru',
  'aim.com', 'mac.com', 'wahoo.com'];
/* The mistyped top-level parts the owner listed. Fixed ONLY when the rest is a provider. */
var KXE_TLD_TYPOS = ['con', 'cmo', 'comm', 'om', 'co'];

/* Optimal-string-alignment distance: one swap ("gmial") is one edit. */
function kxeDistance(a, b) {
  var d = [], i, j;
  for (i = 0; i <= a.length; i++) { d[i] = [i]; }
  for (j = 0; j <= b.length; j++) { d[0][j] = j; }
  for (i = 1; i <= a.length; i++) {
    for (j = 1; j <= b.length; j++) {
      var cost = a.charAt(i - 1) === b.charAt(j - 1) ? 0 : 1;
      d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
      if (i > 1 && j > 1 && a.charAt(i - 1) === b.charAt(j - 2) && a.charAt(i - 2) === b.charAt(j - 1)) {
        d[i][j] = Math.min(d[i][j], d[i - 2][j - 2] + 1);
      }
    }
  }
  return d[a.length][b.length];
}

/* The full corrected address, or null. Never touches the part before @. */
function kxeSuggest(email) {
  var s = String(email == null ? '' : email).trim();
  var at = s.lastIndexOf('@');
  if (at < 1) return null;
  var local = s.slice(0, at);
  var domain = s.slice(at + 1).toLowerCase();
  /* ASCII domains only: an IDN, a space, or anything odd gets no opinion. */
  if (!/^[a-z0-9-]+(\.[a-z0-9-]+)+$/.test(domain)) return null;
  if (KXE_PROVIDERS.indexOf(domain) !== -1 || KXE_KEEP.indexOf(domain) !== -1) return null;

  var found = [];
  var dot = domain.indexOf('.');
  var name = domain.slice(0, dot), suffix = domain.slice(dot + 1);

  /* 1. A mistyped ending on a provider's own name: gmail.con, yahoo.co. */
  if (KXE_TLD_TYPOS.indexOf(suffix) !== -1) {
    KXE_PROVIDERS.forEach(function (p) {
      if (p === name + '.com') found.push(p);
    });
    return found.length === 1 ? local + '@' + found[0] : null;
  }

  /* 2. One slip in a provider's name, with the ending exactly right:
        gmial.com, hotmial.co.uk. Names under five letters (live, me, msn,
        aol, gmx, eim, mail) have too many real neighbours for one edit to
        mean a typo, so they are only ever reached by rule 1. Two providers
        equally close is no suggestion at all. */
  var best = 2, bestList = [];
  KXE_PROVIDERS.forEach(function (p) {
    var pd = p.indexOf('.');
    var pName = p.slice(0, pd), pSuffix = p.slice(pd + 1);
    if (pSuffix !== suffix || pName.length < 5) return;
    var dist = kxeDistance(name, pName);
    if (dist > 1) return;
    if (dist < best) { best = dist; bestList = [p]; }
    else if (dist === best) { bestList.push(p); }
  });
  return bestList.length === 1 ? local + '@' + bestList[0] : null;
}
/*kxe-suggest-end*/

export { kxeSuggest };
