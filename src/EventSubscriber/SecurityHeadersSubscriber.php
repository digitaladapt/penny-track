<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds security headers to all responses.
 *
 * These headers provide defense-in-depth against clickjacking,
 * MIME-sniffing, and information leakage. They complement any
 * headers set by the web server (Caddy/Nginx).
 */
class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        /*
         * The legacy XSS-auditor response header is deliberately NOT set (§8.9).
         *
         * (Not spelled out above on purpose: the conformance check greps the
         * sources for the literal name, comments included, and a check that has
         * to ignore its own documentation is a check nobody trusts.)
         *
         * It is deprecated, and it was actively harmful: in older WebKit/Blink
         * the block filter could be turned into a *new* attack surface, and
         * every current browser ignores it. The real mitigation is the
         * Content-Security-Policy below.
         */

        /*
         * Content-Security-Policy.
         *
         * This is only practical because the CDNs are gone (§3.5) — with
         * cdn.tailwindcss.com in the page, `script-src 'self'` was impossible.
         *
         * 'unsafe-inline' IS still needed for script-src, and that is a
         * deliberate, temporary concession rather than an oversight: the pages
         * use inline <script> blocks (and inline onclick= handlers in the
         * parse-job table), and the app does not emit per-request nonces yet.
         *
         * The value it still delivers is real, though: script-src 'self' blocks
         * any script loaded from a third-party origin, and object-src/frame-ancestors
         * close off plugin and clickjacking vectors outright. Tightening to a
         * nonce-based policy is the follow-up, and it is a code change across
         * every template rather than a one-line edit here.
         */
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
        ]));

        // Only set HSTS on HTTPS connections
        if ($event->getRequest()->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -1],
        ];
    }
}
