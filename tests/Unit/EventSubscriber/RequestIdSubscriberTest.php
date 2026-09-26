<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\RequestIdSubscriber;
use App\Logging\RequestId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class RequestIdSubscriberTest extends TestCase
{
    public function test_generates_id_when_header_absent(): void
    {
        $requestId = new RequestId();
        $subscriber = new RequestIdSubscriber($requestId);

        $subscriber->onKernelRequest($this->requestEvent(new Request()));

        $id = $requestId->get();
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);
    }

    public function test_reuses_valid_incoming_id(): void
    {
        $requestId = new RequestId();
        $subscriber = new RequestIdSubscriber($requestId);

        $request = new Request();
        $request->headers->set('X-Request-ID', 'abc-123_XYZ');
        $subscriber->onKernelRequest($this->requestEvent($request));

        $this->assertSame('abc-123_XYZ', $requestId->get());
    }

    public function test_rejects_injectable_incoming_id(): void
    {
        $requestId = new RequestId();
        $subscriber = new RequestIdSubscriber($requestId);

        $request = new Request();
        $request->headers->set('X-Request-ID', 'evil"quote');
        $subscriber->onKernelRequest($this->requestEvent($request));

        $this->assertNotSame('evil"quote', $requestId->get());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $requestId->get());
    }

    public function test_sub_request_does_not_touch_state(): void
    {
        $requestId = new RequestId();
        $requestId->set('existing-id');
        $subscriber = new RequestIdSubscriber($requestId);

        $subscriber->onKernelRequest($this->requestEvent(new Request(), HttpKernelInterface::SUB_REQUEST));

        $this->assertSame('existing-id', $requestId->get());
    }

    public function test_response_echoes_request_id_header(): void
    {
        $subscriber = new RequestIdSubscriber(new RequestId());

        $request = new Request();
        $request->attributes->set('_request_id', 'resp-id-123');
        $response = new Response();

        $subscriber->onKernelResponse($this->responseEvent($request, $response));

        $this->assertSame('resp-id-123', $response->headers->get('X-Request-ID'));
    }

    public function test_response_without_request_id_leaves_header_unset(): void
    {
        $subscriber = new RequestIdSubscriber(new RequestId());

        $response = new Response();
        $subscriber->onKernelResponse($this->responseEvent(new Request(), $response));

        $this->assertFalse($response->headers->has('X-Request-ID'));
    }

    public function test_subscribed_events(): void
    {
        $events = RequestIdSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
    }

    private function requestEvent(Request $request, int $requestType = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $requestType,
        );
    }

    private function responseEvent(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );
    }
}
