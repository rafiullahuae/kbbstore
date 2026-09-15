<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Build the test schema, in a process of its own
|------------------------------------------------------------------------------
|
| Run by tests/Pest.php before the suite starts. Two reasons it is a separate
| process rather than a few lines inside Pest.php:
|
|   * Booting an application inside the test process installs Laravel's error
|     and exception handlers and leaves migration output in PHPUnit's buffer.
|     PHPUnit then marks every test in the run risky — for reasons that have
|     nothing to do with the tests, which buries any real warning.
|   * It cannot be `artisan migrate:fresh` either, because artisan uses
|     bootstrap/app.php as-is, and that pins the public path at the shared
|     host's web root. 2026_08_28_183000_relocate_public_assets then sees
|     public/build as a stray copy of a directory that lives somewhere else and
|     DELETES it — which turns up later as public/build staged for deletion in
|     the next commit. That is a real thing that happens; this file exists to
|     stop it.
|
| The environment is inherited from the parent, which has already applied
| phpunit.xml's <php><env> block.
*/

require __DIR__ . '/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__ . '/../bootstrap/app.php';

// The override CLAUDE.md's "leave that line alone" is about: bootstrap/app.php
// keeps its production public path; this process points it at the repo's own
// public/ so the relocate migration finds the two paths identical and does
// nothing, exactly as it does on the server.
$app->usePublicPath(dirname(__DIR__) . '/public');

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

exit($kernel->handle(
    new Symfony\Component\Console\Input\ArrayInput(['command' => 'migrate:fresh', '--force' => true]),
    new Symfony\Component\Console\Output\ConsoleOutput,
));
