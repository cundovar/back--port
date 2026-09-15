<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\QuoteEstimate;

class QuoteEstimateNotificationService
{
    public function __construct(
        private readonly BrevoEmailSender $brevoEmailSender,
        private readonly string $recipientEmail,
        private readonly string $senderEmail,
    ) {
    }

    /**
     * Returns false instead of throwing when the notification cannot be sent,
     * so a failed email never invalidates a saved estimate.
     */
    public function notify(QuoteEstimate $estimate): bool
    {
        return $this->brevoEmailSender->send(
            $this->brevoApiKey(),
            $this->senderEmail,
            $this->recipientEmail,
            sprintf('Nouvelle estimation — %s', $estimate->getFullName()),
            $this->formatBody($estimate),
        );
    }

    private function formatBody(QuoteEstimate $estimate): string
    {
        $answers = $estimate->getAnswers();
        $lines = [
            'Nom : ' . $estimate->getFullName(),
            'Email : ' . $estimate->getEmail(),
            'Entreprise : ' . ($estimate->getCompany() ?: 'Non précisée'),
            'Téléphone : ' . ($estimate->getPhone() ?: 'Non précisé'),
            '',
            'Prestation : ' . ($answers['offerLabel'] ?? $estimate->getServiceKey()),
            'Formule : ' . ($answers['variantLabel'] ?? '—'),
            'Grille tarifaire : version ' . $estimate->getPricingVersion(),
            sprintf('Fourchette : %d € – %d €', $estimate->getMinimumAmount(), $estimate->getMaximumAmount()),
            'Synthèse (' . $estimate->getAiSource() . ') : ' . ($estimate->getAiSummary() ?: 'Non disponible'),
            '',
            'Réponses :',
        ];

        foreach ($answers as $key => $value) {
            $lines[] = sprintf('- %s : %s', $key, $this->stringifyAnswer($value));
        }

        return implode("\n", $lines);
    }

    private function stringifyAnswer(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'oui' : 'non';
        }

        if (is_array($value)) {
            if (isset($value['label']) && is_string($value['label'])) {
                return $value['label'];
            }

            return implode(', ', array_map(fn ($item): string => $this->stringifyAnswer($item), $value));
        }

        return (string) $value;
    }

    private function brevoApiKey(): string
    {
        $apiKey = $_SERVER['BREVO_API_KEY'] ?? $_ENV['BREVO_API_KEY'] ?? getenv('BREVO_API_KEY') ?: '';

        return is_string($apiKey) ? $apiKey : '';
    }
}
