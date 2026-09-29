<?php

declare(strict_types=1);

namespace App\Services\Media;

use Symfony\Component\HttpFoundation\File\Exception\CannotWriteFileException;
use Symfony\Component\HttpFoundation\File\Exception\ExtensionFileException;
use Symfony\Component\HttpFoundation\File\Exception\FormSizeFileException;
use Symfony\Component\HttpFoundation\File\Exception\IniSizeFileException;
use Symfony\Component\HttpFoundation\File\Exception\NoTmpDirFileException;
use Symfony\Component\HttpFoundation\File\Exception\PartialFileException;

/**
 * Why an upload did not land, decided in ONE place.
 *
 * ── THE SENTENCE THIS FILE EXISTS TO END ────────────────────────────────────
 *
 * MediaUploadController::upload() had two failure lines and both were fixed
 * strings:
 *
 *     'Could not create upload directory.'
 *     'Upload failed — check folder permissions.'
 *
 * The second one is the interesting one, because it NAMES A CAUSE. A shared
 * host that has run out of disk, a read-only mount after a failed deploy, a
 * php.ini with no `upload_tmp_dir`, and a genuinely unwritable uploads folder
 * all arrived at the owner as the same six words telling him to go and look at
 * permissions — which, in three of those four cases, are perfectly correct.
 * That is the `UgcTranscoder::run()` defect exactly: the system said which one
 * it was, in one line, and the screen threw the line away.
 *
 * ── AND THE SECOND LINE COULD NOT FIRE AT ALL ───────────────────────────────
 *
 * It was written as `if (!$file->move($dir, $filename) || ...)`. Symfony's
 * UploadedFile::move() does not return false — it returns a File or it THROWS
 * (vendor/symfony/http-foundation/File/UploadedFile.php: `if (!$moved) { throw
 * new FileException(...) }`). A File object is truthy, so the guard's first
 * term was dead and every real move failure left upload() as an uncaught
 * exception.
 *
 * With debug off, which is how the live shop runs, that is a bare "Server
 * Error" on the Media Library — no sentence at all, not even the wrong one.
 * So the owner's actual experience of a full disk was a blank failure, and the
 * message this file replaces was one he could only have seen if `is_file()`
 * disagreed with a move that had just succeeded.
 *
 * ── A KEY, NOT A SENTENCE, IS WHAT THE CALLER GETS ──────────────────────────
 *
 * The shape `UgcTranscoder::reason()` established: classify() is a PURE
 * function of the facts and returns one of the constants below, so every branch
 * is assertable without a full disk, without a read-only mount and without a
 * box in any particular state. sentence() turns a key into the owner's words.
 * The two are separate because the key is also what a test, a log line and a
 * future screen want, and none of those want a paragraph.
 *
 * ── THE SYSTEM'S OWN WORDS TRAVEL WITH IT ───────────────────────────────────
 *
 * classify() cannot know every errno a host can produce, and UNKNOWN must not
 * be another dead end. So the caller appends the redacted system message to the
 * sentence, and redact() is what makes that safe: an errno string from mkdir()
 * or a Symfony FileException both carry ABSOLUTE SERVER PATHS, and
 * HealthApiController and UgcVideoController::probeNote() both establish that a
 * path off this box never reaches a screen.
 */
final class UploadFault
{
    /** The disk this shop writes to is full. */
    public const DISK_FULL = 'disk_full';

    /** The folder exists and this PHP process may not write into it. */
    public const PERMISSION = 'permission';

    /** The filesystem is mounted read-only — nothing will write anywhere. */
    public const READ_ONLY = 'read_only';

    /** Something that is not a folder is sitting where the folder must go. */
    public const NOT_A_DIRECTORY = 'not_a_directory';

    /** PHP had nowhere to put the incoming file while it arrived. */
    public const NO_TMP = 'no_tmp';

    /** PHP's own upload limits refused the file before this code saw it. */
    public const TOO_BIG_FOR_PHP = 'too_big_for_php';

    /** The browser stopped sending partway through. */
    public const INCOMPLETE = 'incomplete';

    /** Something else. The system's own words are the whole of the answer. */
    public const UNKNOWN = 'unknown';

    /**
     * Which fault a system error message describes.
     *
     * PURE, and matched on the errno text because that is all `mkdir()` and
     * `move_uploaded_file()` leave behind — PHP puts the C library's strerror()
     * into the warning, so "No space left on device" and "Permission denied"
     * arrive verbatim. Matched case-insensitively and by substring, because the
     * surrounding wording differs between the two functions and between PHP
     * versions while the strerror() fragment does not.
     *
     * ORDER MATTERS, for the reason UgcTranscoder::reason() gives about ffmpeg:
     * a full disk reports EDQUOT/ENOSPC and can ALSO make a later write look
     * like a permission problem, so the disk is named first. Naming permissions
     * on a full disk sends somebody to chmod a folder that is already correct.
     */
    public static function classify(string $systemMessage): string
    {
        $haystack = strtolower($systemMessage);

        $table = [
            self::DISK_FULL => ['no space left on device', 'disk quota exceeded', 'quota exceeded'],
            self::READ_ONLY => ['read-only file system', 'read only file system'],
            self::NOT_A_DIRECTORY => ['not a directory', 'file exists'],
            self::NO_TMP => ['no such file or directory', 'failed to open stream: no such file'],
            self::PERMISSION => ['permission denied', 'operation not permitted', 'access denied'],
        ];

        foreach ($table as $key => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $key;
                }
            }
        }

        return self::UNKNOWN;
    }

    /**
     * The same question asked of a throwable.
     *
     * BY CLASS FIRST. Symfony raises a distinct exception type for each
     * UPLOAD_ERR_* constant, and the type is a fact where the message is prose:
     * NoTmpDirFileException means `upload_tmp_dir` however it is worded. Only
     * when the type carries no such meaning — a plain FileException from the
     * move itself — is the message consulted, and then through classify(), so
     * the two paths cannot disagree about what "Permission denied" means.
     */
    public static function fromThrowable(\Throwable $e): string
    {
        return match (true) {
            $e instanceof NoTmpDirFileException, $e instanceof CannotWriteFileException => self::NO_TMP,
            $e instanceof IniSizeFileException, $e instanceof FormSizeFileException => self::TOO_BIG_FOR_PHP,
            $e instanceof PartialFileException => self::INCOMPLETE,
            $e instanceof ExtensionFileException => self::UNKNOWN,
            default => self::classify($e->getMessage()),
        };
    }

    /**
     * What to say about a fault, in the owner's words.
     *
     * $stage is 'folder' or 'file' — the same fault reads differently depending
     * on whether the shop could not MAKE the uploads folder or could not write
     * INTO it, and the remedy differs with it. Anything else is treated as
     * 'file', because the file is the stage every caller reaches.
     *
     * Each sentence names the fault and then what to do about it. None of them
     * names a path: the folder is always `public/uploads/<something>` relative
     * to the web root, which is a thing the owner can find, and the absolute
     * location of that web root is exactly what must not be printed.
     */
    public static function sentence(string $key, string $stage = 'file'): string
    {
        $folder = $stage === 'folder';

        return match ($key) {
            self::DISK_FULL => 'This server has run out of disk space, so nothing could be written. '
                .'That is not a permissions problem and it will affect orders and logs as well as uploads — '
                .'free space up or raise the plan\'s quota, then try again.',
            self::READ_ONLY => 'This server\'s filesystem is mounted read-only, so nothing can be written '
                .'anywhere — not just this upload. That usually follows a failed deploy or a disk fault, and '
                .'it needs the host, not a settings change here.',
            self::NOT_A_DIRECTORY => $folder
                ? 'Something that is not a folder is already sitting where the uploads folder has to go, '
                .'so the folder could not be created. Look in public/uploads for a FILE with the folder\'s '
                .'name and remove or rename it.'
                : 'The place this file had to be written is not a folder. Look in public/uploads for a file '
                .'sitting where a folder should be, and remove or rename it.',
            self::NO_TMP => 'PHP had nowhere to keep the file while it arrived — its temporary upload '
                .'directory is missing or unwritable. That is a PHP setting (upload_tmp_dir) on the server, '
                .'not a folder in this shop.',
            self::TOO_BIG_FOR_PHP => 'PHP refused the file before this shop saw it, because it is larger '
                .'than the server\'s own upload limit (upload_max_filesize / post_max_size). Save the image '
                .'smaller, or have those raised.',
            self::INCOMPLETE => 'Only part of the file arrived — the browser stopped sending before it '
                .'finished. That is usually a dropped connection or a proxy timing out. Try again.',
            self::PERMISSION => $folder
                ? 'This shop is not allowed to create a folder inside public/uploads. The web server\'s user '
                .'needs write permission on that directory.'
                : 'This shop is not allowed to write into the uploads folder. The web server\'s user needs '
                .'write permission on public/uploads and the folder under it.',
            default => $folder
                ? 'The uploads folder could not be created, and the server did not say which of the usual '
                .'reasons it was.'
                : 'The file could not be written, and the server did not say which of the usual reasons it was.',
        };
    }

    /**
     * A system message with this box's own directory layout taken out of it.
     *
     * mkdir()'s warning and Symfony's FileException both quote ABSOLUTE paths —
     * `/home/1672906.cloudwaysapps.com/yjmakdgtjs/private_html/kbb-app/...` on
     * the live shop. HealthApiController and UgcVideoController::probeNote()
     * both cut base_path() out for the same reason, and a test asserts it never
     * reaches a screen.
     *
     * The web root is cut as well as the application root, because they are
     * DIFFERENT DIRECTORIES on this deployment — bootstrap/app.php's
     * usePublicPath() — so stripping only one of them leaves the other printed.
     * Longest first, so a root that contains the other cannot be half-replaced.
     *
     * The system's remaining words are kept: "No space left on device" with the
     * path removed is still the whole answer, and it is what the owner quotes to
     * his host.
     */
    public static function redact(string $message): string
    {
        $roots = array_filter([
            rtrim((string) base_path(), '/'),
            rtrim((string) public_path(), '/'),
            rtrim((string) sys_get_temp_dir(), '/'),
        ]);

        usort($roots, static fn ($a, $b) => strlen($b) <=> strlen($a));

        $clean = str_replace($roots, '…', $message);

        return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    }
}
