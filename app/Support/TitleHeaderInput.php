<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SiteLayout;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * THE ONE DEFINITION of what a category or brand title header accepts from a
 * form. (Lane RA, lifted out of Admin\CategoriesApiController unchanged.)
 *
 * Two screens write these columns now: Catalog -> Categories -> Edit (Lanes PY
 * and QC) and the storefront's own quick-edit pencil (Lane RA). The brief for
 * the second said "no second, weaker validator", and the only way to make that
 * true by construction rather than by care is for both to call the same rules.
 * So the rule block and the clean-up step that sat inside CategoriesApiController
 * moved here VERBATIM, and that controller now spreads rules() into its own
 * list and hands its validated data to clean(). Nothing about what it accepts
 * changed; PyCategoryHeaderOptionsTest and QcCategoryHeaderDevicesTest are the
 * instruments that say so.
 *
 * Shapes are checked here; MEANING is checked by the one definition the
 * storefront also reads through -- TitleHeader::safeImage() for the picture
 * and TitleHeader::sanitizeStyle() for the look.
 */
final class TitleHeaderInput
{
    /** The fields a brand carries. Brands have no `header_style` column. */
    public const BRAND_FIELDS = ['header_image', 'header_title', 'header_subtitle', 'header_description'];

    /**
     * Every rule, keyed as a Laravel validator wants them.
     *
     * Every one of these is OPTIONAL IN THE REQUEST, and a key that is not sent
     * is left exactly as it is (see clean()).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'header_image' => ['nullable', 'string', 'max:2048'],
            'header_title' => ['nullable', 'string', 'max:300'],
            'header_subtitle' => ['nullable', 'string', 'max:300'],
            'header_description' => ['nullable', 'string', 'max:5000'],
            'header_style' => ['nullable', 'array'],
            'header_style.align' => ['nullable', 'string', Rule::in(TitleHeader::ALIGNS)],
            'header_style.treatment' => ['nullable', 'string', Rule::in(array_keys(SiteLayout::TREATMENTS))],
            'header_style.box' => ['nullable', 'string', Rule::in(array_keys(SiteLayout::BOX_STYLES))],
            'header_style.title_phone' => ['nullable', 'integer'],
            'header_style.title_desktop' => ['nullable', 'integer'],
            'header_style.h_phone' => ['nullable', 'integer'],
            'header_style.h_desktop' => ['nullable', 'integer'],
            /*
             * Lane QC -- "and for mobile also": the same choices per device,
             * each "Use the shop setting" when blank, plus where a phone cuts
             * the picture and the category's own box colours.
             */
            'header_style.align_phone' => ['nullable', 'string', Rule::in(TitleHeader::ALIGNS)],
            'header_style.align_desktop' => ['nullable', 'string', Rule::in(TitleHeader::ALIGNS)],
            'header_style.treatment_phone' => ['nullable', 'string', Rule::in(array_keys(SiteLayout::TREATMENTS))],
            'header_style.treatment_desktop' => ['nullable', 'string', Rule::in(array_keys(SiteLayout::TREATMENTS))],
            'header_style.box_phone' => ['nullable', 'string', Rule::in(array_keys(SiteLayout::BOX_STYLES))],
            'header_style.box_desktop' => ['nullable', 'string', Rule::in(array_keys(SiteLayout::BOX_STYLES))],
            'header_style.text_phone' => ['nullable', 'string', Rule::in(TitleHeader::TEXTS)],
            'header_style.text_desktop' => ['nullable', 'string', Rule::in(TitleHeader::TEXTS)],
            'header_style.valign_phone' => ['nullable', 'string', Rule::in(TitleHeader::VALIGNS)],
            'header_style.valign_desktop' => ['nullable', 'string', Rule::in(TitleHeader::VALIGNS)],
            'header_style.focus' => ['nullable', 'string', Rule::in(TitleHeader::FOCUSES)],
            'header_style.bg' => ['nullable', 'string', 'regex:/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/'],
            'header_style.ic' => ['nullable', 'string', 'regex:/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/'],
        ];
    }

    /**
     * The rules for a brand: the four text and picture fields, nothing else.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function brandRules(): array
    {
        return array_intersect_key(self::rules(), array_flip(self::BRAND_FIELDS));
    }

    /**
     * The title header's fields, each only when the request carried it.
     *
     * A key that IS sent and blank clears it -- that is how the owner removes an
     * imported title and goes back to the category name. The picture must be
     * something TitleHeader::safeImage() will print (an uploaded file or an
     * http(s) address); anything else -- `javascript:`, `data:` -- is refused
     * with the same sentence the category editor has always given.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function clean(array $data): array
    {
        if (array_key_exists('header_image', $data)) {
            $raw = trim((string) $data['header_image']);
            $safe = TitleHeader::safeImage($raw);

            if ($raw !== '' && $safe === null) {
                throw ValidationException::withMessages([
                    'header_image' => 'The header picture must be an uploaded file or an http(s) address.',
                ]);
            }

            $data['header_image'] = $safe;
        }

        foreach (['header_title', 'header_subtitle', 'header_description'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = trim((string) $data[$key]);
                $data[$key] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('header_style', $data)) {
            $style = TitleHeader::sanitizeStyle($data['header_style']);
            $data['header_style'] = $style === [] ? null : $style;
        }

        return $data;
    }
}
