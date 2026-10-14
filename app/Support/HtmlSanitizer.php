<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans rich-text HTML before it is stored or shown: only a short list of formatting tags survives,
 * every attribute is dropped (except a safe href on links) and scripts, styles and event handlers are removed.
 */
class HtmlSanitizer
{
    /** Tags that are kept (the output of the editor). */
    private const ALLOWED = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'a', 'h1', 'h2', 'h3', 'hr'];

    /** Tags removed together with everything inside them. */
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'form', 'textarea', 'select', 'button', 'title', 'head'];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?><div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('root');
        if (! $root) {
            return null;
        }

        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        $out = trim($out);

        // An editor that only holds an empty paragraph counts as empty.
        return trim(strip_tags($out)) === '' && ! str_contains($out, '<hr') ? null : $out;
    }

    /** Plain text of some HTML, for previews and search. */
    public static function text(?string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ' ', (string) $html)))));
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                continue; // text nodes are kept as they are; comments are dropped below
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }

            self::walk($child);

            if (! in_array($tag, self::ALLOWED, true)) {
                // Unknown tag: keep its (already cleaned) content, lose the tag.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            $href = $tag === 'a' ? $child->getAttribute('href') : null;
            foreach (iterator_to_array($child->attributes) as $attribute) {
                $child->removeAttribute($attribute->name);
            }

            if ($tag === 'a') {
                if ($href !== null && preg_match('#^(https?://|mailto:)#i', trim($href))) {
                    $child->setAttribute('href', trim($href));
                    $child->setAttribute('rel', 'noopener noreferrer nofollow');
                    $child->setAttribute('target', '_blank');
                } else {
                    // A link with an unsafe address becomes plain text.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                }
            }
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE) {
                $node->removeChild($child);
            }
        }
    }
}
