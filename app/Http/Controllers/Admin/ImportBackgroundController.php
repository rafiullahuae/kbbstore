<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ImportConsole\ImportChain;
use App\Services\ImportConsole\ImportDriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The owner's side of the background import — Lane GO.
 *
 * Four endpoints and a page, all inside the same `auth:admin` + NoStoreAdminApi
 * group the rest of the import screen lives in, all under `admin-api/import/`
 * so that AdminCapabilities' existing `['*', 'admin-api/import/**',
 * 'data.import']` covers them. NO NEW RULE WAS ADDED: a rule shadowed by an
 * earlier wildcard is dead text, and AdminCapabilities::forPath() was asked
 * rather than the table being read. This lane's test asks it again, so a future
 * tidy-up that narrows the wildcard fails in the suite instead of as a 403 on a
 * host with no shell.
 *
 * -----------------------------------------------------------------------------
 * WHY THERE IS A PAGE OF ITS OWN
 * -----------------------------------------------------------------------------
 * "Even i close the tab" is only half a feature if the owner cannot then OPEN a
 * tab and see what happened. The console that drives the import lives in
 * resources/views/admin/app.blade.php, a 20,000-line file this lane may not
 * edit — and a screen whose job is to be trustworthy when something is broken
 * should not depend on the file that might be what is broken. Lane GD made the
 * same call for the same reason and its page is the model this one follows,
 * down to the IDLE / RUNNING / STALLED vocabulary, so that the two read as one
 * system rather than two features that both happen to have a bar.
 *
 * It is a full HTML document with no build step: `package.json` defines no
 * `build` script and CI does not build assets (CLAUDE.md), so a page that needed
 * compiling would be a page that did not exist on the server.
 *
 * -----------------------------------------------------------------------------
 * WHY THE POLL IS NOT /import/status
 * -----------------------------------------------------------------------------
 * ImportDriver::status() opens and counts the rejection CSV and rebuilds the
 * whole file list, and this page polls for as long as an import takes. The same
 * reasoning ImportDriver::denominators() already carries. `progress()` returns
 * the bars and the chain state and nothing else.
 */
class ImportBackgroundController extends Controller
{
    public function __construct(
        private readonly ImportChain $chain = new ImportChain,
        private readonly ImportDriver $driver = new ImportDriver,
    ) {}

    /**
     * The bars and the chain state, for the page's poll.
     *
     * THE REVIVAL HANGS OFF THIS CALL, and that placement is the feature rather
     * than a convenience. A chain dies when the process holding its baton is
     * killed, and on a host with no timer nothing notices — so the act of
     * LOOKING revives it. Opening this page picks a stalled run back up; a tab
     * left polling picks it up within one poll. It costs one already-read row
     * when there is nothing stalled, which is almost always.
     */
    public function progress(): JsonResponse
    {
        $this->chain->reviveIfStalled();

        return response()->json($this->chain->progress());
    }

    /**
     * Start, or pick back up, a run that continues without a browser.
     *
     * Deliberately does NOT start the import. `start` is the console's job and
     * this is strictly an addition to it: a run exists, with its options
     * already settled and stored, and this asks the server to take over driving
     * it. Folding the two together would mean a second place that decides what
     * a run's options are, which is the thing ImportDriver::start() exists to
     * be the only one of.
     */
    public function begin(): JsonResponse
    {
        $result = $this->chain->begin();

        return response()->json(
            $result + ['progress' => $this->chain->progress()],
            $result['ok'] ? 200 : 409
        );
    }

    /**
     * Pause, Resume and Stop.
     *
     * Stop goes through ImportDriver::stop() — the existing one, untouched — so
     * there is exactly one thing in this application that ends a run, and the
     * console's Stop button and this one cannot drift apart. The chain is then
     * halted on top of it, which is belt to the driver's braces and is not dead
     * text: the claim refuses on `status = 'running'`, and this additionally
     * means a stopped run holds no secret. A secret that outlives its purpose
     * is a secret waiting to be found.
     */
    public function control(Request $request): JsonResponse
    {
        $request->validate(['action' => ['required', 'in:pause,resume,stop']]);

        $action = $request->string('action')->toString();
        $result = ['ok' => true, 'message' => '', 'via' => null];

        if ($action === 'pause') {
            $this->chain->pause();
            $result['message'] = 'Paused. Nothing is being imported; Resume carries on from the row after the '
                .'last one that was committed.';
        } elseif ($action === 'resume') {
            $result = $this->chain->resume();
        } else {
            $this->driver->stop();
            $this->chain->halt('stopped from the progress page');
            $result['message'] = 'Stopped. Nothing it had already imported was undone.';
        }

        return response()->json(
            $result + ['progress' => $this->chain->progress()],
            $result['ok'] ? 200 : 409
        );
    }

    /** The page the owner opens to watch a run he is not driving. */
    public function page(): Response
    {
        $this->chain->reviveIfStalled();

        return response()->view('admin.import-background');
    }
}
