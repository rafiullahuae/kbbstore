<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UgcSection;
use App\Services\ModuleSchema;
use App\Services\Ugc\RailPlayback;
use App\Services\UgcRail;
use App\Services\UgcSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Video rail.
 *
 * ONE SCHEMA, DRAWN BY ONE RENDERER. This whole show() body used to be fifteen
 * lines copied into nine controllers that had to agree by hand — see
 * docs/M-PHASE3-SETTINGS-SCHEMA.md, and the sixteen colour fields across four
 * modules that each stored a hex with no `#` because there were four copies of
 * the same three lines and nothing tied them together.
 *
 * So there is no cast() here, no field loop and no validation list: every value
 * goes through ModuleSchema::write(), which is the one security boundary for
 * every migrated module. Rule 5's "a select stores one of its own options or the
 * default" is that method's job, applied from UgcSettings::POLICY.
 */
class UgcAppearanceController extends Controller
{
    public function __construct(
        private UgcSettings $settings,
        private RailPlayback $playback,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'tabs' => ModuleSchema::tabs(
                UgcSettings::SCHEMA,
                UgcSettings::TABS,
                $this->settings->all(),
                UgcSettings::POLICY,
            ),
            /*
             * SAID ON THE SCREEN, because every control here is inert without it
             * and "I moved the sliders and nothing happened" is the support call
             * this one line prevents.
             */
            'module_on' => $this->settings->enabled(),
            /*
             * ── WHAT THE STOREFRONT WILL ACTUALLY DO, IN NUMBERS ────────────
             *
             * The owner, three rounds running: "on front-end it still not auto
             * play". Every round measured a seeded rail, found it looping, and
             * said so. The rail WAS looping — on that data. On his, four
             * `(Demo)` clips stood at the head of the section and `max_playing`
             * is 4, so the two clips he had uploaded were never reached.
             *
             * `module_on` above is the precedent and the same argument: a
             * control that is inert has to say so on the screen that draws it.
             * This is the same sentence for the case where every control is
             * correct and the rail still does not move, which is the one nobody
             * could see. App\Services\Ugc\RailPlayback has the reasoning; it is
             * read-only and runs nowhere near the storefront.
             */
            'playback' => $this->playback->describe(),
            /*
             * ── THE LIST THE HOMEPAGE DROPDOWN IS DRAWN FROM (Lane IG) ──────
             *
             * The owner: "let us choose the section to show from the list or use
             * shortcode". `home_section` is a `text` field on the schema, for the
             * reason UgcSettings' own docblock gives — a `select` would put a
             * query on the storefront's hot path for a value only the homepage
             * reads — so the LIST is handed over here instead, beside the fields,
             * and the console renders a real <select> from it.
             *
             * ── ONE QUERY, AND WHAT IT DELIBERATELY DOES NOT FILTER ─────────
             *
             * `published()` is NOT applied and neither is forLocale(). A draft
             * section is exactly the thing an owner is about to publish and wants
             * to pick now, and an Arabic-only section is a legitimate choice on a
             * bilingual shop. So every section is offered and its STATUS is
             * carried beside it, which is what the sections screen already does
             * with the shortcode — the screen can grey a draft row and say why,
             * where a filtered list would just be missing the row he is looking
             * for with nothing to explain it.
             *
             * Three narrow columns. Reading `*` here would pull every heading and
             * subheading translation blob out of the table to print a handle.
             */
            'sections' => UgcSection::query()
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'handle', 'title', 'status', 'locale'])
                ->map(fn (UgcSection $s) => [
                    'handle' => (string) $s->handle,
                    // The operator's own label, escaped by the console before it
                    // is printed. Never the shopper-facing heading: this is a
                    // picker, and `title` is what the sections list calls it.
                    'title' => (string) ($s->title ?? ''),
                    'published' => $s->getAttribute('status') === 'publish',
                    'locale' => $s->locale === null ? null : (string) $s->locale,
                ])
                ->all(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(UgcSettings::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        /*
         * ── `home_section` IS CHECKED AGAINST THE REAL SECTIONS, HERE ────────
         *
         * Rule 5: "a select stores one of its own options or the default". This
         * field is a `text` on the schema so that the storefront does not pay a
         * query to read it (see UgcSettings' docblock), which means ModuleSchema
         * cannot enforce membership for it — there is no option set to enforce
         * against. So the enforcement is HERE, which is the one place in the
         * application that already has the list in hand.
         *
         * REFUSED RATHER THAN SILENTLY DEFAULTED, and that is the opposite of
         * this module's POLICY for every other field. The reason is that the two
         * cases are not alike: a `cols` of "banana" is a value nobody typed and
         * the shipped default is the right answer, while a handle is something
         * the owner PICKED — so if it does not exist, the honest answers are
         * "that section is gone" or "you have a stale tab open", and quietly
         * storing '' would empty his homepage rail and tell him it saved.
         *
         * The empty string passes, because it is the real "show nothing" value
         * and the dropdown's first option.
         */
        $handle = $data['settings']['home_section'] ?? null;

        if (is_string($handle) && trim($handle) !== '') {
            $handle = trim($handle);

            $exists = preg_match(UgcSection::HANDLE_RE, $handle) === 1
                && UgcSection::query()->where('handle', $handle)->exists();

            if (! $exists) {
                return response()->json([
                    'ok' => false,
                    'error' => 'There is no video section with the handle “'.$handle.'”. '
                        .'Pick one from the list, or create it in Content → Video sections first.',
                ], 422);
            }

            $data['settings']['home_section'] = $handle;
        }

        $result = $this->settings->save($data['settings']);

        /*
         * The rail cache holds CONTENT and not settings — UgcRail's header says so
         * and that is deliberate, so moving a slider takes effect on the next page
         * load with nothing to clear. It is flushed anyway, and the reason is
         * `metrics_age`: the age gate is applied outside the cache, but a future
         * setting that did reach the payload would be a silent ten-minute lie, and
         * a flush on a screen somebody saves by hand costs nothing at all.
         */
        UgcRail::flush();

        return response()->json([
            'ok' => true,
            'written' => $result['written'],
            // A refused value is REPORTED rather than dropped in silence. A save
            // that quietly discarded a bad number is the fault ModuleSchema exists
            // to remove.
            'rejected' => $result['rejected'],
        ]);
    }
}
