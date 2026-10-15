<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The "Did you mean ...?" table: what kxeSuggest must answer for each address.
 *
 * Lane CE wrote it for the admin's customer dialog
 * (CustomerContactTypoHintTest); Lane EH moved it here, unchanged, so the
 * shopper checkout's copy of the same function (resources/js/kbb/email-suggest.js,
 * CheckoutEmailTypoHintTest) is held to the SAME rows rather than to a second
 * table that could drift. A row added here is a row both copies must answer.
 *
 * What a wrong answer looks like on the shop: a real yahoo.co.uk customer told
 * "Did you mean yahoo.com?", taps it, and stops receiving their order emails --
 * the failure the hint exists to repair, caused by it.
 */
final class EmailTypoCases
{
    /** @return array<string, string|null> typed => the full suggestion, or null */
    public static function all(): array
    {
        return [
            // ---- true fixes (the domain only; the local part is kept exactly)
            'jovana8b@yahoo.co' => 'jovana8b@yahoo.com',
            'Jovana8B@Yahoo.Co' => 'Jovana8B@yahoo.com',
            'a@gmial.com' => 'a@gmail.com',
            'a@gmal.com' => 'a@gmail.com',
            'a@gamil.com' => 'a@gmail.com',
            'a@gmail.con' => 'a@gmail.com',
            'a@gmail.cmo' => 'a@gmail.com',
            'a@gmail.comm' => 'a@gmail.com',
            'a@gmail.om' => 'a@gmail.com',
            'a@gmail.co' => 'a@gmail.com',
            'a@hotmial.com' => 'a@hotmail.com',
            'a@hotmal.com' => 'a@hotmail.com',
            'a@hotmail.con' => 'a@hotmail.com',
            'a@outlok.com' => 'a@outlook.com',
            'a@outlook.co' => 'a@outlook.com',
            'a@icloud.co' => 'a@icloud.com',
            'a@iclod.com' => 'a@icloud.com',
            'a@yaho.com' => 'a@yahoo.com',
            'a@yahooo.com' => 'a@yahoo.com',
            'a@yahoo.con' => 'a@yahoo.com',
            'a@hotmial.co.uk' => 'a@hotmail.co.uk',
            'a@rocketmial.com' => 'a@rocketmail.com',
            'a@protonmal.com' => 'a@protonmail.com',
            '  a@gmial.com  ' => 'a@gmail.com',

            // ---- must not touch: real providers and their country variants
            'a@yahoo.co.uk' => null,
            'a@yahoo.co.in' => null,
            'a@yahoo.co.jp' => null,
            'a@hotmail.fr' => null,
            'a@hotmail.co.uk' => null,
            'a@live.co.uk' => null,
            'a@outlook.sa' => null,
            'a@gmail.com' => null,
            'a@yahoo.com' => null,
            'a@live.com' => null,
            'a@me.com' => null,
            'a@emirates.net.ae' => null,
            'a@eim.ae' => null,
            'a@ymail.com' => null,
            'a@mail.com' => null,
            'a@email.com' => null,
            'a@googlemail.com' => null,
            'a@gmx.de' => null,
            'a@wahoo.com' => null,

            // ---- must not touch: company / custom / anything not near the list
            'a@company.co' => null,
            'a@company.con' => null,
            'a@kbeautybliss.com' => null,
            'a@extrabeauty.ae' => null,
            'a@adnoc.ae' => null,
            'a@emiratesnbd.com' => null,
            'a@du.ae' => null,

            // ---- short names: too many real neighbours for one edit to mean a typo
            'a@liv.com' => null,
            'a@msm.com' => null,
            'a@aoll.com' => null,

            // ---- ambiguous: two providers equally close -> nothing
            'a@xmail.com' => null,

            // ---- not an address we can judge
            'a@بريد.امارات' => null,
            'a@' => null,
            'a' => null,
            '' => null,
            '@gmial.com' => null,
            'a@gmial' => null,
            'a@gmial..com' => null,
        ];
    }
}
