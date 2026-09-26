<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\LLM\LlmClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LlmClientTest extends TestCase
{
    public function test_chat_sends_request_and_returns_parsed_json(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse((string) json_encode([
                'choices' => [
                    ['message' => ['content' => '{"amount": 12.5, "business": "Cafe"}']],
                ],
            ]));
        });

        $client = new LlmClient($httpClient, 'https://llm.test/v1/chat/completions', 'secret-key', 'test-model', 7);

        $result = $client->chat([['role' => 'user', 'content' => 'coffee 12.5']]);

        $this->assertSame(['amount' => 12.5, 'business' => 'Cafe'], $result);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://llm.test/v1/chat/completions', $captured['url']);
        $this->assertContains('Authorization: Bearer secret-key', $captured['options']['headers']);
        $this->assertSame(7.0, $captured['options']['timeout']);
        $this->assertSame(
            ['model' => 'test-model', 'messages' => [['role' => 'user', 'content' => 'coffee 12.5']], 'temperature' => 0.1],
            json_decode((string) $captured['options']['body'], true),
        );
    }

    public function test_chat_omits_authorization_header_when_no_api_key(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse((string) json_encode([
                'choices' => [
                    ['message' => ['content' => '{"ok": true}']],
                ],
            ]));
        });

        $client = new LlmClient($httpClient, 'https://llm.test/v1/chat/completions', '', 'test-model', 5);
        $client->chat([]);

        $this->assertNotContains('Authorization', array_map(
            static fn (string $header): string => explode(':', $header, 2)[0],
            $captured['headers'],
        ));
    }

    public function test_chat_omits_authorization_header_for_change_me_placeholder(): void
    {
        $captured = [];
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse((string) json_encode([
                'choices' => [
                    ['message' => ['content' => '{"ok": true}']],
                ],
            ]));
        });

        $client = new LlmClient($httpClient, 'https://llm.test/v1/chat/completions', 'change-me', 'test-model', 5);
        $client->chat([]);

        $this->assertNotContains('Authorization', array_map(
            static fn (string $header): string => explode(':', $header, 2)[0],
            $captured['headers'],
        ));
    }

    public function test_chat_extracts_json_from_markdown_code_block(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([
            'choices' => [
                ['message' => ['content' => "Here is the data:\n```json\n{\"amount\": 3.5}\n```\nEnjoy!"]],
            ],
        ])));

        $client = new LlmClient($httpClient, 'https://llm.test/v1/chat/completions', 'k', 'm', 5);

        $this->assertSame(['amount' => 3.5], $client->chat([]));
    }

    public function test_chat_throws_when_response_structure_is_invalid(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode(['error' => 'nope'])));
        $client = new LlmClient($httpClient, 'https://llm.test/v1/chat/completions', 'k', 'm', 5);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid LLM response structure');

        $client->chat([]);
    }

    public function test_chat_throws_when_content_is_not_json(): void
    {
        $httpClient = new MockHttpClient(new MockResponse((string) json_encode([
            'choices' => [
                ['message' => ['content' => 'I could not parse that, sorry.']],
            ],
        ])));
        $client = new LlmClient($httpClient, 'https://llm.test/v1/chat/completions', 'k', 'm', 5);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not parse LLM response as JSON');

        $client->chat([]);
    }
}
