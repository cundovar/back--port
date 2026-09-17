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
        private readonly BrandedEmailRenderer $renderer,
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
            $this->renderOwnerHtml($request),
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
            $this->renderClientHtml($request),
        );
    }

    private function renderOwnerHtml(ContactRequest $request): string
    {
        return $this->renderer->render(
            'Nouvelle demande',
            sprintf('%s — %s', $request->getFullName(), $request->getMissionType()),
            $this->renderer->rows($this->ownerFields($request))
                .$this->renderer->subheading('Son besoin')
                .$this->renderer->quote($request->getMessage()),
        );
    }

    private function renderClientHtml(ContactRequest $request): string
    {
        return $this->renderer->render(
            'Demande bien reçue',
            'Voici le récapitulatif de ce que vous venez d’envoyer.',
            $this->renderer->paragraph(sprintf('Bonjour %s,', $this->firstName($request->getFullName())))
                .$this->renderer->paragraph('J’ai bien reçu votre demande. Je reviens vers vous rapidement.')
                .$this->renderer->subheading('Ce que vous m’avez envoyé')
                .$this->renderer->rows($this->clientFields($request))
                .$this->renderer->subheading('Votre besoin')
                .$this->renderer->quote($request->getMessage())
                .$this->renderer->note('Si quelque chose manque ou doit être corrigé, répondez simplement à cet email.')
                .$this->renderer->signature($this->signatureName),
        );
    }

    /** @return array<string, string> */
    private function ownerFields(ContactRequest $request): array
    {
        return [
            'Nom' => $request->getFullName(),
            'Email' => $request->getEmail(),
            'Entreprise' => $request->getCompany() ?: 'Non précisée',
            'Fonction' => $request->getPosition() ?: 'Non précisée',
            'Mission' => $request->getMissionType(),
            'Budget' => $request->getBudget() ?: 'Non précisé',
            'Délai' => $request->getTimeline() ?: 'Non précisé',
        ];
    }

    /** @return array<string, string> */
    private function clientFields(ContactRequest $request): array
    {
        return [
            'Type de mission' => $request->getMissionType(),
            'Entreprise' => $request->getCompany() ?: 'Non précisée',
            'Fonction' => $request->getPosition() ?: 'Non précisée',
            'Budget indicatif' => $request->getBudget() ?: 'Non précisé',
            'Délai souhaité' => $request->getTimeline() ?: 'Non précisé',
        ];
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
