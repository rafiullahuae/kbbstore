<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ModuleSchema;
use App\Services\SiteFooter;
use App\Services\NavigationService;
use App\Services\SettingsService;
use App\Services\SlimFooter;
use App\Support\FooterPages;
use App\Support\FooterPreviewSettings;
use App\Support\AdminCapabilities;
use App\Support\AdminRoles;
use App\Support\SocialProfiles;
use App\Models\AdminUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Footer.
 *
 * The field/tab loop is the one every module API controller in this project
 * has, kept in that shape deliberately: the admin screens that draw it are
 * generic, and a controller answering in a different shape would need a
 * renderer of its own.
 *
 * Two endpoints and nothing else. This screen picks no products and reads no
 * model — every value that crosses is a string, an integer or a boolean from a
 * schema both sides already know.
 *
 * ── TWO SCHEMAS ON ONE SCREEN (Lane HB) ─────────────────────────────────────
 *
 * The site footer on every page (App\Services\SiteFooter — the new design,
 * the switch back to the old one, the help strip, the addresses and the big
 * name) is drawn here too, in three tabs FIRST, because "Appearance → Footer"
 * is where the owner looks for the footer. Its keys all start `site_` and none
 * of SlimFooter's do, so one flat payload splits without ambiguity, and a key
 * belonging to neither is still refused with the same 422. Same endpoint, same
 * capability (`slimfooter.manage`): no new route.
 */
class SlimFooterApiController extends Controller
{
    public function __construct(private SlimFooter $footer) {}

    public function show(): JsonResponse
    {
        // One schema, drawn by one renderer. This loop used to be copied
        // into nine controllers that had to agree by hand.
        $tabs = array_merge(
            ModuleSchema::tabs(
                SiteFooter::SCHEMA,
                SiteFooter::TABS,
                app(SiteFooter::class)->all(),
                SiteFooter::POLICY,
            ),
            ModuleSchema::tabs(
                SlimFooter::SCHEMA,
                SlimFooter::TABS,
                $this->footer->all(),
                SlimFooter::POLICY,
            ),
        );

        /*
         * The squeeze list travels with the fields rather than being written
         * out again in the screen's JavaScript, for the reason CheckoutPage's
         * own screen states: two copies of a key list drift, and the copy that
         * drifts is the one in the file nobody opens.
         */
        /*
         * `pages` (Lane FT): the four pages the screen draws — Site footer ·
         * Desktop / · Mobile, Cart & Checkout footer · Desktop / · Mobile — as
         * sections of keys out of the fields above. The fields travel once, in
         * `tabs`, and a page only names them, so a shared key cannot arrive with
         * two different values. `shared` is the keys both devices read, which
         * the screen marks "applies to desktop and mobile".
         */
        return response()->json([
            'tabs' => $tabs,
            'squeeze' => SlimFooter::SQUEEZE,
            'pages' => FooterPages::pages(),
            'shared' => FooterPages::shared(),
            'socials' => $this->socials(),
        ]);
    }

    /**
     * Social profiles (Lane QK2): the GLOBAL `social_*` keys, not footer
     * copies, shown here because the footer is where their icons are drawn.
     *
     * Read only on this endpoint. The screen saves them through PUT
     * admin-api/settings — the door Store → SEO & Meta already uses, under its
     * own capability — so `editable` asks that exact route's capability rather
     * than restating it. A role holding `slimfooter.manage` but not that one
     * sees the addresses (they are printed on every shop page anyway) and gets
     * read-only boxes; the save itself is refused by EnforceAdminCapability
     * whatever the screen draws.
     *
     * @return array{fields: list<array{key:string,label:string,placeholder:string,hint:string,value:string}>, editable: bool, max: int}
     */
    private function socials(): array
    {
        $values = SocialProfiles::values(app(SettingsService::class));
        $fields = [];

        foreach (SocialProfiles::FIELDS as $key => [$label, $placeholder, $hint]) {
            $fields[] = ['key' => $key, 'label' => $label, 'placeholder' => $placeholder, 'hint' => $hint, 'value' => $values[$key]];
        }

        $admin = Auth::guard('admin')->user();

        return [
            'fields' => $fields,
            'editable' => $admin instanceof AdminUser
                && AdminRoles::can($admin, AdminCapabilities::forPath('PUT', 'admin-api/settings')),
            'max' => SocialProfiles::MAX,
        ];
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(
            array_keys($data['settings']),
            array_keys(SlimFooter::SCHEMA),
            array_keys(SiteFooter::SCHEMA),
        );

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $site = array_intersect_key($data['settings'], SiteFooter::SCHEMA);

        $this->footer->save(array_diff_key($data['settings'], SiteFooter::SCHEMA));

        if ($site !== []) {
            app(SiteFooter::class)->save($site);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST admin-api/slim-footer/preview — one footer page, drawn by the
     * shop's own partial from the values on the screen, saved or not.
     *
     * Capability `footer.preview` (AdminCapabilities). It writes nothing:
     * FooterPreviewSettings refuses set(), and the overlay is unbound in a
     * finally so the next thing this process renders reads the real settings.
     * Every key is one of the two schemas' own or the request is refused, and
     * each value is cast by its own service on the way to the page — the same
     * cast the save uses — so the preview cannot show anything Save would not
     * store.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['required', 'string', 'in:'.implode(',', array_keys(FooterPages::PAGES))],
            'settings' => ['nullable', 'array'],
        ]);

        $settings = (array) ($data['settings'] ?? []);
        $unknown = array_diff(array_keys($settings), array_keys(SlimFooter::SCHEMA), array_keys(SiteFooter::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $over = [];

        foreach ($settings as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $prefix = isset(SiteFooter::SCHEMA[$key]) ? SiteFooter::PREFIX : SlimFooter::PREFIX;
            $over[$prefix.$key] = $value;
        }

        $page = FooterPages::PAGES[$data['page']];
        $app = app();
        $real = $app->make(SettingsService::class);
        $overlay = new FooterPreviewSettings($real, $over);

        $app->instance(SettingsService::class, $overlay);

        try {
            $html = view('admin.previews.footer', [
                'ftpFooter' => $page['footer'],
                'ftpDevice' => $page['device'],
                'kbbSettings' => $overlay,
                'kbbFooterNav' => $page['footer'] === 'site'
                    ? $app->make(NavigationService::class)->filterVisible($app->make(NavigationService::class)->menu('footer'), false)
                    : [],
            ])->render();
        } finally {
            $app->instance(SettingsService::class, $real);
        }

        return response()->json(['ok' => true, 'html' => $html]);
    }
}
