/**
 * App\Services\PageHeaders::compile(), in JavaScript. (Lane PH)
 *
 * The storefront "Edit header" panel and the console's preview redraw the
 * header from this as the owner clicks, with no request. It must agree with
 * the PHP byte for byte, so PageHeaderTest runs both on the same bags and
 * compares the output: change one without the other and that test is red.
 *
 * Pure: no DOM, no imports, so Node can run it.
 */

export const ELEMENTS = { crumb: 'c', image: 'i', title: 't', intro: 'p', button: 'b' };

/**
 * @param {object} bag  {d:{...}, m:{...}, img, img_m, alt}
 * @param {string} kind 'collection' or 'page'
 * @param {{intro?:boolean, image?:boolean, crumb?:{d?:boolean, m?:boolean}}} has
 * @returns {{wrap:string, style:string, cls:Object<string,string>}}
 */
export function compile(bag, kind, has) {
    const isList = kind === 'collection';
    let wrap = isList ? 'sh kbb-ph' : 'kbb-ph kbb-ph-pg';
    const cls = {
        crumb: 'crumb kbb-ph-c',
        image: 'kbb-ph-i',
        title: 'kbb-ph-t',
        count: 'cnt',
        intro: 'kbb-ph-p',
        button: 'lnk kbb-ph-b',
    };
    const style = [];

    for (const dev of ['d', 'm']) {
        const h = bag[dev];
        const present = {
            crumb: !!h.crumb && !(has && has.crumb && has.crumb[dev] === false),
            image: !!(has && has.image) && !!h.image,
            title: !!h.title,
            intro: isList && !!(has && has.intro) && !!h.intro,
            button: isList && !!h.button,
        };
        const side = isList && h.button_at === 'side' && present.button;

        const rows = [];
        for (const el of h.order) {
            let row = null;
            if (el === 'title') row = present.title ? (side ? 't b' : 't t') : (side ? '. b' : null);
            else if (el === 'button') row = present.button && !side ? 'b b' : null;
            else if (present[el]) row = `${ELEMENTS[el]} ${ELEMENTS[el]}`;
            if (row !== null) rows.push(`"${row}"`);
        }

        style.push(`--ph-a${dev}:${rows.length ? rows.join(' ') : 'none'}`);
        style.push(`--ph-h${dev}:${h.img_h}px`);
        style.push(`--ph-f${dev}:${h.fit}`);
        style.push(`--ph-r${dev}:${h.radius}px`);
        style.push(`--ph-g${dev}:${h.gap}px`);
        style.push(`--ph-s${dev}:${h.space}px`);

        if (h.align === 'center') wrap += ` kbb-ph-c${dev}`;
        if (side) wrap += ` kbb-ph-s${dev}`;
        if (isList && !h.dot) wrap += ` kbb-ph-no${dev}`;
        // (Lane FW) Full width, only where a picture is drawn on this device.
        if (present.image && h.width === 'full') wrap += ` kbb-ph-w${dev}`;
        if (!h.title) cls.title += ` kbb-ph-v${dev}`;
        for (const el of ['crumb', 'image', 'intro', 'button']) {
            if (!present[el]) cls[el] += ` kbb-ph-h${dev}`;
        }
        if (!h.count) cls.count += ` kbb-ph-h${dev}`;
    }

    if (!bag.d.title && !bag.m.title) cls.title = 'kbb-ph-t kbb-ph-v';

    return { wrap, style: style.join(';'), cls };
}

/**
 * App\Services\PageHeaders::topStyle(), in JavaScript. (Lane SP3)
 * The top area's spacing as custom properties: above the first block,
 * between the header area and the strip, and below the last. PageHeaderTest
 * runs both on the same bags and compares.
 *
 * @param {object} bag
 * @returns {string}
 */
export function topStyle(bag) {
    const out = [];
    for (const dev of ['d', 'm']) {
        const h = bag[dev];
        out.push(`--pt-t${dev}:${Math.trunc(Number(h.top) || 0)}px`);
        out.push(`--pt-g${dev}:${Math.trunc(Number(h.mid) || 0)}px`);
        out.push(`--pt-b${dev}:${Math.trunc(Number(h.space) || 0)}px`);
    }
    return out.join(';');
}
