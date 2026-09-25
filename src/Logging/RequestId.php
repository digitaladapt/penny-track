<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * Holds the current request's correlation ID.
 *
 * This exists as a separate object rather than as state on the subscriber
 * because Monolog's processors are resolved from the container and cannot see
 * the request directly. A processor depends on THIS, and the subscriber fills
 * it in — which keeps the two halves independent and lets the processor be
 * unit-tested without a kernel.
 *
 * LIFETIME NOTE (FrankenPHP worker mode): the worker reuses the process across
 * requests, so this MUST be overwritten on every request rather than appended
 * to. A stale ID here is worse than no ID at all, because it would silently
 * attribute one request's log lines to another.
 */
final class RequestId
{
    private ?string $id = null;

    public function set(string $id): void
    {
        $this->id = $id;
    }

    public function get(): ?string
    {
        return $this->id;
    }

    /** Called at the start of each request so nothing leaks across requests. */
    public function reset(): void
    {
        $this->id = null;
    }
}
