<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Receipt;
use App\Repository\ReceiptRepository;
use App\Service\LLM\LlmClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ReceiptController extends AbstractController
{
    public function __construct(
        private readonly ReceiptRepository $receiptRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly LlmClient $llmClient,
    ) {
    }

    #[Route('/receipts/new', name: 'app_receipt_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('receipt/new.html.twig');
    }

    #[Route('/api/receipts', name: 'api_receipts_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 10)));

        $receipts = $this->receiptRepository->findBy([], ['createdAt' => 'DESC'], $limit, ($page - 1) * $limit);
        $total = $this->receiptRepository->count([]);

        return new JsonResponse([
            'data' => array_map(fn (Receipt $r) => $this->serializeReceipt($r), $receipts),
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
        ]);
    }

    #[Route('/api/receipts/{id}', name: 'api_receipts_get', methods: ['GET'])]
    public function get(int $id): JsonResponse
    {
        $receipt = $this->receiptRepository->find($id);
        if (!$receipt) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        return new JsonResponse($this->serializeReceipt($receipt));
    }

    #[Route('/api/receipts', name: 'api_receipts_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $receipt = $this->hydrateReceipt(new Receipt(), $data);

        $errors = $this->validator->validate($receipt);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }
            return new JsonResponse(['errors' => $messages], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->persist($receipt);
        $this->entityManager->flush();

        return new JsonResponse($this->serializeReceipt($receipt), Response::HTTP_CREATED);
    }

    #[Route('/api/receipts/{id}', name: 'api_receipts_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $receipt = $this->receiptRepository->find($id);
        if (!$receipt) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);
        $receipt = $this->hydrateReceipt($receipt, $data);

        $errors = $this->validator->validate($receipt);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }
            return new JsonResponse(['errors' => $messages], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serializeReceipt($receipt));
    }

    #[Route('/api/receipts/{id}', name: 'api_receipts_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $receipt = $this->receiptRepository->find($id);
        if (!$receipt) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($receipt);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/receipts/parse', name: 'api_receipts_parse', methods: ['POST'])]
    public function parse(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $text = trim($data['text'] ?? '');

        if ($text === '') {
            return new JsonResponse(['error' => 'Text is required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $systemPrompt = $this->buildSystemPrompt();

        try {
            $parsed = $this->llmClient->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $text],
            ]);
        } catch (\Throwable $e) {
            // Fallback: store raw text for manual review
            $receipt = new Receipt();
            $receipt->setAmount('0.00');
            $receipt->setBusiness('Unknown');
            $receipt->setCategory('Other');
            $receipt->setRawInput($text);
            $receipt->setNotes('LLM parsing failed: ' . $e->getMessage() . "\n\nOriginal: " . $text);
            $this->entityManager->persist($receipt);
            $this->entityManager->flush();

            return new JsonResponse([
                'receipt' => $this->serializeReceipt($receipt),
                'parsed' => null,
                'warning' => 'Could not parse automatically. Saved for manual review.',
            ], Response::HTTP_ACCEPTED);
        }

        $receipt = new Receipt();
        $receipt->setAmount(isset($parsed['amount']) && is_numeric($parsed['amount']) ? number_format((float) $parsed['amount'], 2, '.', '') : '0.00');
        $receipt->setBusiness($parsed['business'] ?? 'Unknown');
        $receipt->setCategory($parsed['category'] ?? 'Other');
        $receipt->setLocation($parsed['location'] ?? null);
        $receipt->setTags($parsed['tags'] ?? []);
        $receipt->setNotes($parsed['notes'] ?? $text);
        $receipt->setRawInput($text);

        if (!empty($parsed['date'])) {
            try {
                $receipt->setCreatedAt(new \DateTimeImmutable($parsed['date']));
            } catch (\Throwable) {
                // keep default
            }
        }

        $errors = $this->validator->validate($receipt);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }
            return new JsonResponse(['errors' => $messages, 'parsed' => $parsed], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->persist($receipt);
        $this->entityManager->flush();

        return new JsonResponse([
            'receipt' => $this->serializeReceipt($receipt),
            'parsed' => $parsed,
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/autocomplete/businesses', name: 'api_autocomplete_businesses', methods: ['GET'])]
    public function autocompleteBusinesses(): JsonResponse
    {
        return new JsonResponse($this->receiptRepository->findUniqueBusinesses());
    }

    #[Route('/api/autocomplete/categories', name: 'api_autocomplete_categories', methods: ['GET'])]
    public function autocompleteCategories(): JsonResponse
    {
        return new JsonResponse($this->receiptRepository->findUniqueCategories());
    }

    #[Route('/api/autocomplete/locations', name: 'api_autocomplete_locations', methods: ['GET'])]
    public function autocompleteLocations(): JsonResponse
    {
        return new JsonResponse($this->receiptRepository->findUniqueLocations());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReceipt(Receipt $receipt): array
    {
        return [
            'id' => $receipt->getId(),
            'amount' => (float) $receipt->getAmount(),
            'location' => $receipt->getLocation(),
            'business' => $receipt->getBusiness(),
            'category' => $receipt->getCategory(),
            'tags' => $receipt->getTags(),
            'notes' => $receipt->getNotes(),
            'raw_input' => $receipt->getRawInput(),
            'created_at' => $receipt->getCreatedAt()?->format('c'),
            'updated_at' => $receipt->getUpdatedAt()?->format('c'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateReceipt(Receipt $receipt, array $data): Receipt
    {
        if (array_key_exists('amount', $data)) {
            $receipt->setAmount(is_numeric($data['amount']) ? number_format((float) $data['amount'], 2, '.', '') : null);
        }
        if (array_key_exists('business', $data)) {
            $receipt->setBusiness($data['business'] ?? null);
        }
        if (array_key_exists('category', $data)) {
            $receipt->setCategory($data['category'] ?? null);
        }
        if (array_key_exists('location', $data)) {
            $receipt->setLocation($data['location'] ?: null);
        }
        if (array_key_exists('tags', $data)) {
            $receipt->setTags(is_array($data['tags']) ? $data['tags'] : []);
        }
        if (array_key_exists('notes', $data)) {
            $receipt->setNotes($data['notes'] ?: null);
        }
        if (array_key_exists('created_at', $data) && !empty($data['created_at'])) {
            try {
                $receipt->setCreatedAt(new \DateTimeImmutable($data['created_at']));
            } catch (\Throwable) {
                // ignore invalid dates, default will be used
            }
        }

        return $receipt;
    }

    private function buildSystemPrompt(): string
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');

        return <<<PROMPT
You are a receipt parsing assistant. Extract expense details from the user's message and return ONLY a JSON object with these fields:
- amount: number (required, in dollars)
- business: string (required, merchant name)
- category: string (required, one of: Food, Transport, Utilities, Entertainment, Shopping, Health, Other)
- location: string or null
- tags: array of strings or empty array
- notes: string or null (include the original message here)
- date: ISO 8601 datetime or null (infer from relative terms like "today", "yesterday", "last Tuesday")

Rules:
- If a field cannot be determined, use null (except amount, business, category which are required)
- For category, choose the best fit; default to "Other" if uncertain
- Normalize business names (capitalize properly)
- Today is: {$today}

Respond with ONLY the JSON object, no markdown, no explanation.
PROMPT;
    }
}
