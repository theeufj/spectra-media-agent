<?php

namespace App\Services\EmailSequences;

/**
 * Reduce admin-authored HTML to a subset that is safe to render and that email
 * clients actually support.
 *
 * Two separate jobs, and it is worth being explicit about both because only one
 * of them is obvious:
 *
 * 1. Safety. This HTML is written in the admin portal and then rendered
 *    unescaped in two places — the preview iframe and the email itself. An
 *    allowlist is the only defensible shape: a blocklist of `<script>` and
 *    `onclick` misses `javascript:` in an href, `data:text/html` in an image,
 *    `<style>` importing a remote sheet, and whatever the next bypass is.
 *    Everything not named here is removed.
 *
 * 2. Deliverability. Email clients are not browsers. Outlook renders through
 *    Word and drops `<section>`, flexbox, and most positioning outright; a
 *    `<script>` tag or a remote stylesheet is a spam signal well before it is a
 *    rendering problem. Restricting the output to the tags that survive
 *    everywhere is what stops "it looked fine in the preview" from being the
 *    last thing anyone says about it.
 *
 * Unknown tags are unwrapped rather than deleted — a stray `<section>` loses
 * the wrapper but keeps the sentence inside it. Tags in DROP_ENTIRELY are
 * deleted with their contents, because the contents are the problem.
 */
class EmailHtmlSanitizer
{
    /**
     * Tags that survive Outlook, Gmail and Apple Mail.
     *
     * @var array<string, list<string>> tag => allowed attributes
     */
    private const ALLOWED = [
        'p' => ['style'],
        'br' => [],
        'hr' => ['style'],
        'strong' => ['style'], 'b' => ['style'],
        'em' => ['style'], 'i' => ['style'],
        'u' => ['style'], 's' => ['style'], 'strike' => ['style'],
        'a' => ['href', 'style', 'target', 'rel', 'title'],
        'ul' => ['style'], 'ol' => ['style'], 'li' => ['style'],
        'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'], 'h4' => ['style'],
        'blockquote' => ['style'],
        'span' => ['style'],
        'div' => ['style', 'align'],
        'img' => ['src', 'alt', 'width', 'height', 'style'],
        // Tables are how an email lays anything out. Buttons in particular are
        // a single-cell table in every client that renders them reliably.
        'table' => ['style', 'width', 'border', 'cellpadding', 'cellspacing', 'align', 'role'],
        'thead' => ['style'], 'tbody' => ['style'], 'tfoot' => ['style'],
        'tr' => ['style'],
        'td' => ['style', 'width', 'align', 'valign', 'colspan', 'rowspan', 'bgcolor'],
        'th' => ['style', 'width', 'align', 'valign', 'colspan', 'rowspan', 'bgcolor'],
    ];

    /**
     * Removed with everything inside them.
     *
     * `<style>` is here alongside the obvious ones because its *content* is the
     * payload — unwrapping it would paste raw CSS into the email as text.
     *
     * @var list<string>
     */
    private const DROP_ENTIRELY = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'button', 'select', 'textarea', 'link', 'meta', 'base', 'svg', 'math',
        'audio', 'video', 'source', 'template', 'noscript',
    ];

    /**
     * CSS properties that mean something in an email client.
     *
     * Positioning is deliberately absent: `position`, `float` and flexbox are
     * either ignored or actively broken in Outlook, so allowing them produces
     * an email that looks right in the preview and wrong in the inbox.
     *
     * @var list<string>
     */
    private const ALLOWED_STYLES = [
        'color', 'background-color', 'background',
        'font-size', 'font-family', 'font-weight', 'font-style', 'font-variant',
        'text-align', 'text-decoration', 'text-transform',
        'line-height', 'letter-spacing',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-radius', 'border-collapse', 'border-color', 'border-width', 'border-style',
        'width', 'max-width', 'min-width', 'height', 'max-height',
        'display', 'vertical-align', 'white-space',
    ];

    /** @var list<string> */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function sanitize(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');

        // The meta tag is what makes libxml treat the input as UTF-8; without
        // it a pound sign or an em dash comes back mojibaked. NOIMPLIED and
        // NODEFDTD keep it from wrapping everything in <html><body>.
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
            .'<div id="sanitizer-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            // Unparseable input is returned as escaped text rather than
            // dropped. Losing an admin's draft silently is worse than showing
            // it with its tags visible, which at least explains itself.
            return e($html);
        }

        $root = $doc->getElementById('sanitizer-root');

        if (! $root instanceof \DOMElement) {
            return e($html);
        }

        $this->clean($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        // Once more on the serialised output: saveHTML re-encodes the braces
        // in an attribute even when setAttribute was given them decoded, so
        // undoing it inside cleanUrl alone is not enough.
        return trim($this->restorePlaceholders($out));
    }

    /**
     * Depth-first over a snapshot of the children.
     *
     * The snapshot matters: unwrapping or removing a node mutates the live
     * DOMNodeList underneath the loop, and iterating that directly skips
     * siblings — which is exactly how a sanitiser ends up letting one through.
     */
    private function clean(\DOMElement $element): void
    {
        $children = iterator_to_array($element->childNodes);

        foreach ($children as $child) {
            if ($child instanceof \DOMText) {
                continue;
            }

            if ($child instanceof \DOMComment) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if (! $child instanceof \DOMElement) {
                // Processing instructions, CDATA, doctypes — nothing an email
                // body has a legitimate use for.
                $child->parentNode?->removeChild($child);

                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP_ENTIRELY, true)) {
                $child->parentNode?->removeChild($child);

                continue;
            }

            if (! array_key_exists($tag, self::ALLOWED)) {
                $this->clean($child);
                $this->unwrap($child);

                continue;
            }

            $this->cleanAttributes($child, $tag);
            $this->clean($child);
        }
    }

    private function cleanAttributes(\DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);

                continue;
            }

            $value = $attribute->nodeValue ?? '';

            if ($name === 'href' || $name === 'src') {
                $clean = $this->cleanUrl($value);

                if ($clean === null) {
                    $element->removeAttribute($attribute->nodeName);
                } else {
                    $element->setAttribute($name, $clean);
                }

                continue;
            }

            if ($name === 'style') {
                $clean = $this->cleanStyle($value);

                if ($clean === '') {
                    $element->removeAttribute($attribute->nodeName);
                } else {
                    $element->setAttribute('style', $clean);
                }
            }
        }

        // A link that opens in place inside a webmail client traps the reader
        // in the frame; noopener is the standard companion.
        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * Allow only schemes that cannot execute.
     *
     * Relative URLs are rejected outright rather than resolved: in an inbox
     * there is no base document to resolve them against, so a relative src is
     * a broken image however it is treated.
     */
    private function cleanUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        // Strip control characters first: "java\tscript:" and "java\0script:"
        // are both read as javascript: by some clients but not by parse_url.
        $url = preg_replace('/[\x00-\x20]/', '', $url) ?? '';

        $url = $this->restorePlaceholders($url);

        // A whole href may be nothing but a placeholder, which has no scheme
        // yet — it gets one when the value is substituted at send time.
        if (preg_match('/^\{\{\s*[a-z_]+\s*\}\}$/i', $url)) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === '') {
            return null;
        }

        return in_array($scheme, self::ALLOWED_SCHEMES, true) ? $url : null;
    }

    /**
     * Undo libxml's percent-encoding of `{{ placeholder }}` braces.
     *
     * Both parsing and serialising an href rewrite `{{ website }}` to
     * `%7B%7B%20website%20%7D%7D`, which no longer matches the substitution
     * pattern — so the link would reach the recipient with the encoded
     * placeholder still in it, pointing nowhere.
     *
     * Only brace-delimited runs are decoded. Decoding wholesale would be a way
     * to smuggle `java%09script:` back past the scheme check.
     */
    private function restorePlaceholders(string $url): string
    {
        return preg_replace_callback(
            '/%7B%7B(.*?)%7D%7D/i',
            fn (array $m) => '{{'.rawurldecode($m[1]).'}}',
            $url
        ) ?? $url;
    }

    /**
     * Keep the declarations that are on the allowlist and drop the rest.
     *
     * `url()` is refused in every property: it is how a background image
     * becomes a tracking pixel pointing anywhere, and email clients block
     * remote CSS backgrounds regardless.
     */
    private function cleanStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = explode(':', $declaration, 2);

            $property = strtolower(trim($property));
            $value = trim($value);

            if ($property === '' || $value === '') {
                continue;
            }

            if (! in_array($property, self::ALLOWED_STYLES, true)) {
                continue;
            }

            $lower = strtolower($value);

            if (str_contains($lower, 'url(')
                || str_contains($lower, 'expression')
                || str_contains($lower, 'javascript:')
                || str_contains($lower, '@import')
                || str_contains($lower, '\\')) {
                continue;
            }

            $kept[] = $property.': '.$value;
        }

        return implode('; ', $kept);
    }

    /** Replace an element with its children, keeping the text inside it. */
    private function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;

        if (! $parent instanceof \DOMNode) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
