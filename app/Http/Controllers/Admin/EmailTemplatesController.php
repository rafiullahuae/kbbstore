<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Mail\CustomerEmails;
use App\Services\Mail\Kit\KitBlocks;
use App\Services\Mail\Kit\KitSamples;
use App\Services\Mail\Kit\KitSections;
use App\Services\Mail\Kit\KitWords;
use App\Services\Mail\MailTester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Emails → Customer emails (e3) and its template editor (e4) — Lane EK.
 *
 * Every route here is `emails.templates` (owner only, like everything else
 * under Emails). Nothing here sends to a customer: "Test" goes to the signed-in
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

        if (isset($data['sections'])) {
            KitSections::save($template, ['sections' => $data['sections']], $this->who($request));
        }

        $refused = KitWords::save($template, (array) ($data['words'] ?? []));

        return response()->json(['ok' => $refused === [], 'refused' => $refused] + $this->state($template), $refused === [] ? 200 : 422);
    }

    public function reset(string $template): JsonResponse
    {
        if (! isset(KitSections::TEMPLATES[$template])) {
            return response()->json(['message' => 'No such email.'], 404);
        }

        KitSections::reset($template);
        KitWords::resetEnglish($template);

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
            'words' => KitWords::fields($template),
            'add' => array_map(static fn (string $t) => ['type' => $t, 'label' => KitBlocks::TYPES[$t]], KitBlocks::SECTION_TYPES),
        ];
    }

    private function who(Request $request): ?string
    {
        $admin = $request->user('admin');

        return $admin === null ? null : mb_substr((string) ($admin->email ?? $admin->name ?? ''), 0, 191);
    }
}
