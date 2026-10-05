<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\Media\UploadFault;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\Exception\FormSizeFileException;
use Symfony\Component\HttpFoundation\File\Exception\IniSizeFileException;
use Symfony\Component\HttpFoundation\File\Exception\NoTmpDirFileException;
use Symfony\Component\HttpFoundation\File\Exception\PartialFileException;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

/**
 * =============================================================================
 * THE MEDIA UPLOAD SAYS WHICH FAULT IT HIT                             Lane AD
 * =============================================================================
 *
 * ── WHAT THE OWNER SAW, AND WHY IT COST A ROUND TRIP ────────────────────────
 *
 * Admin → Media Library → Upload, and anywhere else an image is chosen (the
 * product editor's gallery, a brand logo, a category image, the SEO share
 * image) posts to /admin-api/media/upload. It had two failure lines and both
 * were fixed strings:
 *
 *     'Could not create upload directory.'
 *     'Upload failed — check folder permissions.'
 *
 * The second NAMES A CAUSE. A shared host out of disk, a read-only mount after
 * a failed deploy, and a php.ini with no usable upload_tmp_dir all arrived as
 * those six words, sending the owner to check permissions that were correct.
 * That is UgcTranscoder::run()'s defect in another file: the system said which
 * one it was, in one line, and the screen threw the line away.
 *
 * ── AND THE WORSE HALF: THE GUARD COULD NOT FIRE ────────────────────────────
 *
 * The second line was reached through
 *
 *     if (!$file->move($dir, $filename) || !is_file($destination))
 *
 * and UploadedFile::move() does not return false. It returns a File or it
 * throws FileException. So the first term was dead, and every REAL move failure
 * left upload() as an uncaught exception — a bare "Server Error" on the Media
 * Library with debug off, which is how the live shop runs. The owner's actual
 * experience of a full disk was therefore a blank failure, and the misleading
 * sentence was one he could only reach if is_file() disagreed with a move that
 * had just succeeded.
 *
 * ── MUTATION NOTES, ALL RUN ─────────────────────────────────────────────────
 *
 *  M1  Put `return response()->json(['ok' => false, 'message' => 'Could not
 *      create upload directory.'], 500);` back in place of the folder branch.
 *      RED on "names which fault stopped the folder being made" — the message
 *      no longer says a file is in the way and `fault` is absent.
 *
 *  M2  Replace the try/catch around $file->move() with the original
 *      `if (!$file->move($dir, $filename) || !is_file($destination))`. RED on
 *      "answers a throwing move with a sentence" — the FileException escapes
 *      and the response is a 500 with no JSON body at all.
 *
 *  M3  In UploadFault::classify(), move the PERMISSION row above DISK_FULL.
 *      RED on "names the disk before permissions" — an ENOSPC message that also
 *      mentions permission is classified as a permissions fault.
 *
 *  M4  In UploadFault::redact(), return $message unchanged. RED on "never
 *      prints this server's own directory layout".
 *
 * Every one of the four was applied, the suite run, the failure observed, and
 * the mutation reverted.
 */
function mufAdmin(): void
{
    test()->actingAs(AdminUser::create([
        'name' => 'MUF Owner',
        'email' => 'muf-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]), 'admin');
}

/** A real file with real bytes, for the reason MediaUploadTest gives. */
function mufPng(string $name = 'shot.png'): \Illuminate\Http\UploadedFile
{
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    $path = tempnam(kbbTempDir(), 'mufup');
    file_put_contents($path, $bytes);

    return new \Illuminate\Http\UploadedFile($path, $name, null, null, true);
}

/* ═════════════════ 1 · the folder stage names its own fault ═════════════════ */

it('names which fault stopped the uploads folder being made', function () {
    /*
     * THE FAULT IS SIMULATED RATHER THAN WAITED FOR, which is the only way to
     * see this branch: a file sitting where the folder has to go makes mkdir()
     * fail with "File exists" and leaves is_dir() false, on any box and without
     * root, a full disk or a remount.
     *
     * It is also a fault that really happens here — public/uploads is written
     * by hand during a migration from WordPress, and a stray `products` file
     * beside the `products` folder is exactly the shape of that mistake.
     */
    mufAdmin();

    // Lowercase and digits only: the endpoint's own folder rule is
    // /^[a-z0-9\-]{1,40}$/, and Str::random() is mixed case.
    $folder = 'muf-blocked-'.substr(md5((string) mt_rand()), 0, 6);
    $inTheWay = public_path('uploads/'.$folder);

    @mkdir(dirname($inTheWay), 0755, true);
    file_put_contents($inTheWay, 'not a folder');

    try {
        $response = test()->post('/admin-api/media/upload', [
            'file' => mufPng(),
            'folder' => $folder,
        ]);

        $response->assertStatus(500);

        $body = $response->json();

        expect($body['ok'] ?? null)->toBeFalse()
            ->and($body['fault'] ?? null)->toBe(UploadFault::NOT_A_DIRECTORY);

        $message = (string) ($body['message'] ?? '');

        /*
         * THE OLD SENTENCE IS THE THING BEING ASSERTED AGAINST. It said the
         * folder could not be created and stopped there, which is true and
         * useless: it is the same sentence for a full disk, a read-only mount
         * and this. The new one says what is in the way and where to look.
         */
        expect(str_contains($message, 'not a folder'))->toBeTrue(
            "the refusal does not say a non-folder is in the way: {$message}"
        );
        expect(str_contains($message, 'public/uploads'))->toBeTrue(
            "the refusal does not say where to look: {$message}"
        );

        /*
         * AND IT MUST NOT BLAME PERMISSIONS. This is the specific wrong turn the
         * old wording sent him on, and the one arm where it is provably wrong.
         */
        expect(str_contains(strtolower($message), 'permission'))->toBeFalse(
            "the refusal still blames permissions for a file in the way: {$message}"
        );
    } finally {
        @unlink($inTheWay);
    }
});

/* ═════════════════ 2 · a throwing move is caught, not a 500 ═════════════════ */

it('answers a throwing move with a sentence instead of a bare Server Error', function () {
    /*
     * THE DEAD GUARD, REPRODUCED. A DIRECTORY at the exact destination path
     * makes rename() fail — Symfony's File::move() then throws FileException,
     * which is the path `!$file->move(...)` could never take.
     *
     * The destination is predictable only because Str::random is pinned here;
     * the date second is not, so BOTH the current second and the next one are
     * blocked. That is belt and braces against a test that starts at 12:00:00.999
     * and reaches the controller at 12:00:01.001, which would otherwise be a
     * once-in-a-thousand-runs red that reads exactly like flake.
     */
    mufAdmin();

    Str::createRandomStringsUsing(fn (int $length = 16) => str_repeat('a', $length));

    $folder = 'muf-throw-'.substr(md5((string) mt_rand()), 0, 6);
    $dir = public_path('uploads/'.$folder);
    @mkdir($dir, 0755, true);

    $blocked = [];

    foreach ([0, 1] as $offset) {
        $blocked[] = $dir.'/'.date('Ymd-His', time() + $offset).'-'.str_repeat('a', 8).'.png';
    }

    foreach ($blocked as $path) {
        @mkdir($path, 0755, true);
    }

    try {
        // A name of symbols only slugs to nothing, so the upload takes the
        // Ymd-His-<random> fallback this test blocks (2.60.390: an upload
        // otherwise keeps the operator's own file name).
        $response = test()->post('/admin-api/media/upload', [
            'file' => mufPng('###.png'),
            'folder' => $folder,
        ]);

        /*
         * A JSON BODY AT ALL is most of what this asserts. Before the fix the
         * FileException escaped upload() and the framework rendered it — there
         * was no `ok`, no `message` and nothing for the screen to show.
         */
        $response->assertStatus(500);

        $body = $response->json();

        expect($body)->toBeArray()
            ->and($body['ok'] ?? null)->toBeFalse();

        $message = (string) ($body['message'] ?? '');

        expect($message)->not->toBe('')
            ->and(str_contains($message, 'The server said:'))->toBeTrue(
                "the refusal carries no system detail at all: {$message}"
            );
    } finally {
        Str::createRandomStringsNormally();

        foreach ($blocked as $path) {
            @rmdir($path);
        }

        @rmdir($dir);
    }
});

/* ═══════════════════ 3 · no server path reaches the screen ═══════════════════ */

it('never prints this server\'s own directory layout in an upload refusal', function () {
    /*
     * HealthApiController and UgcVideoController::probeNote() both cut
     * base_path() out of anything bound for a screen, and a test asserts it
     * never appears. mkdir()'s warning and Symfony's FileException both quote
     * ABSOLUTE paths, so this is the same rule applied at a new door.
     *
     * The WEB ROOT as well as the application root, because on the live shop
     * they are different directories — bootstrap/app.php's usePublicPath() —
     * so stripping one still leaks the other.
     */
    $spoken = 'mkdir(): Permission denied in '.base_path().'/app/X.php and '
        .public_path('uploads/products').' was unwritable';

    $clean = UploadFault::redact($spoken);

    expect(str_contains($clean, base_path()))->toBeFalse("base_path() survived redaction: {$clean}");
    expect(str_contains($clean, public_path()))->toBeFalse("public_path() survived redaction: {$clean}");

    // The SYSTEM'S OWN WORDS survive, which is the whole point of keeping it.
    expect(str_contains($clean, 'Permission denied'))->toBeTrue(
        "redaction ate the reason as well as the path: {$clean}"
    );
});

/* ═════════════════════ 4 · the classifier, arm by arm ═════════════════════ */

it('tells the four system faults apart by the system\'s own words', function () {
    expect(UploadFault::classify('mkdir(): No space left on device'))->toBe(UploadFault::DISK_FULL)
        ->and(UploadFault::classify('fopen(): Disk quota exceeded'))->toBe(UploadFault::DISK_FULL)
        ->and(UploadFault::classify('mkdir(): Read-only file system'))->toBe(UploadFault::READ_ONLY)
        ->and(UploadFault::classify('mkdir(): File exists'))->toBe(UploadFault::NOT_A_DIRECTORY)
        ->and(UploadFault::classify('mkdir(): Permission denied'))->toBe(UploadFault::PERMISSION)
        ->and(UploadFault::classify('something nobody has seen before'))->toBe(UploadFault::UNKNOWN);
});

it('names the disk before permissions, because a full disk looks like both', function () {
    /*
     * ORDER IS THE ASSERTION. A host that is out of quota reports ENOSPC and can
     * ALSO refuse the next write as a permission error, so a message carrying
     * both must be read as the disk: telling somebody to chmod a folder that is
     * already correct is the round trip this whole file exists to stop.
     *
     * The same reasoning UgcTranscoder::reason() gives for reporting a missing
     * ffmpeg before an unstartable one.
     *
     * MUTATION (M3, run): move the PERMISSION row above DISK_FULL in
     * classify()'s table. RED here.
     */
    expect(UploadFault::classify('Permission denied: No space left on device'))
        ->toBe(UploadFault::DISK_FULL);
});

it('reads Symfony\'s upload exceptions by type rather than by prose', function () {
    /*
     * BY CLASS, because the type is a fact and the message is prose. Symfony
     * raises a distinct subclass per UPLOAD_ERR_* constant and their wording has
     * changed between releases; the class has not.
     */
    expect(UploadFault::fromThrowable(new NoTmpDirFileException('x')))->toBe(UploadFault::NO_TMP)
        ->and(UploadFault::fromThrowable(new IniSizeFileException('x')))->toBe(UploadFault::TOO_BIG_FOR_PHP)
        ->and(UploadFault::fromThrowable(new FormSizeFileException('x')))->toBe(UploadFault::TOO_BIG_FOR_PHP)
        ->and(UploadFault::fromThrowable(new PartialFileException('x')))->toBe(UploadFault::INCOMPLETE)
        // A PLAIN FileException carries no type meaning, so the words decide.
        ->and(UploadFault::fromThrowable(new FileException('Could not move the file (No space left on device).')))
        ->toBe(UploadFault::DISK_FULL);
});

it('gives every fault a sentence, and a different one for the folder stage', function () {
    /*
     * NO ARM MAY FALL THROUGH TO SILENCE. The keys are read off the class so a
     * constant added later without a sentence is caught here rather than by the
     * owner reading an empty toast.
     */
    $keys = (new ReflectionClass(UploadFault::class))->getConstants();

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $name => $key) {
        foreach (['file', 'folder'] as $stage) {
            $sentence = UploadFault::sentence($key, $stage);

            expect(strlen($sentence))->toBeGreaterThan(40, "{$name} has no usable sentence at the {$stage} stage");
            expect(str_contains($sentence, base_path()))->toBeFalse("{$name} prints a server path");
        }
    }

    // The two stages are genuinely different questions where the remedy differs.
    expect(UploadFault::sentence(UploadFault::PERMISSION, 'folder'))
        ->not->toBe(UploadFault::sentence(UploadFault::PERMISSION, 'file'));
});

/* ════════════════ 5 · the guard that was here could never fire ════════════════ */

it('proves UploadedFile::move() cannot return the false the old guard tested for', function () {
    /*
     * THE SOURCE, not a page. This is the fact the defect rested on, it lives in
     * a vendor file no lane controls, and a composer update is exactly the event
     * that should make somebody re-read this code. A behavioural test cannot say
     * "and it never returns false"; the signature can.
     */
    $file = (new ReflectionClass(SymfonyUploadedFile::class))->getFileName();
    $src = (string) file_get_contents((string) $file);

    expect(str_contains($src, 'public function move(string $directory, ?string $name = null): File'))
        ->toBeTrue('UploadedFile::move() no longer declares a File return type — re-read MediaUploadController');

    expect(str_contains($src, 'throw new FileException'))
        ->toBeTrue('UploadedFile::move() no longer throws FileException — re-read MediaUploadController');

    /*
     * AND THE CONTROLLER MUST NOT HAVE GONE BACK TO TESTING IT. Read with
     * comments stripped, because this file's own prose quotes the old guard and
     * a scanner that reads comments finds code that is not there — the trap
     * CLAUDE.md names for key scans.
     */
    $controller = (string) file_get_contents(base_path('app/Http/Controllers/Admin/MediaUploadController.php'));
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $controller);

    expect(str_contains($code, '!$file->move('))->toBeFalse(
        'MediaUploadController tests the return value of move() again — it is never false'
    );
    expect(substr_count($code, '$file->move('))->toBe(1);
});
