<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Mail\CustomerEmails;
use App\Services\Mail\Kit\KitBlocks;
use App\Services\Mail\Kit\KitSamples;
use App\Services\Mail\Kit\KitSections;
use App\Services\Mail\Kit\EmailWording;
use App\Services\Mail\MailTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Emails → Customer emails (e3) and its template editor (e4) — Lane EK.
 *
 * Capabilities (docs/EMAILS-PLAN.md §6): reading is emails.view (owner,
 * manager), a test to yourself emails.test (owner, manager), every change
 * emails.manage (owner). Nothing here sends to a customer: "Test" goes to the signed-in
 * admin's own address, and the preview is drawn, never sent.
 *
 *   GET  customer                 the list, with each email's switch and last send
 *   POST customer/switch          {template, on} — the email's OWN module toggle
 *   GET  templates/{template}     the editor: sections, words, what can be added
 *   POST templates/{template}     {sections, words} — save
 *   POST templates/{template}/reset    back to the built-in order and English
 *   POST templates/{template}/test     the email, filled with the latest data, to me
 *
 * The live preview is GET|POST admin-api/emails/preview?template=… on
 * EmailsApiController — the endpoint Design & branding already uses.
 */
class EmailTemplatesController extends Controller
{
    public function __construct(private CustomerEmails $emails) {}

    public function customer(): JsonResponse
    {
        return response()->json(['rows' => $this->emails->rows()]);
    }

    public function setSwitch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template' => ['required', 'string', 'max:40'],
            'on' => ['required', 'boolean'],
        ]);

        if (! $this->emails->set($data['template'], (bool) $data['on'])) {
            return response()->json(['ok' => false, 'error' => 'That email has no switch of its own here.'], 422);
        }

        return response()->json(['ok' => true, 'rows' => $this->emails->rows()]);
    }

    public function show(string $template): JsonResponse
    {
        if (! isset(KitSections::TEMPLATES[$template])) {
            return response()->json(['message' => 'No such email.'], 404);
        }

        return response()->json($this->state($template));
    }

    public function save(Request $request, string $template): JsonResponse
    {
        if (! isset(KitSections::TEMPLATES[$template])) {
            return response()->json(['message' => 'No such email.'], 404);
        }

        $data = $request->validate([
            'sections' => ['sometimes', 'array', 'max:40'],
            'words' => ['sometimes', 'array'],
            'words.en' => ['sometimes', 'array'],
            'words.ar' => ['sometimes', 'array'],
        ]);

        // Words first: a refused word (a line break in a subject) saves
        // nothing, so the owner never ends up with half an edit.
        $refused = EmailWording::save($template, (array) ($data['words'] ?? []), $this->who($request));

        if ($refused !== []) {
            return response()->json(['ok' => false, 'refused' => $refused, 'error' => reset($refused)] + $this->state($template), 422);
        }

        if (isset($data['sections'])) {
            KitSections::save($template, ['sections' => $data['sections']], $this->who($request));
        }

        return response()->json(['ok' => true, 'refused' => []] + $this->state($template));
    }

    public function reset(string $template): JsonResponse
    {
        if (! isset(KitSections::TEMPLATES[$template])) {
            return response()->json(['message' => 'No such email.'], 404);
        }

        EmailWording::reset($template);
        KitSections::forget();

        return response()->json(['ok' => true] + $this->state($template));
    }

    public function test(Request $request, MailTester $tester, string $template): JsonResponse
    {
        if (! isset(KitSections::TEMPLATES[$template])) {
            return response()->json(['message' => 'No such email.'], 404);
        }

        $to = (string) ($request->user('admin')?->email ?? '');

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'Your admin account has no email address to send the test to.'], 422);
        }

        $mail = KitSamples::mailable($template);

        if ($mail === null) {
            return response()->json(['ok' => false, 'error' => 'There is no order yet to fill this email with.'], 422);
        }

        return response()->json($tester->send($to, $mail, 'test.' . $template));
    }

    private function state(string $template): array
    {
        $def = KitSections::TEMPLATES[$template];
        $row = collect($this->emails->rows())->firstWhere('template', $template) ?? [];

        return [
            'template' => $template,
            'name' => $def['name'],
            'when' => (string) ($row['when'] ?? ''),
            'header' => $def['header'],
            'note' => $def['note'] ?? null,
            'customised' => KitSections::customised($template),
            'sections' => KitSections::forEditor($template),
            'words' => EmailWording::fields($template),
            'edited_at' => EmailWording::updatedAt($template),
            'add' => array_map(static fn (string $t) => ['type' => $t, 'label' => $t === 'image' ? 'Image' : KitBlocks::TYPES[$t]], KitBlocks::SECTION_TYPES),
            // The approved order, for "Start from a ready template".
            'defaults' => array_keys($def['sections']),
            // What an added block may name — ids, read again at send time.
            'coupons' => \App\Models\Coupon::query()->orderBy('code')->limit(200)->get(['id', 'code'])->toArray(),
            'brands' => \App\Models\Brand::query()->orderBy('name')->get(['id', 'name'])->toArray(),
            'categories' => \App\Models\Category::query()->orderBy('name')->limit(300)->get(['id', 'name'])->toArray(),
        ];
    }

    private function who(Request $request): ?int
    {
        $id = $request->user('admin')?->getKey();

        return $id === null ? null : (int) $id;
    }
}
