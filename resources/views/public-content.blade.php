@php
    $publicProps = data_get($page, 'props', []);
    $publicType = data_get($publicProps, 'publicContent.type');
    $publicTenant = data_get($publicProps, 'tenant', []);
    $publicBrand = data_get($publicTenant, 'logo_text', 'sitetospend');
@endphp
<div class="min-h-screen bg-gray-50 text-gray-900">
    <header class="border-b border-gray-200 bg-white">
        <nav class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-5" aria-label="Main navigation">
            <a href="/" class="text-2xl font-bold text-gray-900">{{ $publicBrand }}</a>
            <div class="flex flex-wrap gap-5 text-sm font-semibold">
                <a href="/google-ads-management">Google Ads</a>
                <a href="/features">Features</a>
                <a href="/pricing">Pricing</a>
                <a href="/blog">Blog</a>
                <a href="/register">Get started</a>
            </div>
        </nav>
    </header>
    <main class="mx-auto max-w-5xl px-6 py-10 sm:py-16">
        @if($publicType === 'blog-index')
            <h1 class="text-3xl font-extrabold sm:text-4xl">Google Ads &amp; digital advertising guides</h1>
            <p class="mt-5 max-w-3xl text-lg leading-relaxed text-gray-600">Plain-English guides to Google Ads, Smart Bidding, AI campaign management and everything in between.</p>
            <nav aria-label="Article categories" class="my-8 flex flex-wrap gap-3">
                <a href="/blog" class="rounded-full border border-gray-200 bg-white px-4 py-3" @if(!data_get($publicProps, 'activeCategory')) aria-current="page" @endif>All ({{ data_get($publicProps, 'articleCount', 0) }})</a>
                @foreach(data_get($publicProps, 'categories', []) as $category)
                    <a href="/blog?{{ http_build_query(['category' => $category['name']]) }}" class="rounded-full border border-gray-200 bg-white px-4 py-3" @if(data_get($publicProps, 'activeCategory') === $category['name']) aria-current="page" @endif>{{ $category['name'] }} ({{ $category['count'] }})</a>
                @endforeach
            </nav>
            <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach(data_get($publicProps, 'articles', []) as $article)
                    <article class="rounded-xl border border-gray-200 bg-white p-6">
                        <p class="mb-3 text-sm text-gray-600">{{ $article['category'] }} · {{ $article['read_time'] }}</p>
                        <h2 class="text-xl font-bold"><a href="/blog/{{ $article['slug'] }}">{{ $article['title'] }}</a></h2>
                        <p class="mt-3 leading-relaxed text-gray-600">{{ $article['description'] }}</p>
                    </article>
                @endforeach
            </div>
            @if(data_get($publicProps, 'pagination.last_page', 1) > 1)
                <nav aria-label="Article pages" class="mt-10 flex flex-wrap justify-center gap-3">
                    @if(data_get($publicProps, 'pagination.previous'))
                        <a href="{{ data_get($publicProps, 'pagination.previous') }}" rel="prev" class="rounded-lg border bg-white px-4 py-3">← Previous</a>
                    @endif
                    @foreach(data_get($publicProps, 'pagination.pages', []) as $link)
                        <a href="{{ $link['url'] }}" class="rounded-lg border bg-white px-4 py-3" @if($link['number'] === data_get($publicProps, 'pagination.current_page')) aria-current="page" @endif>{{ $link['number'] }}</a>
                    @endforeach
                    @if(data_get($publicProps, 'pagination.next'))
                        <a href="{{ data_get($publicProps, 'pagination.next') }}" rel="next" class="rounded-lg border bg-white px-4 py-3">Next →</a>
                    @endif
                </nav>
            @endif
        @elseif($publicType === 'blog-article')
            @php($article = data_get($publicProps, 'article', []))
            <nav aria-label="Breadcrumb" class="mb-5 text-sm"><a href="/blog">Blog</a> / {{ $article['title'] }}</nav>
            <h1 class="text-3xl font-extrabold sm:text-4xl">{{ $article['title'] }}</h1>
            <p class="mt-5 text-lg leading-relaxed text-gray-600">{{ $article['description'] }}</p>
            <p class="mt-4 text-sm text-gray-600">{{ $article['read_time'] }} @if(isset($article['modified'])) · Updated <time datetime="{{ $article['modified'] }}">{{ \Carbon\Carbon::parse($article['modified'])->format('j M Y') }}</time> @endif</p>
            <article class="prose-article mt-8 rounded-2xl border border-gray-200 bg-white p-6 sm:p-10">{!! $article['content'] !!}</article>
            <aside class="mt-10" aria-label="Related articles">
                <h2 class="text-2xl font-bold">More articles</h2>
                <ul class="mt-4 space-y-3">
                    @foreach(data_get($publicProps, 'relatedArticles', []) as $related)
                        <li><a href="/blog/{{ $related['slug'] }}" class="font-semibold underline">{{ $related['title'] }}</a></li>
                    @endforeach
                </ul>
            </aside>
        @elseif($publicType === 'legal')
            <article class="prose-article rounded-2xl border border-gray-200 bg-white p-6 sm:p-10">{!! data_get($publicProps, 'legalContent') !!}</article>
        @elseif($publicType === 'marketing')
            @php($marketing = data_get($publicProps, 'publicContent', []))
            @if(!empty($marketing['eyebrow']))<p class="mb-4 text-sm font-semibold uppercase tracking-wider">{{ $marketing['eyebrow'] }}</p>@endif
            <h1 class="text-3xl font-extrabold sm:text-4xl">{{ $marketing['headline'] }}</h1>
            <p class="mt-5 text-lg leading-relaxed text-gray-600">{{ $marketing['intro'] }}</p>
            @foreach($marketing['sections'] ?? [] as $section)
                <section class="mt-8 rounded-2xl border border-gray-200 bg-white p-6 sm:p-8">
                    <h2 class="text-2xl font-bold">{{ $section['title'] }}</h2>
                    <p class="mt-4 leading-relaxed text-gray-600">{{ $section['body'] }}</p>
                </section>
            @endforeach
            @if(count(data_get($publicProps, 'plans', [])) > 0)
                <section class="mt-10" aria-labelledby="marketing-plans">
                    <h2 id="marketing-plans" class="text-2xl font-bold">Current management plans</h2>
                    <p class="mt-3 text-gray-600">Prices are in USD. Advertising spend is separate.</p>
                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        @foreach(data_get($publicProps, 'plans', []) as $plan)
                            <div class="rounded-xl border border-gray-200 bg-white p-6">
                                <h3 class="text-xl font-bold">{{ data_get($plan, 'name') }}</h3>
                                <p class="mt-3 text-xl font-semibold">@if(data_get($plan, 'price_cents') > 0) US${{ number_format(data_get($plan, 'price_cents') / 100, 0) }}/{{ data_get($plan, 'billing_interval') === 'year' ? 'year' : 'mo' }} @else Contact us @endif</p>
                                <p class="mt-3 text-gray-600">{{ data_get($plan, 'description') }}</p>
                                <ul class="mt-4 space-y-2">@foreach((data_get($plan, 'features') ?? []) as $feature)<li>{{ $feature }}</li>@endforeach</ul>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
            @if(data_get($publicProps, 'setupFeeUsd'))
                <section class="mt-8 rounded-xl border border-gray-200 bg-white p-6"><h2 class="text-2xl font-bold">One-time Google Ads setup</h2><p class="mt-3">US${{ data_get($publicProps, 'setupFeeUsd') }} once. A paused campaign build and handover; advertising spend and ongoing management are separate.</p><a href="/google-ads-setup" class="mt-4 inline-block font-semibold underline">See the one-time setup</a></section>
            @endif
            @if(count(data_get($publicProps, 'faqs', [])) > 0)
                <section class="mt-10" aria-labelledby="marketing-questions"><h2 id="marketing-questions" class="text-2xl font-bold">Questions before you start</h2><dl class="mt-6 space-y-6">@foreach(data_get($publicProps, 'faqs', []) as $faq)<div><dt class="font-semibold">{{ $faq['question'] }}</dt><dd class="mt-2 leading-relaxed text-gray-600">{{ $faq['answer'] }}</dd></div>@endforeach</dl></section>
            @endif
            <nav aria-label="Related resources" class="mt-10 flex flex-wrap gap-5 font-semibold">@foreach($marketing['links'] ?? [] as $link)<a href="{{ $link['href'] }}" class="underline">{{ $link['label'] }}</a>@endforeach</nav>
        @elseif($publicType === 'service')
            @php($service = data_get($publicProps, 'service', []))
            <p class="mb-4 text-sm font-semibold uppercase tracking-wider">{{ $service['eyebrow'] }}</p>
            <h1 class="text-3xl font-extrabold sm:text-4xl">{{ $service['headline'] }}</h1>
            <p class="mt-5 text-lg leading-relaxed text-gray-600">{{ $service['intro'] }}</p>
            <article class="prose-article mt-8 rounded-2xl border border-gray-200 bg-white p-6 sm:p-10">{!! $service['content'] !!}</article>
            <section class="mt-10" aria-labelledby="service-questions">
                <h2 id="service-questions" class="text-2xl font-bold">Questions before you start</h2>
                <dl class="mt-6 space-y-6">
                    @foreach($service['faqs'] as $faq)
                        <div class="rounded-xl border border-gray-200 bg-white p-6"><dt class="font-semibold">{{ $faq['question'] }}</dt><dd class="mt-2 leading-relaxed text-gray-600">{{ $faq['answer'] }}</dd></div>
                    @endforeach
                </dl>
            </section>
            @if($service['slug'] !== 'google-ads-setup')
                <section class="mt-10" aria-labelledby="current-plans">
                    <h2 id="current-plans" class="text-2xl font-bold">Current management plans</h2>
                    <p class="mt-3 text-gray-600">Subscription prices are in USD. Advertising spend is additional.</p>
                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        @foreach(data_get($publicProps, 'plans', []) as $plan)
                            <div class="rounded-xl border border-gray-200 bg-white p-6">
                                <h3 class="text-xl font-bold">{{ data_get($plan, 'name') }}</h3>
                                <p class="mt-3 text-xl font-semibold">@if(data_get($plan, 'price_cents') > 0) US${{ number_format(data_get($plan, 'price_cents') / 100, 0) }}/{{ data_get($plan, 'billing_interval') === 'year' ? 'year' : 'mo' }} @else Contact us @endif</p>
                                <ul class="mt-4 space-y-2">@foreach((data_get($plan, 'features') ?? []) as $feature)<li>{{ $feature }}</li>@endforeach</ul>
                                <a href="/register" class="mt-5 inline-block font-semibold underline">Review my business</a>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
            <p class="mt-10"><a href="/register" class="inline-block rounded-lg bg-gray-900 px-6 py-3 font-semibold text-white">Get started</a> <a href="/pricing" class="ml-5 font-semibold underline">Compare pricing</a></p>
        @endif
    </main>
    <footer class="border-t border-gray-200 bg-white px-6 py-8">
        <nav aria-label="More from Site to Spend" class="mx-auto flex max-w-5xl flex-wrap gap-5 text-sm font-semibold">
            <a href="/google-ads-management">Google Ads management</a>
            <a href="/ai-ads-management">AI ads management</a>
            <a href="/google-ads-setup">One-time Google Ads setup</a>
            <a href="/blog">Guides</a>
            <a href="/about">About</a>
        </nav>
    </footer>
</div>
