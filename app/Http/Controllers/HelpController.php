<?php

namespace App\Http\Controllers;

use App\Support\HelpArticles;
use Inertia\Inertia;
use Inertia\Response;

class HelpController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Help/Index', [
            'articles' => HelpArticles::index(),
        ]);
    }

    public function show(string $slug): Response
    {
        $article = HelpArticles::find($slug);

        abort_if(!$article, 404);

        return Inertia::render('Help/Article', [
            'article'       => $article,
            'relatedArticles' => collect(HelpArticles::index())
                ->where('slug', '!=', $slug)
                ->take(3)
                ->values()
                ->all(),
        ]);
    }
}
