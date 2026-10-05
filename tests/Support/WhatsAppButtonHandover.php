<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * docs/WA-ADMIN-APP-BLOCKS.md, parsed and applied.                    (Lane WA)
 *
 * ONE reader for the record the integrator applies, used by
 * tools/wa-apply-blocks.php (which writes) and WhatsAppButtonScreenTest (which
 * only computes), so the document, the tool and the pins cannot drift apart.
 *
 * The test pins the FINISHED console (CLAUDE.md: never assert NOT wired):
 * finished() is each target as it reads with every block in place — the file
 * itself where the integrator has applied a block, the block applied in memory
 * where he has not. So a pin is green in the lane, green after the merge, and
 * red on the two shapes that are real failures: a block applied twice, and a
 * block whose anchor has drifted so it can no longer be applied at all.
 */
final class WhatsAppButtonHandover
{
    public const DOC = 'docs/WA-ADMIN-APP-BLOCKS.md';

    private const TIMES = ['once' => 1, 'twice' => 2];

    /**
     * @return list<array{n:int, file:string, anchor:string, replacement:string, times:int}>
     */
    public static function blocks(string $base): array
    {
        $doc = (string) file_get_contents($base.'/'.self::DOC);
        $out = [];

        foreach (array_slice(preg_split('/^## Block /m', $doc) ?: [], 1) as $section) {
            preg_match('/^(\d+)/', $section, $n);
            preg_match('/\*\*File:\*\* `([^`]+)`/', $section, $file);
            preg_match('/\*\*Anchor\*\* \(occurs (once|twice)/', $section, $times);
            preg_match_all('/```\n(.*?)\n```/s', $section, $fences);

            if (! $n || ! $file || ! $times || count($fences[1]) !== 2) {
                throw new \RuntimeException('Malformed block in '.self::DOC.': '.substr($section, 0, 60));
            }

            $out[] = [
                'n' => (int) $n[1],
                'file' => $file[1],
                'anchor' => $fences[1][0],
                'replacement' => $fences[1][1],
                'times' => self::TIMES[$times[1]],
            ];
        }

        return $out;
    }

    /**
     * Every target with every block in place, plus what could not be applied.
     *
     * @return array{files: array<string, string>, problems: list<string>, applied: list<int>}
     */
    public static function finished(string $base): array
    {
        $files = [];
        $problems = [];
        $applied = [];

        foreach (self::blocks($base) as $b) {
            if (RetiredNavLiterals::superseded($b['file'], $b['replacement'])) {
                continue; // edited the retired NAV/LATE_NAV literals; the row is AdminNav's now (Lane AP)
            }

            $src = $files[$b['file']] ??= (string) file_get_contents($base.'/'.$b['file']);
            $r = substr_count($src, $b['replacement']);
            $a = substr_count($src, $b['anchor']);

            if ($r === $b['times']) {
                continue; // already in place
            }

            if ($r === 0 && $a === $b['times']) {
                $files[$b['file']] = str_replace($b['anchor'], $b['replacement'], $src);
                $applied[] = $b['n'];

                continue;
            }

            $problems[] = "block {$b['n']} ({$b['file']}): anchor occurs {$a} times, replacement {$r} times, "
                ."expected {$b['times']}";
        }

        return ['files' => $files, 'problems' => $problems, 'applied' => $applied];
    }
}
