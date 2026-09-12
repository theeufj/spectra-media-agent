<?php

namespace App\Http\Controllers\Concerns;

/**
 * Server-rendered metadata for a public page.
 *
 * Every public page used to write its own `<title>`, `<meta name="description">`,
 * Open Graph pair and JSON-LD inside a React `<Head>`, on top of the ones
 * app.blade.php already renders. Inertia only replaces the tags it owns, so the
 * result was not an override but a duplicate — and a duplicate saying something
 * different:
 *
 *   /features   two descriptions, 136 chars server and 191 client
 *   /about      two og:titles, different copy in each
 *   /blog/*     two rel=canonical, one pointing at sitetospend.com and one at
 *               the requesting host — Google discards both when there is more
 *               than one, so every article was left with no canonical at all
 *
 * and the structured data, being built by React, existed only after hydration.
 * That is invisible to GPTBot, ClaudeBot, PerplexityBot, Google-Extended and
 * Applebot-Extended, every one of which public/robots.txt names and admits.
 *
 * So metadata is a controller's job now and a page component's never. The rule
 * is enforced by MarketingStructuredDataTest, which reads the raw response
 * string rather than the Inertia props — asserting on props would pass just as
 * happily with the tags back in the JSX.
 */
trait RendersPageMeta
{
    /**
     * @param  string  $title  Under ~60 characters, or Google truncates it.
     * @param  string  $description  Under ~155 characters, for the same reason.
     * @param  string|null  $ogTitle  Social previews want punchier copy than a
     *                                search result does. Defaults to $title.
     * @param  array<int, array<string, mixed>>|null  $schema  schema.org @graph nodes.
     * @return array<string, mixed>
     */
    protected function meta(
        string $title,
        string $description,
        ?string $ogTitle = null,
        ?string $ogDescription = null,
        ?array $schema = null,
        ?string $type = null,
    ): array {
        return array_filter([
            'title' => $title,
            'description' => $description,
            'canonical' => str_replace('http://', 'https://', url()->current()),
            'og_title' => $ogTitle,
            'og_description' => $ogDescription,
            'type' => $type,
            'schema' => $schema,
        ]);
    }

    /**
     * A FAQPage node built from a `config/faqs.php` list.
     *
     * @param  array<int, array{question: string, answer: string}>  $faqs
     * @return array<string, mixed>
     */
    protected function faqSchema(array $faqs): array
    {
        return [
            '@type' => 'FAQPage',
            'mainEntity' => array_map(fn (array $faq) => [
                '@type' => 'Question',
                'name' => $faq['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $faq['answer'],
                ],
            ], $faqs),
        ];
    }
}
