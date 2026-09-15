<?php

declare(strict_types=1);

namespace App\Service;

class QuotePricingCatalog
{
    /**
     * Initial commercial grid. Amounts are editable from the backoffice once
     * deployed: this is only the seed written by the migration.
     *
     * 'contentQuestion' says whether "vos textes et images" makes sense for the
     * offer. An automation or an assistant has no editorial content to write, so
     * the question is neither asked nor priced there.
     */
    public static function defaultCatalog(): array
    {
        return [
            'offers' => [
                [
                    'key' => 'site-vitrine',
                    'contentQuestion' => true,
                    'label' => 'Présenter mon activité en ligne',
                    'summary' => 'Un site qui explique ce que vous faites et vous amène des demandes.',
                    'variants' => [
                        [
                            'key' => 'landing-page',
                            'label' => 'Une page unique, claire et rapide',
                            'minimumAmount' => 350,
                            'maximumAmount' => 650,
                            'includes' => [
                                'Une page complète avec vos services et vos preuves',
                                'Un formulaire de contact relié à votre email',
                                'Affichage adapté au mobile',
                            ],
                        ],
                        [
                            'key' => 'wordpress-vitrine',
                            'label' => 'Un site de plusieurs pages que vous pouvez modifier',
                            'minimumAmount' => 600,
                            'maximumAmount' => 1100,
                            'includes' => [
                                'Jusqu’à 5 pages (accueil, services, à propos, contact…)',
                                'Interface WordPress pour modifier vos textes vous-même',
                                'Formulaire de contact et mentions légales',
                            ],
                        ],
                        [
                            'key' => 'wordpress-avance',
                            'label' => 'Un site plus complet, avec des fonctions métier',
                            'minimumAmount' => 1100,
                            'maximumAmount' => 2200,
                            'includes' => [
                                'Site multi-pages avec une structure sur mesure',
                                'Mise en avant de vos offres et de vos contenus',
                                'Accompagnement à la prise en main',
                            ],
                        ],
                    ],
                    'options' => [
                        ['key' => 'prise-rdv', 'label' => 'Prise de rendez-vous en ligne', 'minimumAmount' => 150, 'maximumAmount' => 350],
                        ['key' => 'blog', 'label' => 'Espace actualités ou blog', 'minimumAmount' => 120, 'maximumAmount' => 300],
                        ['key' => 'multilingue', 'label' => 'Site en deux langues', 'minimumAmount' => 250, 'maximumAmount' => 600],
                        ['key' => 'seo-local', 'label' => 'Être trouvable sur votre ville', 'minimumAmount' => 150, 'maximumAmount' => 400],
                        ['key' => 'paiement-en-ligne', 'label' => 'Encaisser un paiement en ligne', 'minimumAmount' => 300, 'maximumAmount' => 700],
                    ],
                ],
                [
                    'key' => 'automatisation',
                    'contentQuestion' => false,
                    'label' => 'Arrêter de refaire la même tâche',
                    'summary' => 'Les actions répétitives sont faites automatiquement, sans vous.',
                    'variants' => [
                        [
                            'key' => 'automatisation-ciblee',
                            'label' => 'Une tâche précise à automatiser',
                            'minimumAmount' => 300,
                            'maximumAmount' => 600,
                            'includes' => [
                                'Une automatisation prête à l’emploi',
                                'Un historique de ce qui a été fait',
                                'Une courte formation pour rester autonome',
                            ],
                        ],
                        [
                            'key' => 'automatisation-multi-outils',
                            'label' => 'Plusieurs outils à faire communiquer',
                            'minimumAmount' => 700,
                            'maximumAmount' => 1500,
                            'includes' => [
                                'Les informations circulent entre vos outils',
                                'Un tableau de suivi simple',
                                'Des alertes en cas d’anomalie',
                            ],
                        ],
                    ],
                    'options' => [
                        ['key' => 'relances-auto', 'label' => 'Relances automatiques par email', 'minimumAmount' => 120, 'maximumAmount' => 300],
                        ['key' => 'rapport-hebdo', 'label' => 'Rapport récapitulatif chaque semaine', 'minimumAmount' => 100, 'maximumAmount' => 250],
                        ['key' => 'import-export', 'label' => 'Reprise de vos fichiers existants', 'minimumAmount' => 150, 'maximumAmount' => 400],
                    ],
                ],
                [
                    'key' => 'assistant-ia',
                    'contentQuestion' => false,
                    'label' => 'Un assistant IA pour mon activité',
                    'summary' => 'Un assistant qui répond, trie ou rédige à partir de vos informations.',
                    'variants' => [
                        [
                            'key' => 'assistant-initial',
                            'label' => 'Un premier assistant, sur un usage précis',
                            'minimumAmount' => 500,
                            'maximumAmount' => 900,
                            'includes' => [
                                'Un assistant testable sur votre cas réel',
                                'Des règles de validation humaine',
                                'Un écran pour surveiller les réponses',
                            ],
                        ],
                        [
                            'key' => 'assistant-connecte',
                            'label' => 'Un assistant relié à vos données',
                            'minimumAmount' => 1000,
                            'maximumAmount' => 2000,
                            'includes' => [
                                'L’assistant s’appuie sur vos documents ou votre catalogue',
                                'Des réponses sourcées et vérifiables',
                                'Un suivi de la qualité des réponses',
                            ],
                        ],
                    ],
                    'options' => [
                        ['key' => 'canal-site', 'label' => 'Disponible directement sur votre site', 'minimumAmount' => 200, 'maximumAmount' => 450],
                        ['key' => 'reponses-email', 'label' => 'Préparation de vos réponses email', 'minimumAmount' => 200, 'maximumAmount' => 500],
                        ['key' => 'garde-fous', 'label' => 'Garde-fous renforcés et validation avant envoi', 'minimumAmount' => 150, 'maximumAmount' => 400],
                    ],
                ],
                [
                    'key' => 'refonte',
                    'contentQuestion' => true,
                    'label' => 'Remettre au propre un site existant',
                    'summary' => 'On repart de ce qui existe pour le rendre plus clair et plus fiable.',
                    'variants' => [
                        [
                            'key' => 'refonte-ciblee',
                            'label' => 'Corriger et moderniser l’existant',
                            'minimumAmount' => 400,
                            'maximumAmount' => 900,
                            'includes' => [
                                'Un diagnostic clair de ce qui pose problème',
                                'Les corrections prioritaires appliquées',
                                'Une interface plus simple à utiliser',
                            ],
                        ],
                    ],
                    'options' => [
                        ['key' => 'reprise-contenus', 'label' => 'Reprise et remise en forme des contenus', 'minimumAmount' => 150, 'maximumAmount' => 450],
                        ['key' => 'performance', 'label' => 'Site nettement plus rapide', 'minimumAmount' => 150, 'maximumAmount' => 400],
                        ['key' => 'accessibilite', 'label' => 'Accessibilité améliorée', 'minimumAmount' => 200, 'maximumAmount' => 500],
                    ],
                ],
                [
                    'key' => 'outil-metier',
                    'contentQuestion' => false,
                    'label' => 'Un outil sur mesure pour mon métier',
                    'summary' => 'Une application pensée pour votre façon de travailler.',
                    'variants' => [
                        [
                            'key' => 'outil-mvp',
                            'label' => 'Une première version utilisable',
                            'minimumAmount' => 1200,
                            'maximumAmount' => 2800,
                            'includes' => [
                                'Les écrans indispensables à votre activité',
                                'Des accès distincts selon les personnes',
                                'Une mise en ligne et un accompagnement',
                            ],
                        ],
                    ],
                    'options' => [
                        ['key' => 'espace-client', 'label' => 'Un espace réservé à vos clients', 'minimumAmount' => 300, 'maximumAmount' => 800],
                        ['key' => 'exports', 'label' => 'Exports et documents générés', 'minimumAmount' => 150, 'maximumAmount' => 450],
                        ['key' => 'suivi-activite', 'label' => 'Tableau de suivi de votre activité', 'minimumAmount' => 200, 'maximumAmount' => 600],
                    ],
                ],
            ],
            'adjustments' => [
                'priorityDelay' => [
                    'label' => 'Délai prioritaire',
                    'multiplier' => 1.25,
                ],
                'contentWriting' => [
                    'label' => 'Rédaction des contenus',
                    'minimumAmount' => 150,
                    'maximumAmount' => 400,
                ],
            ],
        ];
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    public function validate(array $catalog): array
    {
        $errors = [];

        $offers = $catalog['offers'] ?? null;
        if (!is_array($offers) || $offers === []) {
            return [['path' => 'offers', 'message' => 'Au moins une offre est requise.']];
        }

        $offerKeys = [];
        foreach ($offers as $index => $offer) {
            $path = sprintf('offers.%d', $index);

            if (!is_array($offer)) {
                $errors[] = ['path' => $path, 'message' => 'Offre invalide.'];
                continue;
            }

            foreach (['key', 'label'] as $field) {
                if (!isset($offer[$field]) || !is_string($offer[$field]) || trim($offer[$field]) === '') {
                    $errors[] = ['path' => $path . '.' . $field, 'message' => 'Champ texte requis.'];
                }
            }

            $key = is_string($offer['key'] ?? null) ? $offer['key'] : '';
            if ($key !== '') {
                if (in_array($key, $offerKeys, true)) {
                    $errors[] = ['path' => $path . '.key', 'message' => 'Clé d’offre en double.'];
                }
                $offerKeys[] = $key;
            }

            $variants = $offer['variants'] ?? null;
            if (!is_array($variants) || $variants === []) {
                $errors[] = ['path' => $path . '.variants', 'message' => 'Au moins une variante est requise.'];
            } else {
                $variantKeys = [];
                foreach ($variants as $variantIndex => $variant) {
                    $variantPath = sprintf('%s.variants.%d', $path, $variantIndex);
                    $errors = array_merge($errors, $this->validatePricedItem($variant, $variantPath, $variantKeys));

                    if (isset($variant['includes']) && !$this->isStringList($variant['includes'])) {
                        $errors[] = ['path' => $variantPath . '.includes', 'message' => 'Liste de textes attendue.'];
                    }
                }
            }

            if (isset($offer['contentQuestion']) && !is_bool($offer['contentQuestion'])) {
                $errors[] = ['path' => $path . '.contentQuestion', 'message' => 'Valeur oui/non attendue.'];
            }

            $options = $offer['options'] ?? [];
            if (!is_array($options)) {
                $errors[] = ['path' => $path . '.options', 'message' => 'Liste d’options attendue.'];
            } else {
                $optionKeys = [];
                foreach ($options as $optionIndex => $option) {
                    $errors = array_merge(
                        $errors,
                        $this->validatePricedItem($option, sprintf('%s.options.%d', $path, $optionIndex), $optionKeys),
                    );
                }
            }
        }

        $errors = array_merge($errors, $this->validateAdjustments($catalog['adjustments'] ?? []));

        return $errors;
    }

    /**
     * Re-indexes the lists so JSON objects sent by the editor come back as arrays.
     * Never assumes a shape: a malformed draft must reach validate() and produce a
     * structured 422, not a server error.
     */
    public function normalize(array $catalog): array
    {
        $offers = $catalog['offers'] ?? null;
        $catalog['offers'] = is_array($offers) ? array_values($offers) : [];

        foreach ($catalog['offers'] as $index => $offer) {
            if (!is_array($offer)) {
                continue;
            }

            foreach (['variants', 'options'] as $collection) {
                $items = $offer[$collection] ?? null;
                $catalog['offers'][$index][$collection] = is_array($items) ? array_values($items) : [];
            }
        }

        if (!isset($catalog['adjustments']) || !is_array($catalog['adjustments'])) {
            $catalog['adjustments'] = self::defaultCatalog()['adjustments'];
        }

        return $catalog;
    }

    public function findOffer(array $catalog, string $offerKey): ?array
    {
        foreach ($catalog['offers'] ?? [] as $offer) {
            if (($offer['key'] ?? null) === $offerKey) {
                return $offer;
            }
        }

        return null;
    }

    public function findVariant(array $offer, string $variantKey): ?array
    {
        foreach ($offer['variants'] ?? [] as $variant) {
            if (($variant['key'] ?? null) === $variantKey) {
                return $variant;
            }
        }

        return null;
    }

    public function findOption(array $offer, string $optionKey): ?array
    {
        foreach ($offer['options'] ?? [] as $option) {
            if (($option['key'] ?? null) === $optionKey) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param list<string> $seenKeys
     *
     * @return list<array{path: string, message: string}>
     */
    private function validatePricedItem(mixed $item, string $path, array &$seenKeys): array
    {
        if (!is_array($item)) {
            return [['path' => $path, 'message' => 'Élément invalide.']];
        }

        $errors = [];

        foreach (['key', 'label'] as $field) {
            if (!isset($item[$field]) || !is_string($item[$field]) || trim($item[$field]) === '') {
                $errors[] = ['path' => $path . '.' . $field, 'message' => 'Champ texte requis.'];
            }
        }

        $key = is_string($item['key'] ?? null) ? $item['key'] : '';
        if ($key !== '') {
            if (in_array($key, $seenKeys, true)) {
                $errors[] = ['path' => $path . '.key', 'message' => 'Clé en double.'];
            }
            $seenKeys[] = $key;
        }

        return array_merge($errors, $this->validateRange($item, $path));
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function validateRange(array $item, string $path): array
    {
        $errors = [];

        foreach (['minimumAmount', 'maximumAmount'] as $field) {
            $value = $item[$field] ?? null;
            if (!is_int($value) || $value < 0) {
                $errors[] = ['path' => $path . '.' . $field, 'message' => 'Montant entier positif attendu.'];
            }
        }

        if ($errors === [] && $item['minimumAmount'] > $item['maximumAmount']) {
            $errors[] = ['path' => $path . '.maximumAmount', 'message' => 'Le maximum doit être supérieur ou égal au minimum.'];
        }

        return $errors;
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function validateAdjustments(mixed $adjustments): array
    {
        if (!is_array($adjustments)) {
            return [['path' => 'adjustments', 'message' => 'Structure invalide.']];
        }

        $errors = [];

        $priority = $adjustments['priorityDelay'] ?? null;
        if (!is_array($priority)) {
            $errors[] = ['path' => 'adjustments.priorityDelay', 'message' => 'Ajustement requis.'];
        } else {
            $errors = array_merge($errors, $this->validateAdjustmentLabel($priority, 'adjustments.priorityDelay'));

            $multiplier = $priority['multiplier'] ?? null;
            if (!is_int($multiplier) && !is_float($multiplier)) {
                $errors[] = ['path' => 'adjustments.priorityDelay.multiplier', 'message' => 'Multiplicateur numérique attendu.'];
            } elseif ($multiplier < 1.0 || $multiplier > 3.0) {
                $errors[] = ['path' => 'adjustments.priorityDelay.multiplier', 'message' => 'Multiplicateur attendu entre 1 et 3.'];
            }
        }

        $content = $adjustments['contentWriting'] ?? null;
        if (!is_array($content)) {
            $errors[] = ['path' => 'adjustments.contentWriting', 'message' => 'Ajustement requis.'];
        } else {
            $errors = array_merge($errors, $this->validateAdjustmentLabel($content, 'adjustments.contentWriting'));
            $errors = array_merge($errors, $this->validateRange($content, 'adjustments.contentWriting'));
        }

        return $errors;
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function validateAdjustmentLabel(array $adjustment, string $path): array
    {
        $label = $adjustment['label'] ?? null;
        if (!is_string($label) || trim($label) === '') {
            return [['path' => $path . '.label', 'message' => 'Champ texte requis.']];
        }

        return [];
    }

    private function isStringList(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }
}
