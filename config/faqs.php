<?php

/*
 * Marketing FAQ copy.
 *
 * This lives in PHP rather than in the JSX because the JSON-LD built from it has
 * to be in the server's HTML. The app has no Inertia SSR, so a page component
 * renders nothing until React runs: `curl /` returns a <body> containing one
 * empty div. Google's renderer executes the JavaScript and eventually sees the
 * page, but GPTBot, ClaudeBot, PerplexityBot and the social unfurlers do not run
 * it — and robots.txt goes out of its way to invite exactly those crawlers in.
 * Structured data emitted from a React <Head> is therefore invisible to every
 * reader it was written for.
 *
 * LandingController and the pricing controller pass these both ways: as a `faqs`
 * prop the accordion renders, and as the `meta.schema` graph app.blade.php
 * prints server-side. One array, so the questions on the page and the questions
 * in the markup cannot drift apart.
 *
 * Answers are written answer-first and kept near 40 words. A generative engine
 * quoting this page takes the opening sentence, and the visitor scanning it on a
 * phone reads one screen rather than four.
 *
 * `:setup_fee` is substituted by LandingController from
 * config('services.stripe.setup_fee_usd_cents'), which is the same key
 * SetupFeeService charges. A price typed in here instead would keep answering
 * "US$999" for as long as nobody remembered this file existed.
 */

return [

    'landing' => [
        [
            'question' => 'How does sitetospend work?',
            'answer' => 'You enter your website address and nothing else. Our vision AI reads the site for your colours, tone of voice and what you actually sell, researches who else is bidding on your keywords, then builds the campaign, writes the ad copy and pushes it live in your own ad accounts. Six agents keep working on it from there.',
        ],
        [
            'question' => 'Which ad platforms does sitetospend support?',
            'answer' => 'Google Ads, Meta across Facebook and Instagram, Microsoft Ads on Bing, and LinkedIn Ads — all from one dashboard. You connect accounts you already own rather than handing budget to a reseller, so your spend history, conversion data and audience lists stay with you if you ever leave.',
        ],
        [
            'question' => 'Do I need a credit card to start?',
            'answer' => 'No. You can connect your site and see the brand extraction, the competitor research and the full campaign we would build for you before paying anything. A card is only required at the point you decide to push those campaigns live.',
        ],
        [
            'question' => 'Can I pay once instead of subscribing?',
            'answer' => 'Yes. One-time Google Ads setup is US$:setup_fee: we build the account, the campaigns, the ad copy and the conversion tracking, hand it over paused under your own Google billing, and that is the end of it. One payment, nothing recurring, no management. The monthly plans are for people who want the agents to keep running afterwards.',
        ],
        [
            'question' => 'Will I still own my ad accounts and data?',
            'answer' => 'Yes. Everything is built inside your own Google Ads and Meta accounts, under your billing, with your name on it. There is no lock-in contract and no notice period to serve — cancel and the campaigns, keyword research and conversion tracking stay exactly where they are.',
        ],
        [
            'question' => 'How is this different from hiring an agency?',
            'answer' => 'An agency charges a monthly retainer plus a percentage of your spend, and in practice a junior account manager reviews the campaigns about once a week. Our agents check performance every day, act on what they find within hours, and charge a flat fee that does not climb as your budget grows.',
        ],
        [
            'question' => 'What happens when an ad gets disapproved?',
            'answer' => 'The self-optimising agent spots the disapproval, works out which advertising policy was triggered, rewrites the offending headline or description and resubmits it for review. Most are cleared before you would have noticed — and a disapproved ad quietly costs you every impression it should have won.',
        ],
        [
            'question' => 'How quickly will I see results?',
            'answer' => 'Campaigns are usually live within minutes of connecting your website. Optimisation needs data, so expect the first week to be about learning which searches and audiences respond, and the second to be where budget starts shifting decisively toward what converts.',
        ],
        [
            'question' => 'Do I need to know anything about Google Ads?',
            'answer' => 'Not a thing. The dashboard is written in plain language rather than platform jargon, and every change an agent makes is logged alongside the reason it made it. Read the detail if you want it; the campaigns carry on if you never open it.',
        ],
    ],

    'pricing' => [
        [
            'question' => "What's included in the free tier?",
            'answer' => 'The free tier lets you properly kick the tyres before you commit: 3 website or file sources for brand matching, 4 AI-generated images per campaign (watermarked), 3 landing page audits, and unlimited ad copy. Going live on Google or Facebook requires a paid plan.',
        ],
        [
            'question' => 'Does the subscription price include my ad budget?',
            'answer' => "No, and that's intentional. Your subscription pays for the platform and the AI doing the work. Your actual ad spend goes straight to Google, Facebook and the other networks — we never touch it or mark it up.",
        ],
        [
            'question' => 'Is there a one-off option instead of a monthly plan?',
            'answer' => 'Yes — one-time Google Ads setup at US$:setup_fee. We build your account, campaigns, ad copy and conversion tracking, then hand it all over paused so you can add your own Google billing and run it yourself. A single payment with nothing recurring and no ongoing management, chosen when you first set up your account.',
        ],
        [
            'question' => 'How does ad spend billing work?',
            'answer' => "When you launch your first campaign we load 7 days' worth of estimated spend as a credit balance. Each morning at 6am we deduct the previous day's actual spend, and top the balance up automatically when it runs low so campaigns never go dark unexpectedly.",
        ],
        [
            'question' => 'What happens if a payment fails?',
            'answer' => "You get 24 hours to sort it out. If it's still unresolved we trim budgets by 50% to slow spend, and pause everything a day after that so nobody is out of pocket. The moment the payment goes through, everything picks back up at full speed.",
        ],
        [
            'question' => 'How do I update my payment method?',
            'answer' => "Billing → Ad Spend in your dashboard. If a payment has failed you'll also see a Retry Payment button — update the card, tap it, and we charge immediately and restart your campaigns.",
        ],
        [
            'question' => 'What do the AI specialists actually do?',
            'answer' => 'Six of them, running around the clock. One finds your competitors, one digs into their sites for angles you can use, one fixes rejected ads, one moves budget to the hours your customers are active, one tests ad variations and keeps the winners, one finds people who look like your existing customers.',
        ],
        [
            'question' => 'How does competitor analysis work?',
            'answer' => "Every week we read your website to understand your business, then look at who else is advertising in your space — their messaging, their offers, their positioning — and work out how you stand out against it. You don't have to ask; it just happens.",
        ],
        [
            'question' => 'What happens if my ad gets disapproved?',
            'answer' => "We catch it automatically, rewrite it so it passes Google's checks, and resubmit — without you needing to do anything. Ads that simply stop performing get paused before they waste more budget.",
        ],
        [
            'question' => 'How does it know what my brand looks like?',
            'answer' => 'We take a screenshot of your website and our vision AI reads it: your colours, your fonts, your tone. Every ad we create matches your look without you filling in a form or uploading a brand guide.',
        ],
        [
            'question' => 'I already have a Google Ads account — can you use it?',
            'answer' => 'Yes, and it is the fastest way to start. Give us your 10-digit customer ID and we send a manager request; you approve it inside Google Ads under Admin → Access and security → Managers. Billing stays with Google on your own payment method, and you can revoke access from the same screen.',
        ],
        [
            'question' => "What if I don't have a Google Ads account yet?",
            'answer' => "We'll walk you through creating one. Linking an account you already own is quicker, so if you have ever run ads — even years ago — it is worth digging out that login first.",
        ],
        [
            'question' => 'What access do you actually get to my ad account?',
            'answer' => 'Manager access, which lets us create and optimise campaigns. We never touch your billing: ad spend goes directly from you to Google and we take no percentage of it. Remove our access whenever you like and the account and its history stay entirely yours.',
        ],
        [
            'question' => 'Can I switch plans later?',
            'answer' => "Upgrade or downgrade any time from your dashboard. If you ever hit the limits of your plan we'll tell you before anything stops working.",
        ],
    ],

];
