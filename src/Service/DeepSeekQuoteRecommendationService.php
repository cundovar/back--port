<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Asks DeepSeek which catalog entries fit a described need.
 *
 * The model is only allowed to return KEYS of the offer it was given, plus a short
 * justification per key. Titles, includes, options and amounts are never taken from
 * the model: they are rendered from the catalog by the caller. A key the catalog
 * does not declare is dropped here, so an invented deliverable can never be priced
 * nor displayed.
 */
class DeepSeekQuoteRecommendationService
{
    private const API_URL = 'https://api.deepseek.com/chat/completions';
    private const MAX_SUMMARY_LENGTH = 400;
    private const MAX_REASON_LENGTH = 140;
    private const MAX_OPTIONS_PER_TIER = 6;

    public const TIERS = ['essential', 'complete'];

    public const SOURCE_DEEPSEEK = 'deepseek';
    public const SOURCE_UNAVAILABLE = 'unavailable';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $deepSeekApiKey,
        private readonly string $deepSeekModel,
        private readonly int $maxTokens,
        private readonly float $temperature,
        private readonly int $timeout,
    ) {
    }

    /**
     * Never throws. On any failure the caller still renders the manual choice.
     *
     * @param array{key:string,label:string,variants:array,options:array} $offer active catalog offer
     * @param array{projectDescription:string,toolLabels:string[],projectStage:string,contentReadiness:string,deadline:string} $context
     *
     * @return array{summary:string,tiers:array<string, array{variantKey:string,optionKeys:string[],reasons:array<string,string>}>,source:string}
     */
    public function recommend(array $offer, array $context): array
    {
        if ($this->deepSeekApiKey === '') {
            $this->logger->warning('Quote recommendation skipped: DEEPSEEK_API_KEY is not configured.');

            return $this->unavailable();
        }

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => sprintf('Bearer %s', $this->deepSeekApiKey),
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->deepSeekModel,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $this->buildPrompt($offer, $context)],
                    ],
                    'temperature' => $this->temperature,
                    'max_tokens' => $this->maxTokens,
                ],
                'timeout' => $this->timeout,
            ]);

            $payload = $response->toArray(false);
            $content = $payload['choices'][0]['message']['content'] ?? '';
            if (!is_string($content) || trim($content) === '') {
                $this->logger->error('Quote recommendation returned an empty response.');

                return $this->unavailable();
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                $this->logger->error('Quote recommendation returned invalid JSON.');

                return $this->unavailable();
            }

            return $this->sanitize($decoded, $offer);
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface | DecodingExceptionInterface $exception) {
            $this->logger->error('Quote recommendation failed.', ['exception' => $exception]);

            return $this->unavailable();
        }
    }

    /**
     * @return array{summary:string,tiers:array<string, array{variantKey:string,optionKeys:string[],reasons:array<string,string>}>,source:string}
     */
    private function sanitize(array $decoded, array $offer): array
    {
        $variantKeys = array_column($offer['variants'] ?? [], 'key');
        $optionKeys = array_column($offer['options'] ?? [], 'key');

        $tiers = [];
        foreach (self::TIERS as $tier) {
            $proposal = $decoded[$tier] ?? null;
            if (!is_array($proposal)) {
                continue;
            }

            $variantKey = $proposal['variantKey'] ?? null;
            if (!is_string($variantKey) || !in_array($variantKey, $variantKeys, true)) {
                // Without a valid variant there is nothing to price: drop the tier.
                continue;
            }

            $keptOptions = [];
            foreach ($proposal['optionKeys'] ?? [] as $key) {
                if (is_string($key) && in_array($key, $optionKeys, true) && !in_array($key, $keptOptions, true)) {
                    $keptOptions[] = $key;
                }

                if (count($keptOptions) >= self::MAX_OPTIONS_PER_TIER) {
                    break;
                }
            }

            $tiers[$tier] = [
                'variantKey' => $variantKey,
                'optionKeys' => $keptOptions,
                // A justification only survives if it is attached to a kept key.
                'reasons' => $this->sanitizeReasons(
                    $proposal['reasons'] ?? [],
                    array_merge([$variantKey], $keptOptions),
                ),
            ];
        }

        if ($tiers === []) {
            $this->logger->error('Quote recommendation returned no usable catalog key.');

            return $this->unavailable();
        }

        return [
            'summary' => $this->sanitizeSummary($decoded['summary'] ?? null),
            'tiers' => $tiers,
            'source' => self::SOURCE_DEEPSEEK,
        ];
    }

    /**
     * @param list<string> $allowedKeys
     *
     * @return array<string, string>
     */
    private function sanitizeReasons(mixed $reasons, array $allowedKeys): array
    {
        if (!is_array($reasons)) {
            return [];
        }

        $clean = [];
        foreach ($reasons as $key => $reason) {
            if (!is_string($key) || !is_string($reason) || !in_array($key, $allowedKeys, true)) {
                continue;
            }

            $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
            if ($reason !== '') {
                $clean[$key] = mb_substr($reason, 0, self::MAX_REASON_LENGTH);
            }
        }

        return $clean;
    }

    private function sanitizeSummary(mixed $summary): string
    {
        if (!is_string($summary)) {
            return '';
        }

        return mb_substr(trim(preg_replace('/\s+/', ' ', $summary) ?? ''), 0, self::MAX_SUMMARY_LENGTH);
    }

    /**
     * @return array{summary:string,tiers:array<string, array{variantKey:string,optionKeys:string[],reasons:array<string,string>}>,source:string}
     */
    private function unavailable(): array
    {
        return ['summary' => '', 'tiers' => [], 'source' => self::SOURCE_UNAVAILABLE];
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'Tu aides un freelance a proposer deux perimetres a un prospect. Langue: francais.',
            'Tu ne donnes JAMAIS de montant, de prix, de fourchette ni de livrable invente:',
            'tu choisis uniquement parmi les cles fournies.',
            'Reponds en JSON avec exactement les cles summary, essential et complete.',
            'summary: deux phrases maximum qui reformulent le besoin.',
            'essential: le perimetre minimal qui repond au besoin principal.',
            'complete: reprend la variante et les options de essential, et ajoute ce qui apporte',
            'du confort, du suivi ou de l autonomie.',
            'Chaque perimetre contient variantKey (une cle de variante), optionKeys (liste de cles d options,',
            'possiblement vide) et reasons (objet cle -> justification de 15 mots maximum).',
            'Si une seule proposition a du sens, renvoie la meme dans essential et complete.',
        ]);
    }

    private function buildPrompt(array $offer, array $context): string
    {
        $variants = [];
        foreach ($offer['variants'] ?? [] as $variant) {
            $includes = implode(', ', array_slice($variant['includes'] ?? [], 0, 4));
            $variants[] = sprintf('- %s : %s (%s)', $variant['key'], $variant['label'], $includes);
        }

        $options = [];
        foreach ($offer['options'] ?? [] as $option) {
            $options[] = sprintf('- %s : %s', $option['key'], $option['label']);
        }

        $tools = $context['toolLabels'];

        return implode("\n", [
            'Resultat recherche : ' . $offer['label'],
            '',
            'Variantes disponibles (utilise la cle exacte) :',
            $variants !== [] ? implode("\n", $variants) : '- aucune',
            '',
            'Options disponibles (utilise la cle exacte) :',
            $options !== [] ? implode("\n", $options) : '- aucune',
            '',
            'Outils deja utilises par le prospect : ' . ($tools !== [] ? implode(', ', $tools) : 'non precises'),
            'Projet neuf ou existant : ' . $context['projectStage'],
            'Contenus (textes, images) : ' . $context['contentReadiness'],
            'Delai souhaite : ' . $context['deadline'],
            '',
            'Besoin decrit par le prospect :',
            $context['projectDescription'],
        ]);
    }
}
