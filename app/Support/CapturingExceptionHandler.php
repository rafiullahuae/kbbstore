<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

/**
 * The real exception handler, with a note of what went past it.
 *
 * WHY THIS EXISTS. HealthApiController renders each public page by handing a
 * sub-request to Illuminate\Foundation\Http\Kernel::handle(). That method wraps
 * the whole dispatch in `try { ... } catch (Throwable $e)`, reports the
 * exception and RENDERS it into a 500 response. It does not rethrow. So a
 * `catch` around the call can never fire, and a checker built on one can only
 * ever report "HTTP 500" for a page that is throwing — which is the one thing
 * the owner already knew before opening the screen.
 *
 * Binding this in front of the real handler for the duration of a probe is what
 * turns that back into an answer: the message, and the first line of the
 * application's own code in the trace.
 *
 * It DECORATES rather than replaces. report() still reaches the real handler,
 * so the failure still lands in storage/logs/laravel.log exactly as it would
 * have; render() still produces the real response. Nothing about the
 * application's behaviour changes while this is bound — it only listens.
 */
final class CapturingExceptionHandler implements ExceptionHandler
{
    private ?Throwable $captured = null;

    public function __construct(private readonly ExceptionHandler $inner) {}

    /** The first throwable seen, or null. */
    public function captured(): ?Throwable
    {
        return $this->captured;
    }

    /**
     * The FIRST, not the last.
     *
     * Rendering an error page can itself throw — a missing 500 view, a view
     * composer that needs the thing that just broke — and the second failure
     * describes the error page rather than the page the owner asked about.
     */
    public function report(Throwable $e): void
    {
        $this->captured ??= $e;

        $this->inner->report($e);
    }

    public function shouldReport(Throwable $e): bool
    {
        return $this->inner->shouldReport($e);
    }

    public function render($request, Throwable $e)
    {
        $this->captured ??= $e;

        return $this->inner->render($request, $e);
    }

    public function renderForConsole($output, Throwable $e): void
    {
        $this->inner->renderForConsole($output, $e);
    }
}
