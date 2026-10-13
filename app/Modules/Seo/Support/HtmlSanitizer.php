<?php

namespace App\Modules\Seo\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allowlist HTML sanitizer for admin-authored SEO content.
 *
 * Parses with DOMDocument and RE-SERIALIZES from scratch: only allowlisted
 * tags are emitted, all text is escaped, and the only attribute that survives
 * is a vetted href on <a>. Dangerous containers (script, style, iframe, svg…)
 * are dropped with their contents; any other unknown tag is unwrapped (its
 * children are kept). <h1> is demoted to <h2> because the page owns the H1.
 */
final class HtmlSanitizer
{
    /** @var array<string, true> */
    private const ALLOWED = [
        'h2' => true, 'h3' => true, 'h4' => true,
        'p' => true, 'br' => true, 'hr' => true,
        'ul' => true, 'ol' => true, 'li' => true,
        'strong' => true, 'b' => true, 'em' => true, 'i' => true, 'u' => true,
        'blockquote' => true, 'a' => true,
        'table' => true, 'thead' => true, 'tbody' => true, 'tr' => true, 'th' => true, 'td' => true,
    ];

    /** @var array<string, true> */
    private const VOID = ['br' => true, 'hr' => true];

    /** @var array<string, string> */
    private const RENAME = ['h1' => 'h2'];

    /** Dropped together with everything inside them. */
    private const DROP = [
        'script' => true, 'style' => true, 'iframe' => true, 'frame' => true, 'frameset' => true,
        'object' => true, 'embed' => true, 'applet' => true, 'svg' => true, 'math' => true,
        'template' => true, 'noscript' => true, 'form' => true, 'input' => true, 'button' => true,
        'select' => true, 'textarea' => true, 'option' => true, 'link' => true, 'meta' => true,
        'base' => true, 'head' => true, 'title' => true, 'img' => true, 'video' => true,
        'audio' => true, 'source' => true, 'picture' => true, 'canvas' => true,
    ];

    public static function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $html = trim($html);
        if ($html === '') {
            return null;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // Explicit UTF-8 hint + wrapper so fragments parse predictably.
        $doc->loadHTML(
            '<?xml encoding="UTF-8"?><!DOCTYPE html><html><body>'.$html.'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementsByTagName('body')->item(0);
        if (! $root instanceof DOMElement) {
            return null;
        }

        $out = self::children($root);
        $out = trim($out);

        return $out === '' ? null : $out;
    }

    private static function children(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::node($child);
        }

        return $out;
    }

    private static function node(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return self::e((string) $node->nodeValue);
        }

        if (! $node instanceof DOMElement) {
            // comments, processing instructions, etc.
            return '';
        }

        $tag = strtolower($node->tagName);

        if (isset(self::DROP[$tag])) {
            return '';
        }

        $tag = self::RENAME[$tag] ?? $tag;

        if (! isset(self::ALLOWED[$tag])) {
            return self::children($node);
        }

        if (isset(self::VOID[$tag])) {
            return '<'.$tag.'>';
        }

        $attrs = '';
        if ($tag === 'a') {
            $href = self::safeHref($node->getAttribute('href'));
            if ($href === null) {
                // Unsafe/missing link → keep text only.
                return self::children($node);
            }
            $attrs = ' href="'.self::e($href).'"';
            if (preg_match('#^https?://#i', $href) === 1) {
                $attrs .= ' rel="noopener noreferrer"';
            }
        }

        return '<'.$tag.$attrs.'>'.self::children($node).'</'.$tag.'>';
    }

    /**
     * http(s), mailto, tel, site-relative ("/path") and fragment links only.
     */
    public static function safeHref(string $href): ?string
    {
        // Browsers ignore control chars/whitespace inside schemes ("java\nscript:"),
        // so strip them all before deciding.
        $href = preg_replace('/[\x00-\x20\x7F]+/', '', $href) ?? '';
        if ($href === '' || strlen($href) > 2048) {
            return null;
        }

        if (str_starts_with($href, '//') || str_starts_with($href, '/\\')) {
            return null; // protocol-relative
        }

        if (str_starts_with($href, '/') || str_starts_with($href, '#')) {
            return $href;
        }

        if (preg_match('#^(https?://|mailto:|tel:)#i', $href) === 1) {
            return $href;
        }

        return null;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
