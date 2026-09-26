<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class SecurityHeadersSubscriberTest extends TestCase
{
    public function test_adds_security_headers_to_plain_http_response(): void
    {
        $subscriber = new SecurityHeadersSubscriber();
        $response = new Response();

        $subscriber->onKernelResponse($this->responseEvent(new Request(), $response));

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame('camera=(), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function test_adds_hsts_only_on_secure_requests(): void
    {
        $subscriber = new SecurityHeadersSubscriber();

        $secureRequest = new Request([], [], [], [], [], ['HTTPS' => 'on']);
        $secureResponse = new Response();
        $subscriber->onKernelResponse($this->responseEvent($secureRequest, $secureResponse));

        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $secureResponse->headers->get('Strict-Transport-Security'),
        );
    }

    public function test_subscribed_events(): void
    {
        $events = SecurityHeadersSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    private function responseEvent(Request $request, Response $response, int $requestType = HttpKernelInterface::MAIN_REQUEST): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $requestType,
            $response,
        );
    }
}
