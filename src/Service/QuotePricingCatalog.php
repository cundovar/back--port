<?php

declare(strict_types=1);

namespace App\Service;

class QuotePricingCatalog
{
    public const MODE_FIXED = 'fixed';
    public const MODE_FROM = 'from';
    public const MODE_RANGE = 'range';

    public const PRICING_MODES = [self::MODE_FIXED, self::MODE_FROM, self::MODE_RANGE];

    /** Modes that commit to one amount, and therefore forbid any range downstream. */
    private const SINGLE_AMOUNT_MODES = [self::MODE_FIXED, self::MODE_FROM];

    /**
     * Initial commercial grid. Amounts are editable from the backoffice once
     * deployed: this is only the seed written by the migration.
     *
     * 'contentQuestion' says whether "vos textes et images" makes sense for the
     * offer. An automation or an assistant has no editorial content to write, so
     * the question is neither asked nor priced there.
     *
     * 'pricingMode' is the commercial contract of a variant:
     *   - fixed : one amount, committed for the scope listed in 'includes'
     *   - from  : one amount as a starting point, the final scope is agreed later
     *   - range : two bounds, for work whose scope cannot honestly be committed
     * A fixed or from variant must keep a single amount end to end, so the offer
     * that carries it only accepts single-amount options (see validate()).
     */
    public static function defaultCatalog(): array
    {
        return [
            'tools' => [
                ['key' => 'tableur', 'label' => 'Excel ou Google Sheets'],
                ['key' => 'email', 'label' => 'Gmail ou Outlook'],
                ['key' => 'wordpress', 'label' => 'WordPress'],
                ['key' => 'crm', 'label' => 'Un CRM ou un logiciel de gestion'],
                ['key' => 'agenda', 'label' => 'Un agenda en ligne'],
                ['key' => 'facturation', 'label' => 'Un outil de facturation'],
                ['key' => 'reseaux-sociaux', 'label' => 'Des réseaux sociaux'],
                ['key' => 'aucun', 'label' => 'Aucun outil particulier'],
            ],
            // Asked only when something already exists. Taking over a WordPress
            // and taking over a React app are two different jobs, and the tool
            // list above never says which one it is. The technical names sit in
            // brackets so the wording stays readable to a non-technical client,
            // and "je ne sais pas" is a real answer: many clients do not know.
            'stacks' => [
                ['key' => 'wordpress', 'label' => 'WordPress'],
                ['key' => 'constructeur', 'label' => 'Un créateur de site (Wix, Squarespace, Shopify)'],
                ['key' => 'sur-mesure', 'label' => 'Une application développée sur mesure (React, Node, Python…)'],
                ['key' => 'genere-ia', 'label' => 'Créé avec un outil d’IA (Lovable, Bolt, v0, Cursor…)'],
                ['key' => 'inconnu', 'label' => 'Je ne sais pas'],
            ],
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
                            'pricingMode' => 'fixed',
                            'minimumAmount' => 550,
                            'maximumAmount' => 550,
                            'priorityAmount' => 150,
                            'includes' => [
                                'Une page complète avec vos services et vos preuves',
                                'Un formulaire de contact relié à votre email',
                                'Affichage adapté au mobile',
                            ],
                        ],
                        [
                            'key' => 'wordpress-vitrine',
                            'label' => 'Un site de plusieurs pages que vous pouvez modifier',
                            'pricingMode' => 'fixed',
                            'minimumAmount' => 900,
                            'maximumAmount' => 900,
                            'priorityAmount' => 200,
                            'includes' => [
                                'Jusqu’à 5 pages (accueil, services, à propos, contact…)',
                                'Interface WordPress pour modifier vos textes vous-même',
                                'Formulaire de contact et mentions légales',
                            ],
                        ],
                        [
                            'key' => 'wordpress-avance',
                            'label' => 'Un site plus complet, avec des fonctions métier',
                            'pricingMode' => 'from',
                            'minimumAmount' => 1400,
                            'maximumAmount' => 1400,
                            'priorityAmount' => 350,
                            'includes' => [
                                'Site multi-pages avec une structure sur mesure',
                                'Mise en avant de vos offres et de vos contenus',
                                'Accompagnement à la prise en main',
                            ],
                        ],
                    ],
                    'options' => [
                        ['key' => 'prise-rdv', 'label' => 'Prise de rendez-vous en ligne', 'minimumAmount' => 250, 'maximumAmount' => 250],
                        ['key' => 'blog', 'label' => 'Espace actualités ou blog', 'minimumAmount' => 200, 'maximumAmount' => 200],
                        ['key' => 'multilingue', 'label' => 'Site en deux langues', 'minimumAmount' => 400, 'maximumAmount' => 400],
                        ['key' => 'seo-local', 'label' => 'Être trouvable sur votre ville', 'minimumAmount' => 250, 'maximumAmount' => 250],
                        ['key' => 'paiement-en-ligne', 'label' => 'Encaisser un paiement en ligne', 'minimumAmount' => 450, 'maximumAmount' => 450],
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
                            'pricingMode' => 'fixed',
                            'minimumAmount' => 500,
                            'maximumAmount' => 500,
                            'priorityAmount' => 150,
                            'includes' => [
                                'Une automatisation prête à l’emploi',
                                'Un historique de ce qui a été fait',
                                'Une courte formation pour rester autonome',
                            ],
                        ],
                        [
                            'key' => 'automatisation-multi-outils',
                            'label' => 'Relier plusieurs outils entre eux',
                            'pricingMode' => 'from',
                            'minimumAmount' => 900,
                            'maximumAmount' => 900,
                            'priorityAmount' => 250,
                            'includes' => [
                                'Vos outils synchronisés entre eux',
                                'Des règles de déclenchement adaptées à votre activité',
                                'Une alerte en cas d’échec',
                            ],
                        ],
                    ],
                    'options' => [
                        ['key' => 'relances-auto', 'label' => 'Relances automatiques par email', 'minimumAmount' => 200, 'maximumAmount' => 200],
                        ['key' => 'rapport-hebdo', 'label' => 'Rapport récapitulatif chaque semaine', 'minimumAmount' => 150, 'maximumAmount' => 150],
                        ['key' => 'import-export', 'label' => 'Reprise de vos fichiers existants', 'minimumAmount' => 250, 'maximumAmount' => 250],
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
                            'label' => 'Un assistant sur vos propres documents',
                            'pricingMode' => 'fixed',
                            'minimumAmount' => 750,
                            'maximumAmount' => 750,
                            'priorityAmount' => 200,
                            'includes' => [
                                'Un assistant nourri par vos documents',
                                'Des réponses vérifiables et traçables',
                                'Une prise en main guidée',
                            ],
                        ],
                        [
                            'key' => 'assistant-connecte',
                            'label' => 'Un assistant branché sur vos outils',
                            'pricingMode' => 'from',
                            'minimumAmount' => 1300,
                            'maximumAmount' => 1300,
                            'priorityAmount' => 350,
                            'includes' => [
                                'Un assistant relié à vos outils du quotidien',
                                'Des actions préparées puis validées par vous',
                                'Un suivi des échanges traités',
                            ],
                        ],
                    ],
                    'options' => [
                        ['key' => 'canal-site', 'label' => 'Disponible directement sur votre site', 'minimumAmount' => 300, 'maximumAmount' => 300],
                        ['key' => 'reponses-email', 'label' => 'Préparation de vos réponses email', 'minimumAmount' => 300, 'maximumAmount' => 300],
                        ['key' => 'garde-fous', 'label' => 'Garde-fous renforcés et validation avant envoi', 'minimumAmount' => 250, 'maximumAmount' => 250],
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
                            'label' => 'Reprendre et fiabiliser l’existant',
                            // Range on purpose: what a legacy site hides is unknown before opening it.
                            'pricingMode' => 'range',
                            'minimumAmount' => 400,
                            'maximumAmount' => 900,
                            'priorityAmount' => 200,
                            'includes' => [
                                'Un état des lieux de ce qui existe',
                                'Une reprise de la structure et des contenus',
                                'Un site plus rapide et plus lisible',
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
                            // Range on purpose: bespoke scope cannot be committed up front.
                            'pricingMode' => 'range',
                            'minimumAmount' => 1200,
                            'maximumAmount' => 2800,
                            'priorityAmount' => 500,
                            'includes' => [
                                'Les écrans dont vous avez besoin au quotidien',
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
                // Single amount: a fixed pack plus a range supplement would be a range again.
                'contentWriting' => [
                    'label' => 'Rédaction des contenus',
                    'minimumAmount' => 300,
                    'maximumAmount' => 300,
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

        $errors = array_merge($errors, $this->validateTools($catalog['tools'] ?? []));
        $errors = array_merge($errors, $this->validateNamedList($catalog['stacks'] ?? [], 'stacks', 'Socle invalide.'));

        // Variants may point at these; collected once so a dangling reference
        // is reported on the variant that carries it.
        $stackKeys = [];
        foreach (self::withDefaults($catalog)['stacks'] as $stack) {
            if (is_array($stack) && is_string($stack['key'] ?? null)) {
                $stackKeys[] = $stack['key'];
            }
        }

        $offers = $catalog['offers'] ?? null;
        if (!is_array($offers) || $offers === []) {
            return array_merge($errors, [['path' => 'offers', 'message' => 'Au moins une offre est requise.']]);
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

                    $errors = array_merge($errors, $this->validateVariantContract($variant, $variantPath));
                    $errors = array_merge($errors, $this->validateVariantStackKeys($variant, $variantPath, $stackKeys));
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
                $commits = $this->offerCommitsToASingleAmount($offer);

                foreach ($options as $optionIndex => $option) {
                    $optionPath = sprintf('%s.options.%d', $path, $optionIndex);
                    $errors = array_merge($errors, $this->validatePricedItem($option, $optionPath, $optionKeys));

                    if ($commits) {
                        $errors = array_merge($errors, $this->validateSingleAmount($option, $optionPath));
                    }
                }
            }
        }

        $errors = array_merge($errors, $this->validateAdjustments($catalog['adjustments'] ?? [], $catalog));

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

        $tools = $catalog['tools'] ?? null;
        $catalog['tools'] = is_array($tools) ? array_values($tools) : [];

        // A grid saved before this list existed carries no stacks. Falling back
        // to the default is what lets the question appear without the owner
        // having to open the backoffice and save again.
        $stacks = $catalog['stacks'] ?? null;
        $catalog['stacks'] = is_array($stacks) && $stacks !== []
            ? array_values($stacks)
            : self::defaultCatalog()['stacks'];

        if (!isset($catalog['adjustments']) || !is_array($catalog['adjustments'])) {
            $catalog['adjustments'] = self::defaultCatalog()['adjustments'];
        }

        return $catalog;
    }

    /**
     * Tools are context only: an unknown key is ignored rather than refused, so a
     * stale browser tab never blocks the journey.
     *
     * @param mixed $submitted keys sent by the browser
     *
     * @return array{keys: list<string>, labels: list<string>}
     */
    public function resolveTools(array $catalog, mixed $submitted, int $max = 12): array
    {
        if (!is_array($submitted)) {
            return ['keys' => [], 'labels' => []];
        }

        $known = [];
        foreach ($catalog['tools'] ?? [] as $tool) {
            if (is_array($tool) && isset($tool['key'], $tool['label']) && is_string($tool['key'])) {
                $known[$tool['key']] = (string) $tool['label'];
            }
        }

        $keys = [];
        $labels = [];
        foreach (array_slice($submitted, 0, $max) as $key) {
            if (is_string($key) && isset($known[$key]) && !in_array($key, $keys, true)) {
                $keys[] = $key;
                $labels[] = $known[$key];
            }
        }

        return ['keys' => $keys, 'labels' => $labels];
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
    private function validateAdjustments(mixed $adjustments, array $catalog): array
    {
        if (!is_array($adjustments)) {
            return [['path' => 'adjustments', 'message' => 'Structure invalide.']];
        }

        $errors = [];

        $content = $adjustments['contentWriting'] ?? null;
        if (!is_array($content)) {
            $errors[] = ['path' => 'adjustments.contentWriting', 'message' => 'Ajustement requis.'];
        } else {
            $errors = array_merge($errors, $this->validateAdjustmentLabel($content, 'adjustments.contentWriting'));
            $errors = array_merge($errors, $this->validateRange($content, 'adjustments.contentWriting'));

            // A committed pack plus a range supplement would silently become a range.
            foreach ($catalog['offers'] ?? [] as $offer) {
                if (is_array($offer) && $this->offerCommitsToASingleAmount($offer)) {
                    $errors = array_merge($errors, $this->validateSingleAmount($content, 'adjustments.contentWriting'));
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function validateTools(mixed $tools): array
    {
        if (!is_array($tools)) {
            return [['path' => 'tools', 'message' => 'Liste d’outils attendue.']];
        }

        $errors = [];
        $seen = [];

        foreach ($tools as $index => $tool) {
            $path = sprintf('tools.%d', $index);

            if (!is_array($tool)) {
                $errors[] = ['path' => $path, 'message' => 'Outil invalide.'];
                continue;
            }

            foreach (['key', 'label'] as $field) {
                if (!isset($tool[$field]) || !is_string($tool[$field]) || trim($tool[$field]) === '') {
                    $errors[] = ['path' => $path . '.' . $field, 'message' => 'Champ texte requis.'];
                }
            }

            $key = is_string($tool['key'] ?? null) ? $tool['key'] : '';
            if ($key !== '') {
                if (in_array($key, $seen, true)) {
                    $errors[] = ['path' => $path . '.key', 'message' => 'Clé d’outil en double.'];
                }
                $seen[] = $key;
            }

            // Tools feed the AI context only: a priced tool would charge an abstraction.
            foreach (['minimumAmount', 'maximumAmount', 'amount'] as $forbidden) {
                if (isset($tool[$forbidden])) {
                    $errors[] = ['path' => $path . '.' . $forbidden, 'message' => 'Un outil ne porte aucun montant.'];
                }
            }
        }

        return $errors;
    }

    /**
     * A variant may name the stacks it answers, so picking "built with an AI
     * tool" lands on the matching formula instead of asking the same thing
     * twice. Strict here on purpose: the backoffice shows both lists side by
     * side, so a dangling reference is a mistake worth reporting rather than a
     * preselection that silently never fires.
     *
     * @return list<array{path: string, message: string}>
     */
    private function validateVariantStackKeys(mixed $variant, string $path, array $stackKeys): array
    {
        if (!is_array($variant) || !array_key_exists('stackKeys', $variant)) {
            return [];
        }

        $keys = $variant['stackKeys'];
        if (!is_array($keys)) {
            return [['path' => $path . '.stackKeys', 'message' => 'Liste de socles attendue.']];
        }

        $errors = [];
        $seen = [];

        foreach ($keys as $key) {
            if (!is_string($key) || trim($key) === '') {
                $errors[] = ['path' => $path . '.stackKeys', 'message' => 'Clé de socle invalide.'];
                continue;
            }

            if (in_array($key, $seen, true)) {
                $errors[] = ['path' => $path . '.stackKeys', 'message' => 'Socle en double.'];
                continue;
            }

            $seen[] = $key;

            if (!in_array($key, $stackKeys, true)) {
                $errors[] = ['path' => $path . '.stackKeys', 'message' => sprintf('Socle inconnu : %s.', $key)];
            }
        }

        return $errors;
    }

    /**
     * Completes a stored grid with the lists it predates. Callers read through
     * this rather than from the entity: the grid in the database was saved
     * before `stacks` existed, and without this the question would stay hidden
     * until someone opened the backoffice and saved the grid by hand.
     *
     * @param array<string, mixed> $catalog
     *
     * @return array<string, mixed>
     */
    public static function withDefaults(array $catalog): array
    {
        foreach (['stacks'] as $list) {
            if (!isset($catalog[$list]) || !is_array($catalog[$list]) || $catalog[$list] === []) {
                $catalog[$list] = self::defaultCatalog()[$list];
            }
        }

        return $catalog;
    }

    /**
     * Same contract as the tools: a key, a label, no duplicate, no amount. Both
     * lists are context for the qualification and must never reach a price.
     *
     * @return list<array{path: string, message: string}>
     */
    private function validateNamedList(mixed $items, string $root, string $invalidMessage): array
    {
        if (!is_array($items)) {
            return [['path' => $root, 'message' => 'Liste attendue.']];
        }

        $errors = [];
        $seen = [];

        foreach ($items as $index => $item) {
            $path = sprintf('%s.%d', $root, $index);

            if (!is_array($item)) {
                $errors[] = ['path' => $path, 'message' => $invalidMessage];
                continue;
            }

            foreach (['key', 'label'] as $field) {
                if (!isset($item[$field]) || !is_string($item[$field]) || trim($item[$field]) === '') {
                    $errors[] = ['path' => $path . '.' . $field, 'message' => 'Champ texte requis.'];
                }
            }

            $key = is_string($item['key'] ?? null) ? $item['key'] : '';
            if ($key !== '') {
                if (in_array($key, $seen, true)) {
                    $errors[] = ['path' => $path . '.key', 'message' => 'Clé en double.'];
                }
                $seen[] = $key;
            }

            foreach (['minimumAmount', 'maximumAmount', 'amount'] as $forbidden) {
                if (isset($item[$forbidden])) {
                    $errors[] = ['path' => $path . '.' . $forbidden, 'message' => 'Cet élément ne porte aucun montant.'];
                }
            }
        }

        return $errors;
    }

    /**
     * One choice, not a list. An unknown key is ignored rather than refused, for
     * the same reason as the tools: a stale browser tab never blocks the journey.
     *
     * @return array{key: string, label: string}
     */
    public function resolveStack(array $catalog, mixed $submitted): array
    {
        if (!is_string($submitted) || $submitted === '') {
            return ['key' => '', 'label' => ''];
        }

        foreach ($catalog['stacks'] ?? [] as $stack) {
            if (is_array($stack) && ($stack['key'] ?? null) === $submitted && isset($stack['label'])) {
                return ['key' => $submitted, 'label' => (string) $stack['label']];
            }
        }

        return ['key' => '', 'label' => ''];
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function validateVariantContract(mixed $variant, string $path): array
    {
        if (!is_array($variant)) {
            return [];
        }

        $errors = [];

        $mode = $variant['pricingMode'] ?? null;
        if (!is_string($mode) || !in_array($mode, self::PRICING_MODES, true)) {
            $errors[] = ['path' => $path . '.pricingMode', 'message' => 'Mode attendu : fixe, à partir de, ou fourchette.'];
        } elseif (in_array($mode, self::SINGLE_AMOUNT_MODES, true)) {
            $errors = array_merge($errors, $this->validateSingleAmount($variant, $path));
        }

        $priority = $variant['priorityAmount'] ?? null;
        if (!is_int($priority) || $priority < 0) {
            $errors[] = ['path' => $path . '.priorityAmount', 'message' => 'Supplément entier positif attendu (0 si aucun).'];
        }

        return $errors;
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    private function validateSingleAmount(mixed $item, string $path): array
    {
        if (!is_array($item) || !is_int($item['minimumAmount'] ?? null) || !is_int($item['maximumAmount'] ?? null)) {
            return [];
        }

        if ($item['minimumAmount'] !== $item['maximumAmount']) {
            return [[
                'path' => $path . '.maximumAmount',
                'message' => 'Un prix ferme ou « à partir de » attend un montant unique : minimum et maximum doivent être égaux.',
            ]];
        }

        return [];
    }

    /** True when at least one variant of the offer commits to a single amount. */
    private function offerCommitsToASingleAmount(array $offer): bool
    {
        foreach ($offer['variants'] ?? [] as $variant) {
            if (is_array($variant) && in_array($variant['pricingMode'] ?? null, self::SINGLE_AMOUNT_MODES, true)) {
                return true;
            }
        }

        return false;
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
