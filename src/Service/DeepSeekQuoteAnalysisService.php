<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DeepSeekQuoteAnalysisService
{
    private const API_URL = 'https://api.deepseek.com/chat/completions';

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
     * @param array{serviceLabel:string,complexityLabel:string,integrationsCount:int,legacyTakeover:bool,urgency:bool,trainingLabel:string,projectDescription:string} $projectContext
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

            $summary = $decoded['summary'] ?? null;
            if (!is_string($summary) || trim($summary) === '') {
                $this->logger->error('Quote AI analysis returned no usable summary.');

                return $this->fallback($projectContext);
            }

            return [
                'summary' => trim($summary),
                'recommendedScope' => $this->normalizeStringList($decoded['recommendedScope'] ?? []),
                'missingQuestions' => $this->normalizeStringList($decoded['missingQuestions'] ?? []),
                'riskFlags' => $this->normalizeStringList($decoded['riskFlags'] ?? []),
                'source' => 'deepseek',
            ];
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface $exception) {
            $this->logger->error('Quote AI analysis failed.', ['exception' => $exception]);

            return $this->fallback($projectContext);
        }
    }

    /**
     * @param array{serviceLabel:string,complexityLabel:string,integrationsCount:int,legacyTakeover:bool,urgency:bool,trainingLabel:string,projectDescription:string} $projectContext
     * @param array<int, array{label:string,impactMin:int,impactMax:int}> $calculationDetail
     */
    private function buildPrompt(array $projectContext, array $calculationDetail): string
    {
        $factorLines = [];
        foreach ($calculationDetail as $factor) {
            $factorLines[] = '- ' . $factor['label'];
        }

        return implode("\n", [
            'Type de prestation : ' . $projectContext['serviceLabel'],
            'Complexite : ' . $projectContext['complexityLabel'],
            'Nombre d integrations : ' . $projectContext['integrationsCount'],
            'Reprise d un existant : ' . ($projectContext['legacyTakeover'] ? 'oui' : 'non'),
            'Urgence : ' . ($projectContext['urgency'] ? 'oui' : 'non'),
            'Accompagnement : ' . $projectContext['trainingLabel'],
            'Facteurs retenus dans le calcul :',
            $factorLines !== [] ? implode("\n", $factorLines) : '- aucun facteur supplementaire',
            '',
            'Description du besoin :',
            $projectContext['projectDescription'] !== '' ? $projectContext['projectDescription'] : 'Non precisee.',
            '',
            'Redige une synthese courte et rassurante pour ce prospect, le perimetre recommande,'
                . ' les questions manquantes pour affiner, et les points de vigilance.',
        ]);
    }

    /**
     * @param array{serviceLabel:string,complexityLabel:string,integrationsCount:int,legacyTakeover:bool,urgency:bool,trainingLabel:string,projectDescription:string} $projectContext
     *
     * @return array{summary:string,recommendedScope:string[],missingQuestions:string[],riskFlags:string[],source:string}
     */
    private function fallback(array $projectContext): array
    {
        return [
            'summary' => sprintf(
                'Estimation basee sur un projet de type %s (complexite %s). La fourchette est calculee automatiquement'
                    . ' a partir de vos reponses ; un echange permettra d affiner le perimetre exact.',
                $projectContext['serviceLabel'],
                $projectContext['complexityLabel'],
            ),
            'recommendedScope' => ['Un premier echange pour preciser le perimetre exact.'],
            'missingQuestions' => [],
            'riskFlags' => [],
            'source' => 'fallback',
        ];
    }

    /**
     * @return string[]
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }
}
