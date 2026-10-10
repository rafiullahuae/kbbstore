<?php

declare(strict_types=1);

namespace App\Services\Marketing;

/**
 * The READY marketing templates and the preset customer groups the shop ships
 * (Lane MK). Seeded by migration — templates as read-only presets the owner
 * Uses (a campaign draft from a copy) or Duplicates (an editable copy under
 * "My templates"); groups as editable rows.
 *
 * The owner, 4 October: "The marketing emails will have also pre-marketing
 * templates for new arrivals, this week special, best sellers etc etc. and can
 * be editable easily."
 *
 * EVERY PRODUCT BLOCK FILLS ITSELF at send time (ProductFill): no product,
 * price or picture is written into a template, so a template made today is
 * still right next season. A coupon block ships with no coupon chosen and is
 * left out of the email until one is; the Review & send checklist says so.
 *
 * Three of them are the approved campaign emails, block for block:
 * docs/rj-email-previews/marketing/ m1 (Autumn glow), m2 (We miss you) and
 * m3 (Brand fans — "this group's top brand").
 */
final class TemplateLibrary
{
    /**
     * key => [name, category, description, subject, preheader, blocks]
     *
     * @return array<string, array{name:string, category:string, description:string, subject:string, preheader:string, blocks:list<array{type:string, props:array<string,mixed>}>}>
     */
    public static function templates(): array
    {
        $b = static fn (string $type, array $props = []) => Blocks::make($type, $props);
        $head = $b('mini_header');
        $foot = $b('footer');

        return [
            'new-arrivals' => [
                'name' => 'New arrivals',
                'category' => 'Products',
                'description' => 'The newest products in the shop, filled in when it is sent.',
                'subject' => 'Just landed: new K-beauty this week',
                'preheader' => 'The newest arrivals at K-Beauty Bliss, picked for you.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'spark', 'eyebrow' => 'Just landed', 'title' => 'New in this week', 'lead' => 'Fresh arrivals from the brands you love — authentic, and delivered across the UAE in 1–3 days.']),
                    $b('product_grid', ['fill' => 'newest', 'order' => 'newest', 'count' => 4, 'cta' => 'Shop now']),
                    $b('button', ['label' => 'See everything new', 'href' => '/new-in/']),
                    $foot,
                ],
            ],
            'weekly-special' => [
                'name' => "This week's special",
                'category' => 'Offers',
                'description' => 'Products on sale right now, biggest saving first, with an optional coupon.',
                'subject' => "This week's special is here",
                'preheader' => 'Prices down on favourites — this week only.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'star', 'tone' => 'amber', 'eyebrow' => 'This week only', 'title' => "This week's special", 'lead' => 'Our favourites at their best prices of the week. When they are gone, they are gone.']),
                    $b('product_grid', ['fill' => 'on_sale', 'order' => 'biggest_saving', 'count' => 4, 'cta' => 'Shop now']),
                    $b('coupon', ['line' => 'An extra treat on top of this week\'s prices', 'expires' => 'This week only']),
                    $b('button', ['label' => 'Shop the specials', 'href' => '/super-sale/']),
                    $foot,
                ],
            ],
            'best-sellers' => [
                'name' => 'Best sellers',
                'category' => 'Products',
                'description' => 'The products that sell most, by units sold.',
                'subject' => 'Our best sellers, loved by thousands',
                'preheader' => 'The K-beauty our customers come back for again and again.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'heart', 'eyebrow' => 'Most loved', 'title' => 'Our best sellers', 'lead' => 'The products our customers come back for, again and again.']),
                    $b('product_grid', ['fill' => 'best_sellers', 'order' => 'best_sellers', 'count' => 4, 'cta' => 'Shop now']),
                    $b('button', ['label' => 'See all best sellers', 'href' => '/best-sellers/']),
                    $foot,
                ],
            ],
            'under-54' => [
                'name' => 'Under AED 54',
                'category' => 'Offers',
                'description' => 'Best sellers that cost less than AED 54.',
                'subject' => 'Little luxuries, all under AED 54',
                'preheader' => 'Authentic K-beauty that costs less than you think.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'bag', 'eyebrow' => 'Little luxuries', 'title' => 'Everything under AED 54', 'lead' => 'Authentic K-beauty that costs less than you think.']),
                    $b('product_grid', ['fill' => 'under_price', 'max_price' => 54, 'order' => 'best_sellers', 'count' => 4, 'cta' => 'Shop now']),
                    $b('button', ['label' => 'Shop under AED 54', 'href' => '/everything-under-54-aed/']),
                    $foot,
                ],
            ],
            'super-sale' => [
                'name' => 'Super Sale',
                'category' => 'Offers',
                'description' => 'The deepest discounts in the shop, biggest saving first.',
                'subject' => 'Super Sale: our biggest savings are on',
                'preheader' => 'The deepest discounts in the shop, while stocks last.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'gift', 'tone' => 'red', 'eyebrow' => 'Super Sale', 'title' => 'Our biggest savings are on', 'lead' => 'The deepest discounts in the shop — while stocks last.']),
                    $b('product_grid', ['fill' => 'on_sale', 'order' => 'biggest_saving', 'count' => 6, 'cta' => 'Shop now']),
                    $b('button', ['label' => 'Shop the Super Sale', 'href' => '/super-sale/']),
                    $foot,
                ],
            ],
            'bundles-sets' => [
                'name' => 'Bundles & sets',
                'category' => 'Products',
                'description' => 'Ready-made routines and sets from the catalogue.',
                'subject' => 'Better together: bundles & sets',
                'preheader' => 'Complete routines in one box — and they cost less together.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'gift', 'eyebrow' => 'Better together', 'title' => 'Bundles & sets', 'lead' => 'Complete routines in one box, put together by brands that know what works.']),
                    $b('product_grid', ['fill' => 'sets', 'order' => 'best_sellers', 'count' => 4, 'cta' => 'See the set']),
                    $b('button', ['label' => 'Shop all sets', 'href' => '/shop/']),
                    $foot,
                ],
            ],
            'brand-spotlight' => [
                'name' => 'Brand spotlight',
                'category' => 'Brands',
                'description' => 'One brand, its best sellers. Choose the brand in the product block.',
                'subject' => 'Brand spotlight: why we love it',
                'preheader' => 'A closer look at one of our favourite brands.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'star', 'eyebrow' => 'Brand spotlight', 'title' => 'Meet the brand', 'lead' => 'A closer look at one of the brands our customers love most — and the products to start with.']),
                    $b('product_grid', ['fill' => 'brand', 'order' => 'best_sellers', 'count' => 4, 'cta' => 'Shop now']),
                    $b('button', ['label' => 'See every product', 'href' => '/brands/']),
                    $foot,
                ],
            ],
            'we-miss-you' => [
                'name' => 'We miss you',
                'category' => 'Win back',
                'description' => 'For customers who have not ordered in a while (m2). Pair it with "Lapsed 90 days".',
                'subject' => 'It has been a while — here is 10% off',
                'preheader' => 'It has been a while — here is 10% off your next order.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'heart', 'eyebrow' => 'It has been a while', 'title' => 'We saved you something', 'lead' => 'Your skin has changed since your last order — so has our shelf. Here is 10% off to come and see.']),
                    $b('coupon', ['line' => '10% off your next order', 'expires' => 'Valid 14 days · one use per customer']),
                    $b('product_grid', ['title' => 'New since your last visit', 'fill' => 'newest', 'order' => 'newest', 'count' => 2, 'cta' => 'Shop now']),
                    $b('button', ['label' => 'Come back and shop', 'href' => '/shop/']),
                    $foot,
                ],
            ],
            'brand-fans' => [
                'name' => 'Brand fans',
                'category' => 'Brands',
                'description' => 'Fills itself with the chosen group\'s top brand (m3) — e.g. "Mostly bought Medicube".',
                'subject' => 'More {top_brand}, just for you',
                'preheader' => 'New {top_brand} picks, chosen because you love the brand.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'spark', 'eyebrow' => 'Picked for you', 'title' => 'More {top_brand}, just for you', 'lead' => 'You keep coming back to {top_brand}, so here is what is new and best-loved from the brand.']),
                    $b('product_grid', ['fill' => 'group_top_brand', 'order' => 'best_sellers', 'count' => 4, 'cta' => 'Shop now']),
                    $b('coupon', ['line' => '10% off {top_brand} this week', 'expires' => '']),
                    $b('button', ['label' => 'Shop all {top_brand}', 'href' => Blocks::TOP_BRAND_URL]),
                    $foot,
                ],
            ],
            'autumn-glow' => [
                'name' => 'Seasonal: Autumn glow',
                'category' => 'Seasonal',
                'description' => 'A seasonal edit with a banner, four picks and a code (m1).',
                'subject' => 'Autumn Glow Edit',
                'preheader' => '15% off the glow edit this week — your code is inside.',
                'blocks' => [
                    $head,
                    $b('hero_image', ['art' => 'autumn-glow']),
                    $b('heading', ['style' => 'title', 'title' => 'Hi {first_name|there}, your glow edit is here', 'lead' => 'Four of this season’s most-loved picks for dewy, even skin.']),
                    $b('product_grid', ['fill' => 'best_sellers', 'order' => 'best_sellers', 'count' => 4, 'cta' => 'Shop now']),
                    $b('coupon', ['line' => '15% off everything in the Glow Edit', 'expires' => 'Ends Sunday, midnight']),
                    $b('button', ['label' => 'Shop the Glow Edit', 'href' => '/shop/']),
                    $foot,
                ],
            ],
            'welcome' => [
                'name' => 'Welcome new subscriber',
                'category' => 'Welcome',
                'description' => 'A first hello for newsletter subscribers, with best sellers to start with.',
                'subject' => 'Welcome to K-Beauty Bliss',
                'preheader' => 'Authentic K-beauty, fast delivery and free samples in every order.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'heart', 'eyebrow' => 'Welcome', 'title' => 'Welcome to the K-Beauty Bliss family', 'lead' => 'Thank you for joining us. Here is what to expect from every order.']),
                    $b('text', ['align' => 'center', 'body' => "**Fast delivery** · 1–3 days, all over the UAE\n**100% original** · straight from the brand\n**Free samples** · random K-beauty samples in every order"]),
                    $b('product_grid', ['title' => 'Start with our best sellers', 'fill' => 'best_sellers', 'order' => 'best_sellers', 'count' => 4, 'cta' => 'Shop now']),
                    $b('coupon', ['line' => 'A welcome gift on your first order', 'expires' => '']),
                    $b('button', ['label' => 'Start shopping', 'href' => '/shop/']),
                    $foot,
                ],
            ],
            'skincare-tips' => [
                'name' => 'Skincare tips',
                'category' => 'Content',
                'description' => 'The latest journal posts, filled in when it is sent, and a few products.',
                'subject' => 'Skincare tips from our journal',
                'preheader' => 'Fresh advice from the K-Beauty Bliss journal.',
                'blocks' => [
                    $head,
                    $b('heading', ['icon' => 'spark', 'eyebrow' => 'From the journal', 'title' => 'Skincare tips for this week', 'lead' => 'A few minutes of reading for better skin — straight from our journal.']),
                    $b('columns', ['count' => '2', 'source' => 'latest_posts', 'cta' => 'Read more']),
                    $b('button', ['label' => 'Read the journal', 'href' => '/skincare-guide/', 'style' => 'ghost']),
                    $b('product_row', ['title' => 'What our readers are buying', 'fill' => 'best_sellers', 'order' => 'best_sellers', 'count' => 3, 'cta' => 'Shop now']),
                    $foot,
                ],
            ],
            /*
             * DESIGN C, "PLAYFUL K-BEAUTY" (Lane EC). The owner picked it from
             * Lane ED's "New Look, Less Prices" previews on 10 October — "this
             * is finalized" — block for block: the tagline above the card, the
             * highlighter headline and its intro, the still life, "Start
             * shopping", "Fresh picks for less" over six pastel cards (3 across,
             * 2 on a phone), the four benefit chips, the dark "Shop the new
             * prices" and the footer. The cards fill themselves (Super Sale,
             * biggest saving first); switch the block to Manual to pick them.
             * No returns anywhere: the shop does not offer them.
             */
            'new-look' => self::newLook('en'),
            'new-look-ar' => self::newLook('ar'),
        ];
    }

    /**
     * The other subject lines a ready template offers beside its own (the
     * builder shows them as one-tap suggestions). Lane ED's three for C.
     *
     * @return list<string>
     */
    public static function subjectIdeas(?string $key): array
    {
        return match ($key) {
            'new-look' => ['We got a glow-up, and so did the prices 🌸', 'New look, less prices: fresh picks inside', 'Your favourite K-beauty shop just got prettier'],
            'new-look-ar' => ['جدّدنا إطلالتنا… وخفّضنا الأسعار 🌸', 'إطلالة جديدة وأسعار أقل: مختارات طازجة بانتظارك', 'متجرك الكوري المفضّل صار أجمل'],
            default => [],
        };
    }

    /** The look and language a ready template is made in (EmailTheme); standard English unless said. */
    public static function lookOf(?string $key): array
    {
        $t = $key !== null ? (self::templates()[$key] ?? null) : null;

        return ['theme' => (string) ($t['theme'] ?? 'standard'), 'locale' => (string) ($t['locale'] ?? 'en')];
    }

    /** @return array{name:string, category:string, description:string, subject:string, preheader:string, theme:string, locale:string, blocks:list<array{type:string, props:array<string,mixed>}>} */
    private static function newLook(string $locale): array
    {
        $b = static fn (string $type, array $props = []) => Blocks::make($type, $props);
        $ar = $locale === 'ar';
        $w = $ar ? [
            'name' => 'New look, less prices (Arabic)',
            'description' => 'Design C in Arabic, right to left: the same blocks and products, the Arabic words.',
            'subject' => self::subjectIdeas('new-look-ar')[0],
            'preheader' => 'تجدّد متجرنا وانخفضت الأسعار. ست مختارات جديدة بانتظارك.',
            'tagline' => '✿ متجرنا بحلّة جديدة ✿',
            'title' => 'إطلالة جديدة،', 'highlight' => 'وأسعار أقل',
            'lead' => "جدّدنا متجرنا بالكامل، وصارت منتجات العناية الكورية التي تحبينها ألطف على ميزانيتك. تعالي وألقي\u{00A0}نظرة\u{00A0}💕",
            'alt' => 'لاصقات العيون من numbuzin وبخاخ Anua وسيروم Anua TXA وجرعة فيتامين C من Arencia على دوائر بألوان الباستيل',
            'start' => 'ابدئي التسوّق ←',
            'picks' => 'مختارات جديدة بأسعار أقل', 'picksLead' => 'ست لمسات فاخرة صغيرة، بأسعارها الجديدة',
            'cta' => 'تسوّقي الآن',
            'badges' => [
                ['icon' => 'truck', 'bold' => 'توصيل مجاني', 'text' => 'للطلبات فوق AED 199'],
                ['icon' => 'bolt', 'bold' => 'خلال 1–3 أيام', 'text' => 'في جميع أنحاء الإمارات'],
                ['icon' => 'card', 'bold' => 'تابي، تمارا', 'text' => '، البطاقة أو الدفع نقدًا'],
                ['icon' => 'sparkles', 'bold' => 'أصلية 100%', 'text' => '، من كوريا مباشرة'],
            ],
            'end' => 'تسوّقي الأسعار الجديدة',
            'note' => 'صُنعت بحب (وكثير من السيروم) في دبي ✿',
        ] : [
            'name' => 'New look, less prices',
            'description' => 'Design C · Playful K-beauty: highlighter headline, six pastel product cards, four benefit chips. Products fill themselves (Super Sale) or pick them by hand.',
            'subject' => self::subjectIdeas('new-look')[0],
            'preheader' => 'We got a glow-up, and so did the prices. Six fresh picks are waiting.',
            'tagline' => '✿ glow-up alert ✿',
            'title' => 'New look,', 'highlight' => 'less prices',
            'lead' => "We gave our shop a glow-up, and your favourite K-beauty got friendlier on the wallet. Come and have a look\u{00A0}around\u{00A0}💕",
            'alt' => Blocks::ART['new-look-c']['alt'],
            'start' => 'Start shopping →',
            'picks' => 'Fresh picks for less', 'picksLead' => 'Six little luxuries, newly priced',
            'cta' => 'Shop now',
            'badges' => Blocks::BADGE_DEFAULTS,
            'end' => 'Shop the new prices',
            'note' => 'Made with love (and a lot of serum) in Dubai ✿',
        ];

        return [
            'name' => $w['name'],
            'category' => 'Seasonal',
            'description' => $w['description'],
            'subject' => $w['subject'],
            'preheader' => $w['preheader'],
            'theme' => 'playful',
            'locale' => $locale,
            'blocks' => [
                $b('mini_header', ['topbar' => true, 'nav' => false, 'tagline' => $w['tagline']]),
                $b('heading', ['style' => 'hero', 'icon' => 'none', 'eyebrow' => '', 'title' => $w['title'], 'highlight' => $w['highlight'], 'lead' => $w['lead']]),
                $b('hero_image', ['art' => 'new-look-c', 'alt' => $w['alt'], 'href' => '/super-sale/']),
                $b('button', ['label' => $w['start'], 'href' => '/super-sale/']),
                $b('heading', ['style' => 'title', 'icon' => 'blossom', 'title' => $w['picks'], 'lead' => $w['picksLead']]),
                $b('product_grid', ['fill' => 'on_sale', 'order' => 'biggest_saving', 'count' => 6, 'columns' => '3', 'layout' => 'playful', 'cta' => $w['cta']]),
                $b('badges', ['items' => $w['badges']]),
                $b('button', ['label' => $w['end'], 'href' => '/super-sale/', 'style' => 'dark']),
                $b('footer', ['note' => $w['note']]),
            ],
        ];
    }

    /**
     * key => [name, audience, match, rules] — plan §3's presets.
     *
     * @return array<string, array{name:string, audience:string, match:string, rules:list<array{field:string, op:string, value:mixed}>}>
     */
    public static function groups(): array
    {
        return [
            'never-ordered' => ['name' => 'Never ordered', 'audience' => 'customers', 'match' => 'all', 'rules' => [['field' => 'never_ordered', 'op' => 'yes', 'value' => null]]],
            'one-order' => ['name' => 'One order only', 'audience' => 'customers', 'match' => 'all', 'rules' => [['field' => 'orders', 'op' => 'eq', 'value' => 1]]],
            'repeat-buyers' => ['name' => 'Repeat buyers (2+ orders)', 'audience' => 'customers', 'match' => 'all', 'rules' => [['field' => 'orders', 'op' => 'gte', 'value' => 2]]],
            'vip' => ['name' => 'VIP (spent AED 1,000+)', 'audience' => 'customers', 'match' => 'all', 'rules' => [['field' => 'spent', 'op' => 'gte', 'value' => 1000]]],
            'lapsed-90' => ['name' => 'Lapsed 90 days', 'audience' => 'customers', 'match' => 'all', 'rules' => [['field' => 'last_order', 'op' => 'more_than_days', 'value' => 90]]],
            'all-customers' => ['name' => 'All customers', 'audience' => 'customers', 'match' => 'all', 'rules' => []],
            'newsletter-subscribers' => ['name' => 'All confirmed subscribers', 'audience' => 'subscribers', 'match' => 'all', 'rules' => []],
        ];
    }
}
