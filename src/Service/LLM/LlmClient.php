<?php

declare(strict_types=1);

namespace App\Service\LLM;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class LlmClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiEndpoint,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $timeout,
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<string, mixed>
     */
    public function chat(array $messages): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.1,
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($this->apiKey !== '' && $this->apiKey !== 'change-me') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $response = $this->httpClient->request('POST', $this->apiEndpoint, [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => $this->timeout,
        ]);

        $data = $response->toArray();

        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \RuntimeException('Invalid LLM response structure');
        }

        $content = $data['choices'][0]['message']['content'];
        $parsed = json_decode($content, true);

        if (!is_array($parsed)) {
            // Try to extract JSON from markdown code blocks
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
                $parsed = json_decode(trim($matches[1]), true);
            }
        }

        if (!is_array($parsed)) {
            throw new \RuntimeException('Could not parse LLM response as JSON: ' . $content);
        }

        return $parsed;
    }
}
