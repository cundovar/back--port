<?php

declare(strict_types=1);

namespace App\Service;

final class RichTextSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'a', 'blockquote', 'code', 'pre'
    ];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'title'],
    ];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;

        set_error_handler(fn() => null);
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOEMPTY | LIBXML_NOWARNING | LIBXML_NOERROR
        );
        restore_error_handler();

        if (!$loaded) {
            return '';
        }

        $this->removeDisallowedElements($dom->documentElement);
        $this->removeDisallowedAttributes($dom->documentElement);

        return $this->extractBody($dom);
    }

    private function removeDisallowedElements(\DOMNode $node): void
    {
        $nodesToRemove = [];

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($child->nodeName);

                if (!in_array($tagName, self::ALLOWED_TAGS, true)) {
                    $nodesToRemove[] = $child;
                } else {
                    $this->removeDisallowedElements($child);
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $this->removeDisallowedElements($child);
            }
        }

        foreach ($nodesToRemove as $nodeToRemove) {
            $nodeToRemove->parentNode?->removeChild($nodeToRemove);
        }
    }

    private function removeDisallowedAttributes(\DOMNode $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tagName = strtolower($child->nodeName);
                $allowedAttrs = self::ALLOWED_ATTRIBUTES[$tagName] ?? [];

                $attrsToRemove = [];
                foreach ($child->attributes ?? [] as $attr) {
                    if (!in_array($attr->nodeName, $allowedAttrs, true)) {
                        $attrsToRemove[] = $attr->nodeName;
                    }
                }

                foreach ($attrsToRemove as $attrName) {
                    $child->removeAttribute($attrName);
                }

                $this->removeDisallowedAttributes($child);
            }
        }
    }

    private function extractBody(\DOMDocument $dom): string
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }

        $html = '';
        foreach ($body->childNodes as $child) {
            $html .= $dom->saveHTML($child);
        }

        return trim($html);
    }
}
