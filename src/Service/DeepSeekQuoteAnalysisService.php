<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DeepSeekQuoteAnalysisService
{
    private const API_URL = 'https://api.deepseek.com/chat/completions';
    private const MAX_SUMMARY_LENGTH = 600;
    private const MAX_LIST_ITEM_LENGTH = 240;
    private const MAX_RECOMMENDED_SCOPE_ITEMS = 3;
    private const MAX_MISSING_QUESTIONS_ITEMS = 3;
    private const MAX_RISK_FLAGS_ITEMS = 2;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $deepSeekApiKey,
        private readonly string $deepSeekModel,
        private readonly int $maxTokens,
        private readonly float $temperature,
    ) {
    }

    /**
     * Never throws: any failure falls back to a deterministic summary so the
     * caller can always return a usable estimate.
     *
     * @param array{offerLabel:string,variantLabel:string,optionLabels:string[],projectStage:string,contentReadiness:string,deadline:string,projectDescription:string} $projectContext
     * @param array<int, array{label:string,impactMin:int,impactMax:int}> $calculationDetail
     *
     * @return array{summary:string,recommendedScope:string[],missingQuestions:string[],riskFlags:string[],source:string}
     */
    public function analyze(array $projectContext, array $calculationDetail): array
    {
        if ($this->deepSeekApiKey === '') {
            $this->logger->warning('Quote AI analysis skipped: DEEPSEEK_API_KEY is not configured.');

            return $this->fallback($projectContext);
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
                        [
                            'role' => 'system',
                            'content' => "Tu aides un freelance a qualifier une demande de projet. Langue: francais."
                                . " Tu ne donnes JAMAIS de montant, de prix ni de fourchette: le tarif est calcule ailleurs."
                                . " Reponds uniquement en JSON avec les cles summary (string de 2 phrases maximum),"
                                . " recommendedScope (3 strings courtes maximum), missingQuestions (3 maximum),"
                                . " riskFlags (2 maximum). Sois concis: une reponse trop longue est inutilisable.",
                        ],
                        [
                            'role' => 'user',
                            'content' => $this->buildPrompt($projectContext, $calculationDetail),
                        ],
                    ],
                    'temperature' => $this->temperature,
                    'max_tokens' => $this->maxTokens,
                ],
                'timeout' => 15,
            ]);

            $payload = $response->toArray(false);
            $content = $payload['choices'][0]['message']['content'] ?? '';
            if (!is_string($content) || trim($content) === '') {
                $this->logger->error('Quote AI analysis returned an empty response.');

                return $this->fallback($projectContext);
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                $this->logger->error('Quote AI analysis returned invalid JSON.');

                return $this->fallback($projectContext);
            }

            $allowedKeys = ['summary', 'recommendedScope', 'missingQuestions', 'riskFlags'];
            if (array_diff(array_keys($decoded), $allowedKeys) !== []) {
                $this->logger->error('Quote AI analysis returned unexpected fields.');

                return $this->fallback($projectContext);
            }

            $summary = $this->normalizeSummary($decoded['summary'] ?? null);
            $recommendedScope = $this->normalizeStringList(
                $decoded['recommendedScope'] ?? [],
                self::MAX_RECOMMENDED_SCOPE_ITEMS,
            );
            $missingQuestions = $this->normalizeStringList(
                $decoded['missingQuestions'] ?? [],
                self::MAX_MISSING_QUESTIONS_ITEMS,
            );
            $riskFlags = $this->normalizeStringList(
                $decoded['riskFlags'] ?? [],
                self::MAX_RISK_FLAGS_ITEMS,
            );

            if ($summary === null || $recommendedScope === null || $missingQuestions === null || $riskFlags === null) {
                $this->logger->error('Quote AI analysis returned a payload outside the expected schema.');

                return $this->fallback($projectContext);
            }

            return [
                'summary' => $summary,
                'recommendedScope' => $recommendedScope,
                'missingQuestions' => $missingQuestions,
                'riskFlags' => $riskFlags,
                'source' => 'deepseek',
            ];
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface | DecodingExceptionInterface $exception) {
            $this->logger->error('Quote AI analysis failed.', ['exception' => $exception]);

            return $this->fallback($projectContext);
        }
    }

    /**
     * @param array{offerLabel:string,variantLabel:string,optionLabels:string[],projectStage:string,contentReadiness:string,deadline:string,projectDescription:string} $projectContext
     * @param array<int, array{label:string,impactMin:int,impactMax:int}> $calculationDetail
     */
    private function buildPrompt(array $projectContext, array $calculationDetail): string
    {
        $factorLines = [];
        foreach ($calculationDetail as $factor) {
            $factorLines[] = '- ' . $factor['label'];
        }

        $optionLabels = $projectContext['optionLabels'];

        return implode("\n", [
            'Resultat recherche : ' . $projectContext['offerLabel'],
            'Formule choisie : ' . $projectContext['variantLabel'],
            'Fonctionnalites demandees :',
            $optionLabels !== [] ? '- ' . implode("\n- ", $optionLabels) : '- aucune fonctionnalite supplementaire',
            'Projet neuf ou existant : ' . $projectContext['projectStage'],
            'Contenus (textes, images) : ' . $projectContext['contentReadiness'],
            'Delai souhaite : ' . $projectContext['deadline'],
            'Elements retenus dans le calcul :',
            $factorLines !== [] ? implode("\n", $factorLines) : '- aucun element supplementaire',
            '',
            'Description du besoin :',
            $projectContext['projectDescription'] !== '' ? $projectContext['projectDescription'] : 'Non precisee.',
            '',
            'Redige une synthese courte et rassurante pour ce prospect, le perimetre recommande,'
                . ' les questions manquantes pour affiner, et les points de vigilance.',
        ]);
    }

    /**
     * @param array{offerLabel:string,variantLabel:string,optionLabels:string[],projectStage:string,contentReadiness:string,deadline:string,projectDescription:string} $projectContext
     *
     * @return array{summary:string,recommendedScope:string[],missingQuestions:string[],riskFlags:string[],source:string}
     */
    private function fallback(array $projectContext): array
    {
        return [
            'summary' => sprintf(
                'Estimation basee sur : %s, formule %s. La fourchette est calculee automatiquement a partir'
                    . ' de vos reponses ; un echange permettra d affiner le perimetre exact.',
                $projectContext['offerLabel'],
                $projectContext['variantLabel'],
            ),
            'recommendedScope' => ['Un premier echange pour preciser le perimetre exact.'],
            'missingQuestions' => [],
            'riskFlags' => [],
            'source' => 'fallback',
        ];
    }

    private function normalizeSummary(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $summary = trim($value);

        return $summary !== '' && mb_strlen($summary) <= self::MAX_SUMMARY_LENGTH ? $summary : null;
    }

    /**
     * @return string[]|null
     */
    private function normalizeStringList(mixed $value, int $maxItems): ?array
    {
        if (!is_array($value) || count($value) > $maxItems) {
            return null;
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }

            $normalized = trim($item);
            if ($normalized === '') {
                continue;
            }

            if (mb_strlen($normalized) > self::MAX_LIST_ITEM_LENGTH) {
                return null;
            }

            $items[] = $normalized;
        }

        return $items;
    }
}
