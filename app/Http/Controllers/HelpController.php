<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersPageMeta;
use App\Support\HelpArticles;
use Inertia\Inertia;
use Inertia\Response;

class HelpController extends Controller
{
    use RendersPageMeta;

    public function index(): Response
    {
        $articles = HelpArticles::index();

        return Inertia::render('Blog/Index', [
            'articles' => $articles,
            'meta' => $this->meta(
                'Google Ads Guides — Plain English | sitetospend',
                'Practical guides to Google Ads: conversion tracking, budget pacing, ad rank, negative keywords and match types. Written without the jargon.',
                'Google Ads Guides, Without the Jargon | sitetospend',
                'Conversion tracking, budget pacing, ad rank, negative keywords and match types — explained plainly.',
                // The index listed twenty articles and declared none of them.
                // A Blog node with its posts is what lets a search or answer
                // engine see this as a body of work rather than one thin page.
                [[
                    '@type' => 'Blog',
                    'name' => 'sitetospend guides',
                    'url' => url('/blog'),
                    'description' => 'Plain-English guides to Google Ads, Meta Ads and AI campaign management.',
                    'blogPost' => array_map(fn (array $a) => [
                        '@type' => 'BlogPosting',
                        'headline' => $a['title'],
                        'description' => $a['description'],
                        'url' => url('/blog/'.$a['slug']),
                        'datePublished' => $a['published'],
                        'articleSection' => $a['category'],
                    ], $articles),
                ]],
            ),
        ]);
    }

    public function show(string $slug): Response
    {
        $article = HelpArticles::find($slug);

        abort_if(! $article, 404);

        // url() follows the requesting host, so a vertical skin canonicalises to
        // its own domain. The JSX built this string from a literal
        // 'https://sitetospend.com', which pointed every realpropertyads.com
        // article at a page on someone else's site.
        $canonical = str_replace('http://', 'https://', url()->current());

        return Inertia::render('Blog/Article', [
            'article' => $article,
            // Each article already carries its own description; it simply never
            // reached the server HTML, so every article shared the site-wide one.
            'meta' => $this->meta(
                $article['title'].' | sitetospend',
                $article['description'],
                $article['title'],
                $article['description'],
                [
                    [
                        '@type' => 'Article',
                        'headline' => $article['title'],
                        'description' => $article['description'],
                        'url' => $canonical,
                        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
                        'datePublished' => $article['published'],
                        'dateModified' => $article['published'],
                        'articleSection' => $article['category'],
                        'author' => ['@type' => 'Organization', 'name' => 'sitetospend', 'url' => url('/')],
                        'publisher' => ['@type' => 'Organization', 'name' => 'sitetospend', 'url' => url('/')],
                    ],
                    [
                        '@type' => 'BreadcrumbList',
                        'itemListElement' => [
                            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
                            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => url('/blog')],
                            ['@type' => 'ListItem', 'position' => 3, 'name' => $article['title'], 'item' => $canonical],
                        ],
                    ],
                ],
                'article',
            ),
            'relatedArticles' => collect(HelpArticles::index())
                ->where('slug', '!=', $slug)
                ->take(3)
                ->values()
                ->all(),
        ]);
    }
}
