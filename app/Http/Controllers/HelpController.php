<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RendersPageMeta;
use App\Support\HelpArticles;
use App\Support\PublicSeo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HelpController extends Controller
{
    use RendersPageMeta;

    public function index(Request $request): Response
    {
        $allArticles = HelpArticles::index();
        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($allArticles as $article) {
            $name = (string) $article['category'];
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        $categories = [];
        foreach ($counts as $name => $count) {
            $categories[] = ['name' => $name, 'count' => $count];
        }
        $category = is_string($request->query('category')) ? $request->query('category') : null;
        if (! in_array($category, array_column($categories, 'name'), true)) {
            $category = null;
        }
        $filtered = array_values(array_filter($allArticles, fn (array $article) => $category === null || $article['category'] === $category));
        $lastPage = max(1, (int) ceil(count($filtered) / 9));
        $page = max(1, min($lastPage, $request->integer('page', 1)));
        $articles = array_slice($filtered, ($page - 1) * 9, 9);
        $request->query->remove('page');
        $request->query->remove('category');
        if ($page > 1) {
            $request->query->set('page', (string) $page);
        }
        if ($category !== null) {
            $request->query->set('category', $category);
        }
        $pageUrl = fn (int $number): string => '/blog'.(($query = http_build_query(array_filter([
            'category' => $category,
            'page' => $number > 1 ? $number : null,
        ]))) ? '?'.$query : '');
        $pagination = [
            'current_page' => $page,
            'last_page' => $lastPage,
            'total' => count($filtered),
            'previous' => $page > 1 ? $pageUrl($page - 1) : null,
            'next' => $page < $lastPage ? $pageUrl($page + 1) : null,
            'pages' => array_map(fn (int $number) => ['number' => $number, 'url' => $pageUrl($number)], range(1, $lastPage)),
        ];

        return Inertia::render('Blog/Index', [
            'articles' => $articles,
            'categories' => $categories,
            'articleCount' => count($allArticles),
            'activeCategory' => $category,
            'pagination' => $pagination,
            'publicContent' => ['type' => 'blog-index'],
            'meta' => $this->meta(
                ($page > 1 ? 'Google Ads Guides — Page '.$page : 'Google Ads Guides — Plain English').' | sitetospend',
                'Practical guides to Google Ads: conversion tracking, budget pacing, ad rank, negative keywords and match types. Written without the jargon.',
                'Google Ads Guides, Without the Jargon | sitetospend',
                'Conversion tracking, budget pacing, ad rank, negative keywords and match types — explained plainly.',
                // The index listed twenty articles and declared none of them.
                // A Blog node with its posts is what lets a search or answer
                // engine see this as a body of work rather than one thin page.
                [[
                    '@type' => 'Blog',
                    'name' => 'sitetospend guides',
                    'url' => PublicSeo::sharedUrl($pageUrl($page)),
                    'description' => 'Plain-English guides to Google Ads, Meta Ads and AI campaign management.',
                    'blogPost' => array_map(fn (array $a) => [
                        '@type' => 'BlogPosting',
                        'headline' => $a['title'],
                        'description' => $a['description'],
                        'url' => PublicSeo::sharedUrl('/blog/'.$a['slug']),
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

        $canonical = PublicSeo::sharedUrl('/blog/'.$slug);

        return Inertia::render('Blog/Article', [
            'article' => $article,
            'publicContent' => ['type' => 'blog-article'],
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
                        'dateModified' => $article['modified'] ?? $article['published'],
                        'articleSection' => $article['category'],
                        'author' => ['@type' => 'Organization', 'name' => 'sitetospend', 'url' => PublicSeo::sharedUrl('/')],
                        'publisher' => ['@type' => 'Organization', 'name' => 'sitetospend', 'url' => PublicSeo::sharedUrl('/')],
                    ],
                    [
                        '@type' => 'BreadcrumbList',
                        'itemListElement' => [
                            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => PublicSeo::sharedUrl('/')],
                            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => PublicSeo::sharedUrl('/blog')],
                            ['@type' => 'ListItem', 'position' => 3, 'name' => $article['title'], 'item' => $canonical],
                        ],
                    ],
                ],
                'article',
            ),
            'relatedArticles' => HelpArticles::related($slug),
        ]);
    }
}
