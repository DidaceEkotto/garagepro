<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Nettoie strictement le HTML riche (pages légales, éditeur WYSIWYG) contre les attaques XSS.
 * Autorise uniquement une liste blanche de balises de mise en forme et d'attributs sûrs.
 */
class HtmlSanitizer
{
    /** @var array<int, string> */
    private const ALLOWED_TAGS = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'hr',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
        'a', 'span', 'div',
    ];

    /** @var array<string, array<int, string>> */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title', 'target', 'rel'],
        'table' => ['class'],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);

        // Encapsule dans un conteneur UTF-8 pour éviter les balises html/body parasites
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div>'.$html.'</div></body></html>';
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $body = $dom->getElementsByTagName('body')->item(0);
        if (! $body || ! $body->firstChild) {
            return e(strip_tags($html));
        }

        $container = $body->firstChild;
        self::sanitizeNode($container);

        $result = '';
        foreach ($container->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return trim($result);
    }

    private static function sanitizeNode(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);

                // Supprime entièrement les balises dangereuses et leur contenu
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'textarea', 'meta', 'link', 'base'], true)) {
                    $node->removeChild($child);
                    continue;
                }

                // Pour les autres balises non autorisées, conserve uniquement le texte enfant nettoyé
                if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                    self::sanitizeNode($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }

                // Nettoie tous les attributs
                $allowedAttrs = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
                $attrsToRemove = [];
                foreach ($child->attributes as $attr) {
                    $attrName = strtolower($attr->name);
                    if (str_starts_with($attrName, 'on') || ! in_array($attrName, $allowedAttrs, true)) {
                        $attrsToRemove[] = $attr->name;
                    }
                }
                foreach ($attrsToRemove as $name) {
                    $child->removeAttribute($name);
                }

                if ($tag === 'a') {
                    $href = trim((string) $child->getAttribute('href'));
                    if ($href !== '' && ! preg_match('#^(https?://|mailto:|/)#i', $href)) {
                        $child->removeAttribute('href');
                    }
                    if ($child->getAttribute('target') === '_blank') {
                        $child->setAttribute('rel', 'noopener noreferrer');
                    } else {
                        $child->removeAttribute('target');
                    }
                }

                self::sanitizeNode($child);
            } elseif ($child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            }
        }
    }
}
