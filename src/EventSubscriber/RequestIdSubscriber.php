<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Logging\RequestId;
use Override;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives every request a correlation ID, and echoes it back on the response.
 *
 * WHY THIS EXISTS (GUIDING-LIGHT §8.5):
 * Structured JSON logs are already configured, but a JSON line with no
 * correlation key is only half useful — when something fails you can see *that*
 * it failed and still not be able to tie that line to the request which
 * produced it, or to the error-tracker event it would eventually become. The
 * request ID is the join key, and adding it now is what makes adopting an error
 * tracker later "set SENTRY_DSN" rather than "re-architect logging".
 *
 * Three things happen, in this order:
 *
 *   1. If the caller supplied an X-Request-ID, reuse it — that makes this app's
 *      logs joinable with whatever the edge proxy logged for the same request.
 *   2. Otherwise generate one (128 bits, hex).
 *   3. Publish it to App\Logging\RequestId, which the Monolog processor reads,
 *      and echo it on the response so a user can quote it in a bug report.
 *
 * The incoming value is accepted ONLY if it looks like an identifier. An
 * attacker-controlled string written straight into your logs is log injection,
 * and the log lines are JSON — so a value containing a quote would corrupt the
 * record's structure, not merely its readability.
 */
final class RequestIdSubscriber implements EventSubscriberInterface
{
    public const string HEADER = 'X-Request-ID';

    /** Conservative: hex/uuid-ish identifiers only, so nothing injectable. */
    private const string VALID = '/^[A-Za-z0-9._-]{8,64}$/';

    public function __construct(
        private readonly RequestId $requestId,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        // FrankenPHP worker mode reuses the process across requests, so this is
        // a reset rather than a first-write. Without it a request that never
        // sets an ID would inherit the previous request's — silently attributing
        // its log lines to the wrong request, which is worse than having none.
        $this->requestId->reset();

        $incoming = $event->getRequest()->headers->get(self::HEADER);

        $id = (\is_string($incoming) && 1 === preg_match(self::VALID, $incoming))
            ? $incoming
            : bin2hex(random_bytes(16));

        $this->requestId->set($id);

        // Also kept on the request so the response listener — and anything else
        // that only has the Request — can reach it.
        $event->getRequest()->attributes->set('_request_id', $id);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $id = $event->getRequest()->attributes->get('_request_id');

        if (\is_string($id)) {
            $event->getResponse()->headers->set(self::HEADER, $id);
        }
    }

    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            // Early, so that later listeners' log records already carry the ID.
            KernelEvents::REQUEST => ['onKernelRequest', 4096],
            // Late, so the header survives anything else that rewrites headers.
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
        ];
    }
}
