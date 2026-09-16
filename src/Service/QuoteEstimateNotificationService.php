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
        private readonly string $signatureName,
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

    /**
     * Acknowledgement sent to the prospect, so they keep a written trace of what
     * they asked for. Deliberately narrower than notify(): no AI qualification,
     * no pricing version, no raw answers — only the scope and the amount the
     * catalog already showed them on screen.
     */
    public function notifyClient(QuoteEstimate $estimate): bool
    {
        return $this->brevoEmailSender->send(
            $this->brevoApiKey(),
            $this->senderEmail,
            $estimate->getEmail(),
            sprintf('Votre demande est bien reçue — %s', $this->offerLabel($estimate)),
            $this->formatClientBody($estimate),
        );
    }

    private function formatClientBody(QuoteEstimate $estimate): string
    {
        $answers = $estimate->getAnswers();

        return implode("\n", [
            sprintf('Bonjour %s,', $this->firstName($estimate->getFullName())),
            '',
            'J\'ai bien reçu votre demande. Je reviens vers vous rapidement pour en parler.',
            '',
            'Voici le récapitulatif de ce que vous avez sélectionné.',
            '',
            'Votre projet : ' . $this->offerLabel($estimate),
            'Formule : ' . ($answers['variantLabel'] ?? '—'),
            'Montant : ' . $this->formatAmount($estimate, $answers),
            $answers['disclaimer'] ?? '',
            '',
            'Ce qui est compris :',
            $this->formatList($answers['includes'] ?? [], 'Aucun élément enregistré'),
            '',
            'Options retenues :',
            $this->formatList(array_column($answers['selectedOptions'] ?? [], 'label'), 'Aucune option'),
            '',
            'Outils que vous utilisez déjà : ' . ($this->formatInline($answers['toolLabels'] ?? []) ?: 'Non précisés'),
            '',
            'Ce montant reprend la formule et les options que vous avez choisies. Il ne remplace pas un devis : nous le confirmons ensemble une fois le périmètre précisé.',
            '',
            'Si quelque chose ne correspond pas à votre besoin, répondez simplement à cet email.',
            '',
            'À très vite,',
            $this->signatureName,
        ]);
    }

    private function offerLabel(QuoteEstimate $estimate): string
    {
        $label = $estimate->getAnswers()['offerLabel'] ?? null;

        return is_string($label) && trim($label) !== '' ? $label : $estimate->getServiceKey();
    }

    private function firstName(string $fullName): string
    {
        $parts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $parts[0] ?? $fullName;
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
            'fixed' => sprintf('%s (prix ferme)', $this->formatEuros($min)),
            'from' => sprintf('à partir de %s', $this->formatEuros($min)),
            default => $min === $max
                ? $this->formatEuros($min)
                : sprintf('%s – %s', $this->formatEuros($min), $this->formatEuros($max)),
        };
    }

    /**
     * French grouping with non-breaking spaces, so "1 250 €" never splits across
     * two lines in a mail client.
     */
    private function formatEuros(int $amount): string
    {
        return number_format($amount, 0, ',', "\u{00A0}") . "\u{00A0}€";
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
