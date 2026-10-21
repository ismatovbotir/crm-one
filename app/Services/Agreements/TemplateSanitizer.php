<?php

namespace App\Services\Agreements;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allowlist HTML sanitizer for agreement template bodies (Spec 06 section 13 "Templates"): only
 * simple text formatting and tables survive; script/style/iframe/object/embed/form and their
 * content are dropped, other unknown elements are unwrapped (their text kept), every attribute
 * except colspan/rowspan is removed (no on* handlers, no style, no links). Placeholders are plain
 * text and pass through unchanged.
 */
class TemplateSanitizer
{
    /** @var list<string> */
    public const ALLOWED_TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'div', 'span', 'hr', 'blockquote', 'sup', 'sub'];

    /** @var list<string> */
    public const DROPPED_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'link', 'meta', 'base', 'noscript', 'template', 'frame', 'frameset', 'applet'];

    /** @var list<string> */
    public const ALLOWED_ATTRIBUTES = ['colspan', 'rowspan'];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="__root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('__root');

        if (! $root instanceof DOMElement) {
            return '';
        }

        $this->clean($root);

        $output = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $output .= $document->saveHTML($child);
        }

        return $output;
    }

    protected function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROPPED_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }

            $this->clean($child);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }

                $node->removeChild($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $name = strtolower($attribute->name);

                if (! in_array($name, self::ALLOWED_ATTRIBUTES, true) || ! ctype_digit(trim($attribute->value))) {
                    $child->removeAttribute($attribute->name);
                }
            }
        }
    }
}
