<?php

namespace App\Http\Controllers;

use App\Support\HelpArticles;
use Inertia\Inertia;
use Inertia\Response;

class HelpController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Blog/Index', [
            'articles' => HelpArticles::index(),
            'meta' => [
                'title' => 'Google Ads Guides — Plain English | sitetospend',
                'description' => 'Practical guides to Google Ads: conversion tracking, budget pacing, ad rank, negative keywords and match types. Written without the jargon.',
                'canonical' => str_replace('http://', 'https://', url()->current()),
            ],
        ]);
    }

    public function show(string $slug): Response
    {
        $article = HelpArticles::find($slug);

        abort_if(! $article, 404);

        return Inertia::render('Blog/Article', [
            'article' => $article,
            // Each article already carries its own description; it simply never
            // reached the server HTML, so every article shared the site-wide one.
            'meta' => [
                'title' => $article['title'].' | sitetospend',
                'description' => $article['description'],
                'canonical' => str_replace('http://', 'https://', url()->current()),
                'type' => 'article',
            ],
            'relatedArticles' => collect(HelpArticles::index())
                ->where('slug', '!=', $slug)
                ->take(3)
                ->values()
                ->all(),
        ]);
    }
}
