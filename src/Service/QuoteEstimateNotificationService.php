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
            'Montant : ' . $this->formatAmount($estimate, $answers),
            $answers['disclaimer'] ?? '',
            '',
            // Contractual scope, taken from the catalog frozen with the estimate.
            'Ce qui est compris :',
            $this->formatList($answers['includes'] ?? [], 'Aucun élément enregistré'),
            '',
            'Options retenues :',
            $this->formatList(array_column($answers['selectedOptions'] ?? [], 'label'), 'Aucune option'),
            '',
            'Outils déjà utilisés : ' . ($this->formatInline($answers['toolLabels'] ?? []) ?: 'Non précisés'),
            '',
            // Qualification only: never an engagement, never part of the price.
            'Qualification IA (' . $estimate->getAiSource() . ') : ' . ($estimate->getAiSummary() ?: 'Non disponible'),
            '',
            'Réponses :',
        ];

        $rendered = ['offerLabel', 'variantLabel', 'selectedOptions', 'includes', 'pricingMode', 'disclaimer', 'toolKeys', 'toolLabels'];
        foreach ($answers as $key => $value) {
            if (!in_array($key, $rendered, true)) {
                $lines[] = sprintf('- %s : %s', $key, $this->stringifyAnswer($value));
            }
        }

        return implode("\n", $lines);
    }

    private function formatAmount(QuoteEstimate $estimate, array $answers): string
    {
        $min = $estimate->getMinimumAmount();
        $max = $estimate->getMaximumAmount();

        return match ($answers['pricingMode'] ?? 'range') {
            'fixed' => sprintf('%d € (prix ferme)', $min),
            'from' => sprintf('à partir de %d €', $min),
            default => $min === $max ? sprintf('%d €', $min) : sprintf('%d € – %d €', $min, $max),
        };
    }

    private function formatList(mixed $items, string $empty): string
    {
        if (!is_array($items) || $items === []) {
            return '- ' . $empty;
        }

        $lines = [];
        foreach ($items as $item) {
            if (is_string($item) && trim($item) !== '') {
                $lines[] = '- ' . $item;
            }
        }

        return $lines !== [] ? implode("\n", $lines) : '- ' . $empty;
    }

    private function formatInline(mixed $items): string
    {
        return is_array($items) ? implode(' · ', array_filter($items, 'is_string')) : '';
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
