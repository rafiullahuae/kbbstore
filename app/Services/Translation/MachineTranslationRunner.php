<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\Translation;
use App\Support\Locale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Spends the owner's money, in batches, into DRAFTS he has to approve.
 *
 * ── THE TWO RULES THIS CLASS EXISTS TO ENFORCE ──────────────────────────────
 *
 * 1. MACHINE OUTPUT IS NEVER PUBLISHED. Every row this writes is a draft, and
 *    a draft is invisible to shoppers — TranslationStore::map() reads published
 *    rows only. That is the mechanical half of the owner's "if we found
 *    anything incorrect, we can correct it manually": there is a stage at which
 *    a human has seen it, and the shop cannot skip that stage by accident.
 *
 *    It matters more here than on a normal shop. This catalogue is skincare.
 *    A machine translation of an ingredient list or a "not suitable for" line
 *    is a safety claim in a language nobody at the shop reads, published under
 *    the shop's name.
 *
 * 2. NOTHING THAT ALREADY HAS A TRANSLATION IS RE-SENT. Re-translating is money
 *    spent to overwrite a human's work with a machine's. A field is picked up
 *    only if it has no row at all, in any status.
 *
 * ── WHAT IS NOT SENT TO A MACHINE AT ALL ────────────────────────────────────
 *
 * isMachineSafe(): anything carrying markup. Product descriptions in this shop
 * are HTML, and both of the provider's format options are wrong for them —
 * `html` returns re-encoded tags and occasionally reorders attributes, `text`
 * translates the tag names. A mangled description is worse than an English one,
 * and it costs money to produce.
 *
 * So the machine does names, short descriptions and interface strings, and
 * descriptions are typed. The progress screen shows those as outstanding,
 * honestly, rather than as done.
 */
final class MachineTranslationRunner
{
    public function __construct(private readonly TranslationProvider $provider) {}

    /**
     * Is a string something a machine should be asked to translate?
     *
     * Markup, or nothing but digits and punctuation ("2024", "50"), which costs
     * characters and comes back unchanged. Something like "50ml" DOES go — it
     * has letters, and "50 مل" is a real translation of it.
     */
    public static function isMachineSafe(string $text): bool
    {
        $text = trim($text);

        if ($text === '') {
            return false;
        }

        if ($text !== strip_tags($text)) {
            return false;
        }

        return preg_match('/\p{L}/u', $text) === 1;
    }

    /**
     * Translate up to $limit outstanding fields into $locale.
     *
     * $limit is the owner's brake, not a technical one. It is what makes
     * "translate 400 products this month and the rest next month" — which is
     * how the shop stays inside Google's free allowance — a thing he can
     * actually do from a screen.
     *
     * @return array{requested: int, translated: int, characters: int, characters_sent: int, skipped: int, errors: list<string>}
     */
    public function run(string $locale, int $limit = 100, ?string $onlyGroup = null): array
    {
        if (! Locale::isSupported($locale) || $locale === Locale::DEFAULT) {
            throw new \InvalidArgumentException("Nothing to translate into '{$locale}'.");
        }

        if (! $this->provider->available()) {
            throw new \RuntimeException(
                'No machine-translation provider is configured. The manual path needs no key: '
                .'type the Arabic into the box beside the English one.'
            );
        }

        $pending = $this->pending($locale, $limit, $onlyGroup);

        if ($pending === []) {
            return [
                'requested' => 0, 'translated' => 0, 'characters' => 0,
                'characters_sent' => 0, 'skipped' => 0, 'errors' => [],
            ];
        }

        $translated = 0;
        $characters = 0;
        $sent = 0;
        $errors = [];

        foreach (array_chunk($pending, GoogleProvider::MAX_PER_REQUEST) as $batch) {
            $texts = array_column($batch, 'english');

            /*
             * Counted BEFORE the call and whatever the call answers.
             *
             * Google bills per character of source text it was handed. A batch
             * that comes back short, or that fails after the request was read,
             * is money spent — so the characters go on the receipt at the
             * moment they leave, not at the moment a row is written. Reporting
             * only what was stored told the owner he had used a third of what
             * his account had actually been charged, and the next estimate he
             * read was wrong by the difference.
             */
            $sent += array_sum(array_map(static fn (string $t): int => mb_strlen($t, 'UTF-8'), $texts));

            try {
                $results = $this->provider->translate($texts, Locale::DEFAULT, $locale);
            } catch (\Throwable $e) {
                // One failed batch must not lose the batches that succeeded —
                // those are already written and already paid for.
                $errors[] = $e->getMessage();

                continue;
            }

            /*
             * ORDER IS THE CONTRACT, AND THIS IS THE SIDE THAT CHECKS IT.
             *
             * Answers are paired to requests by index. GoogleProvider upholds
             * that by answering an index it could not translate with null IN
             * PLACE; its header says so in capitals. Nothing enforced it here —
             * so a provider that returned a COMPACTED array, which is what a
             * stray array_filter or a second implementation of this interface
             * produces, slid every answer one place up the batch and wrote each
             * product's Arabic copy onto the NEXT product's row.
             *
             * Nothing about the outcome would have looked wrong: ninety-nine
             * drafts written, no errors, the money spent, and ninety-nine
             * products carrying somebody else's name in Arabic — published one
             * Approve later, on a skincare catalogue.
             *
             * A count that does not match is a broken contract, and a broken
             * contract means NO row in the batch can be trusted, not merely the
             * missing one. The whole batch is dropped and reported. The
             * characters stay on the receipt above, because they were still
             * sent.
             */
            if (count($results) !== count($batch)) {
                $errors[] = 'The translation service answered a batch of '.count($batch)
                    .' with '.count($results).' rows. Nothing from that batch was stored: with rows '
                    .'missing there is no way to tell which translation belongs to which product, and '
                    .'a batch written one row out of step gives every product the next one\'s words.';

                continue;
            }

            foreach ($batch as $i => $slot) {
                $value = $results[$i] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                TranslationStore::put(
                    $locale,
                    $slot['group'],
                    $slot['item_id'],
                    $slot['field'],
                    $value,
                    Translation::STATUS_DRAFT,
                    Translation::SOURCE_MACHINE,
                    englishSource: $slot['english'],
                );

                $translated++;
                $characters += mb_strlen($slot['english'], 'UTF-8');
            }
        }

        return [
            'requested' => count($pending),
            'translated' => $translated,
            // What was STORED, which is what the progress screen grew by.
            'characters' => $characters,
            // What was SENT, which is what the bill will say. The two differ
            // by every batch that failed and every row answered with nothing.
            'characters_sent' => $sent,
            'skipped' => count($pending) - $translated,
            'errors' => $errors,
        ];
    }

    /**
     * Fields with English text, no translation of any kind, and safe to send.
     *
     * @return list<array{group: string, item_id: int, field: string, english: string}>
     */
    public function pending(string $locale, int $limit, ?string $onlyGroup = null): array
    {
        $existing = [];

        if (Schema::hasTable('translations')) {
            foreach (Translation::query()->where('locale', $locale)->get(['group', 'item_id', 'field']) as $row) {
                $existing[TranslationStore::slot((string) $row->group, (int) $row->item_id, (string) $row->field)] = true;
            }
        }

        $out = [];

        if ($onlyGroup === null || $onlyGroup === Translation::GROUP_UI) {
            foreach (InterfaceStrings::flat() as $key => $english) {
                if (count($out) >= $limit) {
                    return $out;
                }

                if (isset($existing[TranslationStore::slot(Translation::GROUP_UI, 0, $key)])) {
                    continue;
                }

                if (! self::isMachineSafe($english)) {
                    continue;
                }

                $out[] = ['group' => Translation::GROUP_UI, 'item_id' => 0, 'field' => $key, 'english' => $english];
            }
        }

        foreach (TranslationEstimate::CONTENT as $class => $columns) {
            /** @var Model $prototype */
            $prototype = new $class;
            $table = $prototype->getTable();

            if (! Schema::hasTable($table) || ($onlyGroup !== null && $onlyGroup !== $table)) {
                continue;
            }

            $present = array_values(array_filter(
                $columns,
                static fn (string $c): bool => Schema::hasColumn($table, $c)
            ));

            if ($present === []) {
                continue;
            }

            foreach ($class::query()->orderBy($prototype->getKeyName())->cursor() as $row) {
                if (count($out) >= $limit) {
                    return $out;
                }

                foreach ($present as $column) {
                    $english = $row->getAttribute($column);

                    if (! is_string($english) || ! self::isMachineSafe($english)) {
                        continue;
                    }

                    if (isset($existing[TranslationStore::slot($table, (int) $row->getKey(), $column)])) {
                        continue;
                    }

                    if (count($out) >= $limit) {
                        return $out;
                    }

                    $out[] = [
                        'group' => $table,
                        'item_id' => (int) $row->getKey(),
                        'field' => $column,
                        'english' => $english,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Translate ONE string and hand it straight back, storing nothing.
     *
     * The seam for the per-field Translate button that sits beside each Arabic
     * box in the product editor. It writes nothing on purpose: the text lands
     * in the box, the owner reads it and edits it, and it is stored by the same
     * ordinary save that stores everything else on that form. A button that
     * wrote a draft behind the form would leave the editor showing one thing
     * and the database holding another.
     */
    public function one(string $text, string $locale): ?string
    {
        if (! $this->provider->available()) {
            throw new \RuntimeException('No machine-translation provider is configured.');
        }

        if (! self::isMachineSafe($text)) {
            return null;
        }

        return $this->provider->translate([$text], Locale::DEFAULT, $locale)[0] ?? null;
    }
}
