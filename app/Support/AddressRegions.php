<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The first-level subdivisions a shopper picks from, per country (Lane AD).
 *
 * The owner, 7 October: "i need a selection of EMIRATES. for each country. if
 * country is UAE, all 7 emirates list should be there, if oman and so on, i
 * need the Emirates / States properly in list to make selection by user." And:
 * "for UAE and gulf countries, i need the list dual languages together", with a
 * screenshot of the old site's list reading "أبو ظبي — Abu Dhabi" and so on.
 *
 * ── WHAT IS STORED IS THE ENGLISH NAME, EXACTLY AS THE FREE-TEXT BOX STORED IT
 *
 * Every option's VALUE is the canonical English name ("Dubai", "Ras Al Khaimah")
 * -- the same string a shopper typed into the old box, the same one
 * OrderAddress::EMIRATES offers in the admin, and the one a state-level shipping
 * zone is written against (ShippingService::zoneFor() matches `AE:Dubai`, case
 * and all). Only the visible TEXT is bilingual. Orders, emails, invoices,
 * shipping zones and the WooCommerce history therefore read exactly what they
 * read before.
 *
 * ── THE ORDER IS THE OLD SITE'S ─────────────────────────────────────────────
 *
 * The UAE list is in the order of the owner's screenshot, which is the Arabic
 * alphabetical order the old WooCommerce site used; the other five Gulf lists
 * follow the same rule so the six read alike. Each row is
 * [value, Arabic, aliases, English display] -- the display only where the
 * owner's spelling differs from the stored value ("Ras al Khaimah").
 *
 * ── COUNTRIES WITHOUT A LIST KEEP THE FREE-TEXT BOX ─────────────────────────
 *
 * The six Gulf countries are the ones the shop's zones cover (ShippingSeeder).
 * Anything Extended delivery adds beyond them keeps the typed field, labelled
 * "State / region": a country with fifty states, or none, is better typed than
 * scrolled, and a list nobody verified is worse than no list.
 *
 * Pure data and string work: no query, no settings read, no cache.
 */
final class AddressRegions
{
    /**
     * Interface keys for the field's label, by kind.
     */
    public const LABELS = [
        'emirate' => 'store.checkout.field_state',
        'governorate' => 'store.checkout.field_state_governorate',
        'region' => 'store.checkout.field_state_region',
        'municipality' => 'store.checkout.field_state_municipality',
        'other' => 'store.checkout.field_state_other',
    ];

    /**
     * country => [kind, rows]; row = [value, Arabic, aliases, display?].
     *
     * Aliases are matched after normalise(): lower case, letters only, so
     * "Ras Al-Khaimah", "ras al khaimah" and "RasAlKhaimah" are one key. They
     * carry the ISO 3166-2 code with and without its country prefix (what a
     * WooCommerce export writes into `state`), the airport-style abbreviations
     * people type, and the spellings Google and the old site used.
     *
     * @var array<string, array{0: string, 1: list<array{0: string, 1: string, 2: list<string>, 3?: string}>}>
     */
    public const LISTS = [
        'AE' => ['emirate', [
            ['Abu Dhabi', 'أبو ظبي', ['AE-AZ', 'AZ', 'AUH', 'Abu Dhabi Emirate', 'Al Ain', 'ابوظبي', 'العين']],
            ['Sharjah', 'الشارقة', ['AE-SH', 'SH', 'SHJ']],
            ['Fujairah', 'الفجيرة', ['AE-FU', 'FU', 'FUJ', 'Fujeirah']],
            ['Umm Al Quwain', 'ام القيوين', ['AE-UQ', 'UQ', 'UAQ', 'Umm al Qaiwain', 'Umm Al Qaywayn']],
            ['Dubai', 'دبي', ['AE-DU', 'DU', 'DXB']],
            ['Ras Al Khaimah', 'رأس الخيمة', ['AE-RK', 'RK', 'RAK', 'Ras al Khaima'], 'Ras al Khaimah'],
            ['Ajman', 'عجمان', ['AE-AJ', 'AJ']],
        ]],
        'OM' => ['governorate', [
            ['Al Buraimi', 'البريمي', ['OM-BU', 'BU', 'Buraimi']],
            ['Al Dakhiliyah', 'الداخلية', ['OM-DA', 'DA', 'Ad Dakhiliyah', 'Dakhiliyah', 'Interior']],
            ['Al Dhahirah', 'الظاهرة', ['OM-ZA', 'ZA', 'Adh Dhahirah', 'Dhahirah']],
            ['Al Wusta', 'الوسطى', ['OM-WU', 'WU', 'Wusta']],
            ['South Al Batinah', 'جنوب الباطنة', ['OM-BJ', 'BJ', 'Al Batinah South', 'Janub al Batinah']],
            ['South Al Sharqiyah', 'جنوب الشرقية', ['OM-SJ', 'SJ', 'Ash Sharqiyah South', 'Janub ash Sharqiyah']],
            ['North Al Batinah', 'شمال الباطنة', ['OM-BS', 'BS', 'Al Batinah North', 'Shamal al Batinah']],
            ['North Al Sharqiyah', 'شمال الشرقية', ['OM-SS', 'SS', 'Ash Sharqiyah North', 'Shamal ash Sharqiyah']],
            ['Dhofar', 'ظفار', ['OM-ZU', 'ZU', 'Zufar', 'Salalah']],
            ['Muscat', 'مسقط', ['OM-MA', 'MA', 'Masqat', 'MCT']],
            ['Musandam', 'مسندم', ['OM-MU', 'MU']],
        ]],
        'SA' => ['region', [
            ['Al Bahah', 'الباحة', ['SA-11', '11', 'Baha', 'Al Baha']],
            ['Al Jawf', 'الجوف', ['SA-12', '12', 'Jawf', 'Al Jouf']],
            ['Northern Borders', 'الحدود الشمالية', ['SA-08', '08', 'Al Hudud ash Shamaliyah']],
            ['Riyadh', 'الرياض', ['SA-01', '01', 'Ar Riyad', 'RUH']],
            ['Eastern Province', 'الشرقية', ['SA-04', '04', 'Ash Sharqiyah', 'Eastern', 'Eastern Region', 'Dammam']],
            ['Al Qassim', 'القصيم', ['SA-05', '05', 'Qassim', 'Al Qasim']],
            ['Madinah', 'المدينة المنورة', ['SA-03', '03', 'Al Madinah', 'Medina', 'Al Madinah al Munawwarah']],
            ['Tabuk', 'تبوك', ['SA-07', '07']],
            ['Jazan', 'جازان', ['SA-09', '09', 'Jizan', 'Jazan']],
            ['Hail', 'حائل', ['SA-06', '06', "Ha'il"]],
            ['Asir', 'عسير', ['SA-14', '14', "'Asir", 'Aseer']],
            ['Makkah', 'مكة المكرمة', ['SA-02', '02', 'Mecca', 'Makkah al Mukarramah', 'Jeddah']],
            ['Najran', 'نجران', ['SA-10', '10']],
        ]],
        'QA' => ['municipality', [
            ['Umm Salal', 'أم صلال', ['QA-US', 'US', 'Umm Slal']],
            ['Al Khor and Al Thakhira', 'الخور والذخيرة', ['QA-KH', 'KH', 'Al Khor', 'Al Khawr', 'Al Khawr wa adh Dhakhirah']],
            ['Doha', 'الدوحة', ['QA-DA', 'DA', 'Ad Dawhah', 'DOH']],
            ['Al Rayyan', 'الريان', ['QA-RA', 'RA', 'Ar Rayyan', 'Rayyan']],
            ['Al Shahaniya', 'الشحانية', ['QA-SH', 'SH', 'Ash Shihaniyah', 'Shahaniya']],
            ['Al Shamal', 'الشمال', ['QA-MS', 'MS', 'Ash Shamal', 'Madinat ash Shamal']],
            ['Al Daayen', 'الضعاين', ['QA-ZA', 'ZA', "Az Za'ayin", 'Daayen']],
            ['Al Wakrah', 'الوكرة', ['QA-WA', 'WA', 'Wakrah', 'Al Wakra']],
        ]],
        'BH' => ['governorate', [
            ['Southern Governorate', 'المحافظة الجنوبية', ['BH-14', '14', 'Southern', 'Al Janubiyah']],
            ['Northern Governorate', 'المحافظة الشمالية', ['BH-17', '17', 'Northern', 'Ash Shamaliyah']],
            ['Capital Governorate', 'محافظة العاصمة', ['BH-13', '13', 'Capital', 'Al Asimah', 'Manama']],
            ['Muharraq Governorate', 'محافظة المحرق', ['BH-15', '15', 'Muharraq', 'Al Muharraq']],
        ]],
        'KW' => ['governorate', [
            ['Al Ahmadi', 'الأحمدي', ['KW-AH', 'AH', 'Ahmadi']],
            ['Al Jahra', 'الجهراء', ['KW-JA', 'JA', 'Jahra']],
            ['Al Asimah', 'العاصمة', ['KW-KU', 'KU', 'Capital', 'Kuwait City', 'Al Kuwayt']],
            ['Al Farwaniyah', 'الفروانية', ['KW-FA', 'FA', 'Farwaniya', 'Al Farwaniya']],
            ['Hawalli', 'حولي', ['KW-HA', 'HA', 'Hawally']],
            ['Mubarak Al-Kabeer', 'مبارك الكبير', ['KW-MU', 'MU', 'Mubarak al Kabir']],
        ]],
    ];

    /**
     * First Strong Isolate / Pop Directional Isolate around the Arabic half,
     * so its direction is settled on its own and cannot pull the dash or the
     * English into its run, on an LTR page or an RTL one. An <option> cannot
     * carry a <bdi>, so the characters are the only isolate it can hold.
     */
    private const FSI = "\u{2068}";

    private const PDI = "\u{2069}";

    /** @var array<string, array<string, string>>|null normalised alias => value, per country */
    private static ?array $index = null;

    public static function has(?string $country): bool
    {
        return isset(self::LISTS[strtoupper((string) $country)]);
    }

    /** The label's interface key for this country. */
    public static function labelKey(?string $country): string
    {
        $kind = self::LISTS[strtoupper((string) $country)][0] ?? 'other';

        return self::LABELS[$kind];
    }

    /** @return list<string> the stored values, in list order */
    public static function values(string $country): array
    {
        return array_map(static fn (array $r): string => $r[0], self::LISTS[strtoupper($country)][1] ?? []);
    }

    /**
     * value => visible text, "Arabic — English", for one country.
     *
     * @return array<string, string>
     */
    public static function options(string $country): array
    {
        $out = [];

        foreach (self::LISTS[strtoupper($country)][1] ?? [] as $row) {
            $out[$row[0]] = self::FSI.$row[1].self::PDI.' — '.($row[3] ?? $row[0]);
        }

        return $out;
    }

    /**
     * The list's own value for whatever was typed or imported, or null.
     *
     * "dubai", "DXB", "AE-DU", "DU" and "دبي" are all Dubai. Null for a
     * country with no list, an empty value, or a value no row answers to.
     */
    public static function canonical(?string $country, ?string $value): ?string
    {
        $country = strtoupper((string) $country);
        $key = self::normalise((string) $value);

        if ($key === '' || ! isset(self::LISTS[$country])) {
            return null;
        }

        return self::index()[$country][$key] ?? null;
    }

    /**
     * The first of several candidates that names a row: the saved state, then
     * the saved city -- an address saved through the cart's popup carries the
     * emirate in its city box, and a WooCommerce one may carry "Al Barsha" as
     * the state and "Dubai" as the city.
     */
    public static function guess(?string $country, ?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $hit = self::canonical($country, $candidate);

            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * Everything the page's script needs to swap the list when the country
     * changes, for the countries the page offers: code => [label, [[value,
     * Arabic(, English shown)], ...]] -- the shown English only where it is
     * not the value, so no name is sent twice. Printed once, as JSON; the
     * browser builds each option's text exactly as options() does, with no
     * request.
     *
     * @param  iterable<string>  $countries
     * @return array<string, array{0: string, 1: list<list<string>>}>
     */
    public static function clientMap(iterable $countries): array
    {
        $out = [];

        foreach ($countries as $code) {
            $code = strtoupper((string) $code);

            if (! isset(self::LISTS[$code])) {
                continue;
            }

            $opts = [];
            foreach (self::LISTS[$code][1] as $row) {
                $opts[] = isset($row[3]) ? [$row[0], $row[1], $row[3]] : [$row[0], $row[1]];
            }

            $out[$code] = [__(self::labelKey($code)), $opts];
        }

        return $out;
    }

    /**
     * Lower case, letters and digits only, Arabic alef and teh marbuta folded,
     * and a trailing "emirate"/"governorate"/"region"/"province" dropped.
     */
    public static function normalise(string $value): string
    {
        $v = mb_strtolower(trim($value), 'UTF-8');
        $v = strtr($v, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);
        $v = (string) preg_replace('/\b(emirate of|emirate|governorate|municipality|province|region)\b/u', ' ', $v);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $v);
    }

    /** @return array<string, array<string, string>> */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];

        foreach (self::LISTS as $country => [, $rows]) {
            foreach ($rows as $row) {
                foreach (array_merge([$row[0], $row[1], $row[3] ?? $row[0]], $row[2]) as $spelling) {
                    $key = self::normalise($spelling);

                    if ($key !== '') {
                        $index[$country][$key] ??= $row[0];
                    }
                }
            }
        }

        return self::$index = $index;
    }
}
