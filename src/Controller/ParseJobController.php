<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ParseJob;
use App\Entity\Receipt;
use App\Repository\ParseJobRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ParseJobController extends AbstractController
{
    public function __construct(
        private readonly ParseJobRepository $parseJobRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/parse-jobs', name: 'app_parse_jobs', methods: ['GET'])]
    public function index(): Response
    {
        $jobs = $this->parseJobRepository->findUncompleted();

        return $this->render('parse_job/index.html.twig', [
            'jobs' => $jobs,
        ]);
    }

    #[Route('/api/parse-jobs/{id}/retry', name: 'api_parse_jobs_retry', methods: ['POST'])]
    public function retry(int $id): JsonResponse
    {
        $job = $this->parseJobRepository->find($id);
        if (!$job) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        if ($job->getStatus() === ParseJob::STATUS_PROCESSING) {
            return new JsonResponse(['error' => 'Cannot retry a job currently processing'], Response::HTTP_CONFLICT);
        }

        if ($job->getStatus() === ParseJob::STATUS_COMPLETED) {
            return new JsonResponse(['error' => 'Cannot retry a completed job'], Response::HTTP_CONFLICT);
        }

        $job->setStatus(ParseJob::STATUS_PENDING);
        $job->setLastError(null);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/api/parse-jobs/{id}', name: 'api_parse_jobs_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $job = $this->parseJobRepository->find($id);
        if (!$job) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        if ($job->getStatus() === ParseJob::STATUS_PROCESSING) {
            return new JsonResponse(['error' => 'Cannot delete a job currently processing'], Response::HTTP_CONFLICT);
        }

        $this->entityManager->remove($job);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/parse-jobs/{id}/manual-add', name: 'api_parse_jobs_manual_add', methods: ['POST'])]
    public function manualAdd(int $id, Request $request, ValidatorInterface $validator): JsonResponse
    {
        $job = $this->parseJobRepository->find($id);
        if (!$job) {
            return new JsonResponse(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        if ($job->getStatus() === ParseJob::STATUS_COMPLETED) {
            return new JsonResponse(['error' => 'Job already completed'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['error' => 'Invalid JSON body'], Response::HTTP_BAD_REQUEST);
        }

        $receipt = new Receipt();
        $receipt->setAmount(
            isset($data['amount']) && is_numeric($data['amount'])
                ? number_format((float) $data['amount'], 2, '.', '')
                : null
        );
        $receipt->setBusiness($data['business'] ?? null);
        $receipt->setCategory($data['category'] ?? null);
        $receipt->setLocation($data['location'] ?? null);
        $receipt->setTags(is_array($data['tags'] ?? null) ? $data['tags'] : []);
        $receipt->setNotes($data['notes'] ?? $job->getRawText());
        $receipt->setRawInput($job->getRawText());

        if (!empty($data['created_at'])) {
            try {
                $receipt->setCreatedAt(new \DateTimeImmutable($data['created_at']));
            } catch (\Throwable) {
                // keep default
            }
        }

        $errors = $validator->validate($receipt);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }
            return new JsonResponse(['errors' => $messages], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->persist($receipt);

        $job->setReceipt($receipt);
        $job->setStatus(ParseJob::STATUS_COMPLETED);
        $job->setCompletedAt(new \DateTimeImmutable());
        $job->setLastError(null);

        $this->entityManager->flush();

        return new JsonResponse([
            'status' => 'ok',
            'receipt_id' => $receipt->getId(),
        ]);
    }

    #[Route('/api/parse-jobs/counts', name: 'api_parse_jobs_counts', methods: ['GET'])]
    public function counts(): JsonResponse
    {
        return new JsonResponse([
            'pending' => $this->parseJobRepository->countPending(),
            'failed' => $this->parseJobRepository->countFailed(),
        ]);
    }
}
