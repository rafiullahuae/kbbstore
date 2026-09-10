<?php
/**
 * kbb-unstick.php — one-time recovery tool.
 *
 * Drop this into the same folder as kbb-doctor.php on your server (the
 * public web root — the folder your site's address actually points at),
 * then visit it in your browser once: yoursite.com/kbb-unstick.php
 *
 * It does exactly what logging out does — clears every active session, so
 * whatever pending-update state was stuck is gone — but as its own script,
 * not a button on the admin page. It does not touch the updater code, your
 * settings, your products, or anything else. It does not need the admin
 * page to be working at all, and does not require Laravel to boot cleanly.
 *
 * Delete this file once it's worked. It doesn't need a password to run,
 * which is fine for a five-minute repair job but not for something that
 * stays on the server.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

function findEnv(string $startDir): ?string
{
    $dir = $startDir;
    for ($i = 0; $i < 4; $i++) {
        $candidate = $dir . '/.env';
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}

function parseEnv(string $path): array
{
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        $out[trim($key)] = $value;
    }
    return $out;
}

$envPath = findEnv(__DIR__);
$result = ['ok' => false, 'detail' => '', 'sessionsCleared' => 0];

if ($envPath === null) {
    $result['detail'] = 'Could not find a .env file near this script. Move this file into the '
        . 'same folder as kbb-doctor.php and try again, or tell Claude exactly where this file '
        . 'and your app\'s .env file live on the server.';
} else {
    $env = parseEnv($envPath);
    $driver = $env['SESSION_DRIVER'] ?? 'database';
    $appRoot = dirname($envPath);

    try {
        if ($driver === 'file') {
            $sessionDir = $appRoot . '/storage/framework/sessions';
            $cleared = 0;
            if (is_dir($sessionDir)) {
                foreach (glob($sessionDir . '/*') ?: [] as $file) {
                    if (is_file($file) && basename($file) !== '.gitignore') {
                        @unlink($file);
                        $cleared++;
                    }
                }
            }
            $result = ['ok' => true, 'detail' => "File-based sessions ({$sessionDir}) cleared.", 'sessionsCleared' => $cleared];
        } else {
            // database (Laravel's own default) and every other driver this
            // app has used all keep the sessions table even when it isn't
            // the active driver, so clearing it is always safe to attempt.
            $connection = $env['DB_CONNECTION'] ?? 'mysql';
            $dsn = match ($connection) {
                'sqlite' => 'sqlite:' . ($env['DB_DATABASE'] ?? ''),
                'pgsql' => sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    $env['DB_HOST'] ?? '127.0.0.1',
                    $env['DB_PORT'] ?? '5432',
                    $env['DB_DATABASE'] ?? ''
                ),
                default => sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $env['DB_HOST'] ?? '127.0.0.1',
                    $env['DB_PORT'] ?? '3306',
                    $env['DB_DATABASE'] ?? ''
                ),
            };

            $pdo = new PDO($dsn, $env['DB_USERNAME'] ?? null, $env['DB_PASSWORD'] ?? null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $count = (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
            $pdo->exec('DELETE FROM sessions');

            $result = ['ok' => true, 'detail' => 'Database sessions table cleared.', 'sessionsCleared' => $count];
        }
    } catch (\Throwable $e) {
        $result['detail'] = 'Could not clear sessions: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>KBB — recovery</title>
<style>
body{margin:0;background:#0f1720;color:#dbe4ee;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:640px;margin:0 auto;padding:40px 20px}
h1{font-size:20px}
.card{background:#16212d;border:1px solid #24323f;border-radius:12px;padding:20px 22px;margin:18px 0}
.ok{border-color:#2c6b3f;background:#14321f}
.err{border-color:#7a2f2f;background:#361a1a}
.muted{color:#8b9bad;font-size:13px}
code{background:#0f1720;padding:2px 6px;border-radius:4px}
</style>
</head><body>
<div class="wrap">
<h1>KBB recovery tool</h1>
<div class="card <?= $result['ok'] ? 'ok' : 'err' ?>">
<?php if ($result['ok']): ?>
    <b>Done.</b> <?= htmlspecialchars($result['detail']) ?>
    <?php if ($result['sessionsCleared'] > 0): ?>
        <p><?= (int) $result['sessionsCleared'] ?> session(s) cleared.</p>
    <?php endif; ?>
    <p>Go back to your admin panel and log in again. The stuck update should be gone,
       and you should see the upload option.</p>
<?php else: ?>
    <b>Could not finish.</b> <?= htmlspecialchars($result['detail']) ?>
<?php endif; ?>
</div>
<p class="muted">Delete this file from your server now that it's done its job — it doesn't
require a password to run, which isn't something to leave sitting on a live site.</p>
</div>
</body></html>
