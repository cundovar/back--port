<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ContactRequest;
use App\Security\AdminTokenGuard;
use App\Service\BrevoEmailSender;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ContactRequestController
{
    public function __construct(
        private readonly BrevoEmailSender $brevoEmailSender,
        private readonly string $recipientEmail,
        private readonly string $senderEmail,
    ) {
    }

    #[Route('/api/contact-requests', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = $this->parseJson($request);

        if (!$this->validatePayload($payload)) {
            throw new BadRequestHttpException('Missing required fields');
        }

        if (isset($payload['honeypot']) && trim((string) $payload['honeypot']) !== '') {
            return new JsonResponse(['ok' => true], 202);
        }

        $contactRequest = new ContactRequest();
        $this->hydrateContactRequest($contactRequest, $payload);

        $em->persist($contactRequest);
        $em->flush();

        $notificationSent = $this->brevoEmailSender->send(
            $this->brevoApiKey(),
            $this->senderEmail,
            $this->recipientEmail,
            sprintf('Nouvelle demande de contact — %s', $contactRequest->getFullName()),
            $this->formatEmailBody($contactRequest),
        );

        return new JsonResponse(['ok' => true, 'notificationSent' => $notificationSent], $notificationSent ? 201 : 202);
    }

    #[Route('/api/admin/contact-requests', methods: ['GET'])]
    public function listAdmin(Request $request, EntityManagerInterface $em, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);

        $status = $request->query->get('status');
        $criteria = [];
        if ($status && in_array($status, ['new', 'in_review', 'answered', 'archived'], true)) {
            $criteria['status'] = $status;
        }

        $requests = $em->getRepository(ContactRequest::class)->findBy(
            $criteria,
            ['createdAt' => 'DESC'],
        );

        $data = array_map(fn (ContactRequest $r) => $this->mapContactRequest($r), $requests);

        return new JsonResponse($data);
    }

    #[Route('/api/admin/contact-requests/{id}', methods: ['GET'])]
    public function getRequest(int $id, EntityManagerInterface $em, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);
        $contactRequest = $em->getRepository(ContactRequest::class)->find($id);
        if (!$contactRequest) {
            throw new NotFoundHttpException('Contact request not found');
        }

        return new JsonResponse($this->mapContactRequest($contactRequest));
    }

    #[Route('/api/admin/contact-requests/{id}', methods: ['PATCH'])]
    public function updateStatus(int $id, Request $request, EntityManagerInterface $em, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);
        $contactRequest = $em->getRepository(ContactRequest::class)->find($id);
        if (!$contactRequest) {
            throw new NotFoundHttpException('Contact request not found');
        }

        $payload = $this->parseJson($request);
        $status = (string) ($payload['status'] ?? '');
        $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;

        if ($status) {
            $contactRequest->setStatus($status);
        }
        if ($notes !== null) {
            $contactRequest->setNotes($notes);
        }

        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/admin/contact-requests/{id}', methods: ['DELETE'])]
    public function delete(int $id, Request $request, EntityManagerInterface $em, AdminTokenGuard $guard): JsonResponse
    {
        $guard->assertAdmin($request);
        $contactRequest = $em->getRepository(ContactRequest::class)->find($id);
        if (!$contactRequest) {
            throw new NotFoundHttpException('Contact request not found');
        }

        $em->remove($contactRequest);
        $em->flush();

        return new JsonResponse(['ok' => true]);
    }

    private function parseJson(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }
        return $payload;
    }

    private function validatePayload(array $payload): bool
    {
        return isset($payload['fullName'], $payload['email'], $payload['missionType'], $payload['message'])
            && !empty(trim((string) $payload['fullName']))
            && !empty(trim((string) $payload['email']))
            && !empty(trim((string) $payload['missionType']))
            && !empty(trim((string) $payload['message']));
    }

    private function hydrateContactRequest(ContactRequest $contactRequest, array $payload): void
    {
        $contactRequest->setFullName((string) ($payload['fullName'] ?? ''));
        $contactRequest->setEmail((string) ($payload['email'] ?? ''));
        $contactRequest->setCompany((string) ($payload['company'] ?? ''));
        $contactRequest->setPosition((string) ($payload['position'] ?? ''));
        $contactRequest->setMissionType((string) ($payload['missionType'] ?? ''));
        $contactRequest->setMessage((string) ($payload['message'] ?? ''));
        $contactRequest->setBudget(isset($payload['budget']) ? (string) $payload['budget'] : null);
        $contactRequest->setTimeline(isset($payload['timeline']) ? (string) $payload['timeline'] : null);
        $contactRequest->setHoneypot(isset($payload['honeypot']) ? (string) $payload['honeypot'] : null);
        $contactRequest->setStatus((string) ($payload['status'] ?? ContactRequest::STATUS_NEW));
    }

    private function mapContactRequest(ContactRequest $request): array
    {
        return [
            'id' => $request->getId(),
            'fullName' => $request->getFullName(),
            'email' => $request->getEmail(),
            'company' => $request->getCompany(),
            'position' => $request->getPosition(),
            'missionType' => $request->getMissionType(),
            'message' => $request->getMessage(),
            'budget' => $request->getBudget(),
            'timeline' => $request->getTimeline(),
            'status' => $request->getStatus(),
            'createdAt' => $request->getCreatedAt()->format('c'),
            'qualifiedAt' => $request->getQualifiedAt()?->format('c'),
            'notes' => $request->getNotes(),
        ];
    }

    private function formatEmailBody(ContactRequest $request): string
    {
        return implode("\n", [
            'Nom : '.$request->getFullName(),
            'Email : '.$request->getEmail(),
            'Entreprise : '.$request->getCompany(),
            'Fonction : '.$request->getPosition(),
            'Mission : '.$request->getMissionType(),
            'Budget : '.($request->getBudget() ?: 'Non précisé'),
            'Délai : '.($request->getTimeline() ?: 'Non précisé'),
            '',
            'Message :',
            $request->getMessage(),
        ]);
    }

    private function brevoApiKey(): string
    {
        $apiKey = $_SERVER['BREVO_API_KEY'] ?? $_ENV['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY') ?: '';

        return is_string($apiKey) ? $apiKey : '';
    }
}
