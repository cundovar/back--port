<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContactRequest;

/**
 * Both sends return false instead of throwing: the request is already saved
 * when they run, and a mail outage must never turn a received request into an
 * error for the visitor.
 */
class ContactRequestNotificationService
{
    public function __construct(
        private readonly BrevoEmailSender $brevoEmailSender,
        private readonly string $recipientEmail,
        private readonly string $senderEmail,
        private readonly string $signatureName,
    ) {
    }

    public function notify(ContactRequest $request): bool
    {
        return $this->brevoEmailSender->send(
            $this->brevoApiKey(),
            $this->senderEmail,
            $this->recipientEmail,
            sprintf('Nouvelle demande de contact — %s', $request->getFullName()),
            $this->formatOwnerBody($request),
        );
    }

    /**
     * Confirmation sent to the visitor, repeating what they typed so they keep a
     * written trace of it. It carries nothing the owner's copy adds afterwards:
     * no status, no internal notes.
     */
    public function notifyClient(ContactRequest $request): bool
    {
        return $this->brevoEmailSender->send(
            $this->brevoApiKey(),
            $this->senderEmail,
            $request->getEmail(),
            'Votre demande est bien reçue',
            $this->formatClientBody($request),
        );
    }

    private function formatOwnerBody(ContactRequest $request): string
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

    private function formatClientBody(ContactRequest $request): string
    {
        return implode("\n", [
            sprintf('Bonjour %s,', $this->firstName($request->getFullName())),
            '',
            'J’ai bien reçu votre demande. Je reviens vers vous rapidement.',
            '',
            'Voici ce que vous m’avez envoyé.',
            '',
            'Type de mission : '.$request->getMissionType(),
            'Entreprise : '.($request->getCompany() ?: 'Non précisée'),
            'Fonction : '.($request->getPosition() ?: 'Non précisée'),
            'Budget indicatif : '.($request->getBudget() ?: 'Non précisé'),
            'Délai souhaité : '.($request->getTimeline() ?: 'Non précisé'),
            '',
            'Votre besoin :',
            $request->getMessage(),
            '',
            'Si quelque chose manque ou doit être corrigé, répondez simplement à cet email.',
            '',
            'À très vite,',
            $this->signatureName,
        ]);
    }

    private function firstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $parts[0] ?? $fullName;
    }

    private function brevoApiKey(): string
    {
        $apiKey = $_SERVER['BREVO_API_KEY'] ?? $_ENV['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY') ?: '';

        return is_string($apiKey) ? $apiKey : '';
    }
}
