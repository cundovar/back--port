<?php

declare(strict_types=1);

namespace App\Service;

final class ContentSchemaService
{
    private const ARRAY_SECTIONS = ['services', 'problems', 'process', 'expertise'];

    private const DEFAULTS = [
        'services' => [],
        'problems' => [],
        'process' => [],
        'expertise' => [],
    ];

    public function normalize(array $payload): array
    {
        foreach (self::DEFAULTS as $section => $defaultValue) {
            if (!array_key_exists($section, $payload)) {
                $payload[$section] = $defaultValue;
            }
        }

        return $payload;
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    public function validate(array $payload): array
    {
        $errors = [];

        foreach (self::ARRAY_SECTIONS as $section) {
            if (array_key_exists($section, $payload) && !is_array($payload[$section])) {
                $errors[] = [
                    'path' => $section,
                    'message' => 'Expected an array.',
                ];
            }
        }

        foreach (($payload['services'] ?? []) as $index => $service) {
            if (!$this->isAssocArray($service)) {
                $errors[] = ['path' => sprintf('services.%d', $index), 'message' => 'Expected an object.'];
                continue;
            }

            foreach (['title', 'promise', 'actionLabel'] as $field) {
                if (!$this->hasOptionalString($service, $field)) {
                    $errors[] = [
                        'path' => sprintf('services.%d.%s', $index, $field),
                        'message' => 'Expected a string.',
                    ];
                }
            }

            foreach (['problems', 'deliverables', 'technologies'] as $field) {
                if (!$this->hasOptionalStringList($service, $field)) {
                    $errors[] = [
                        'path' => sprintf('services.%d.%s', $index, $field),
                        'message' => 'Expected an array of strings.',
                    ];
                }
            }

            if (!$this->hasOptionalFaqList($service, 'faqs')) {
                $errors[] = [
                    'path' => sprintf('services.%d.faqs', $index),
                    'message' => 'Expected an array of question and answer objects.',
                ];
            }

            if (!$this->hasOptionalPricing($service, 'pricing')) {
                $errors[] = [
                    'path' => sprintf('services.%d.pricing', $index),
                    'message' => 'Expected an object with string price fields.',
                ];
            }
        }

        return $errors;
    }

    private function isAssocArray(mixed $value): bool
    {
        return is_array($value) && !array_is_list($value);
    }

    private function hasOptionalString(array $payload, string $field): bool
    {
        return !array_key_exists($field, $payload) || is_string($payload[$field]);
    }

    private function hasOptionalStringList(array $payload, string $field): bool
    {
        if (!array_key_exists($field, $payload)) {
            return true;
        }

        if (!is_array($payload[$field])) {
            return false;
        }

        foreach ($payload[$field] as $item) {
            if (!is_string($item)) {
                return false;
            }
        }

        return true;
    }

    private function hasOptionalFaqList(array $payload, string $field): bool
    {
        if (!array_key_exists($field, $payload)) {
            return true;
        }

        if (!is_array($payload[$field])) {
            return false;
        }

        foreach ($payload[$field] as $item) {
            if (!$this->isAssocArray($item)) {
                return false;
            }

            if (
                (!array_key_exists('question', $item) || !is_string($item['question']))
                || (!array_key_exists('answer', $item) || !is_string($item['answer']))
            ) {
                return false;
            }
        }

        return true;
    }

    private function hasOptionalPricing(array $payload, string $field): bool
    {
        if (!array_key_exists($field, $payload)) {
            return true;
        }

        if (!$this->isAssocArray($payload[$field])) {
            return false;
        }

        foreach (['essential', 'standard'] as $priceField) {
            if (array_key_exists($priceField, $payload[$field]) && !is_string($payload[$field][$priceField])) {
                return false;
            }
        }

        return true;
    }
}
