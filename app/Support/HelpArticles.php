<?php

namespace App\Support;

class HelpArticles
{
    public static function all(): array
    {
        return [
            self::conversionTracking(),
            self::aiAgents(),
            self::smartBidding(),
            self::competitorAnalysis(),
            self::gettingStarted(),
            self::negativeKeywords(),
            self::adRankExplained(),
            self::responsiveSearchAds(),
            self::aiAdCopywriting(),
            self::audienceTargeting(),
            self::budgetPacing(),
            self::multiPlatformAdvertising(),
            self::understandingRoas(),
            self::whyGoogleAdsIsHard(),
            self::whyAdsStopWorking(),
            self::hiddenCostOfManaging(),
            self::whySmallBusinessLoses(),
            self::campaignStructureMistakes(),
            self::landingPageConversions(),
            self::facebookAdsExplained(),
        ];
    }

    public static function find(string $slug): ?array
    {
        foreach (self::all() as $article) {
            if ($article['slug'] === $slug) {
                return $article;
            }
        }
        return null;
    }

    public static function index(): array
    {
        return array_map(fn($a) => array_diff_key($a, ['content' => '']), self::all());
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function conversionTracking(): array
    {
        return [
            'slug'        => 'how-conversion-tracking-works',
            'title'       => 'Google Ads Conversion Tracking Explained',
            'description' => 'Learn how sitetospend.com tracks conversions after someone clicks your Google Ad — and how we set it all up automatically with no developer needed.',
            'category'    => 'Platform',
            'read_time'   => '8 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>What is a conversion?</h2>
<p>A conversion is any action a visitor takes that you consider valuable — filling out a contact form, making a purchase, booking a call, or signing up. When someone clicks your Google Ad and then completes one of these actions, that's a conversion.</p>
<p>Google Ads uses conversions to understand which clicks are worth paying for. Without this data, Google shows your ads to everyone equally. With it, Google learns <em>who actually converts</em> and bids more aggressively to reach people who look like your best customers. This is the foundation of Smart Bidding.</p>

<h2>The gclid — how Google links a click to a conversion</h2>
<p>When someone clicks your Google Ad, Google appends a unique tracking code — called a <strong>gclid</strong> (Google Click Identifier) — to the destination URL:</p>
<pre><code>https://yoursite.com/contact?gclid=CjwKCAiA85efBhBb...</code></pre>
<p>This gclid is Google's receipt for that specific click. When a conversion fires later, Google matches it back to the original click using the gclid. That's how it knows which ad, which keyword, and which audience led to the conversion.</p>
<p>sitetospend.com captures this gclid automatically when a visitor lands on your site and can upload server-side conversions later — even for actions that happen in our system, not on your webpage.</p>

<h2>Google Tag Manager — the remote control for your website</h2>
<p>Normally, adding tracking to a website means editing its HTML every time. Google Tag Manager (GTM) removes this entirely. The client installs <strong>one small snippet</strong> into their site once, and sitetospend.com can then add, change, or remove tracking tags remotely — no developer involvement needed after the initial install.</p>
<p>Think of GTM as an app store installed on your website. sitetospend.com pushes tags into it via the GTM API. Your site automatically runs whatever we publish.</p>
<ul>
  <li><strong>Client's job:</strong> Paste the GTM snippet into the website <code>&lt;head&gt;</code> and <code>&lt;body&gt;</code> once (5 minutes, any developer or WordPress admin can do it)</li>
  <li><strong>sitetospend.com's job:</strong> Create conversion actions, add tags, configure triggers, publish the container — all via API, automatically</li>
</ul>

<h2>How a conversion actually fires</h2>
<p>Once GTM is on the site, it watches for triggers you define — a form submission, a button click, a thank-you page loading. When the trigger condition is met, the conversion tag fires and sends the gclid + a dollar value to Google Ads. Google records the conversion against the original click.</p>
<p>Common trigger types:</p>
<ul>
  <li><strong>Page load</strong> — fires when a specific URL loads (e.g. <code>/thank-you</code>). Most reliable.</li>
  <li><strong>Form submit</strong> — fires when a contact or booking form is submitted</li>
  <li><strong>Button click</strong> — fires when "Book Now" or "Get a Quote" is clicked</li>
  <li><strong>Phone tap</strong> — fires when a phone number link is tapped on mobile</li>
</ul>

<h2>Why we track multiple events — not just the sale</h2>
<p>A sale or signed contract might happen once a week. Google's Smart Bidding needs at least 30 conversions per month to work well. By tracking multiple points in the funnel — each with an estimated dollar value — we give Google far more data to learn from, far faster.</p>
<ul>
  <li>Pricing page visit — $5 (shows commercial intent)</li>
  <li>Contact form submitted — $30 (high intent lead)</li>
  <li>Phone call tapped — $40 (very high intent)</li>
  <li>Quote request sent — $60 (purchase intent)</li>
  <li>Sale / booking confirmed — $500+ (actual revenue)</li>
</ul>
<p>These values don't have to be exact — they represent relative importance. Over time, sitetospend.com refines them as real revenue data comes in.</p>

<h2>What sitetospend.com does automatically</h2>
<p>When a new client connects their Google Ads account, sitetospend.com automatically:</p>
<ol>
  <li>Crawls their live website to detect the existing GTM container (if any)</li>
  <li>Provisions a new GTM container if none is found</li>
  <li>Creates a Google Ads conversion action via the API</li>
  <li>Adds the conversion tag and trigger to the GTM container</li>
  <li>Publishes the container so it's live immediately</li>
  <li>Provides the GTM installation snippet if the client needs to add it to their site</li>
</ol>
<p>The client's only manual step is pasting the GTM snippet into their site — and only if they don't have GTM already.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function aiAgents(): array
    {
        return [
            'slug'        => 'how-ai-agents-work',
            'title'       => 'How Our AI Agents Optimise Your Campaigns 24/7',
            'description' => 'sitetospend.com runs six autonomous AI agents that continuously monitor, fix, and improve your ad campaigns — without you lifting a finger.',
            'category'    => 'Platform',
            'read_time'   => '6 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>Why autonomous agents instead of dashboards?</h2>
<p>Most ad platforms give you a dashboard and leave the work to you. The problem: Google Ads has hundreds of levers, changes constantly, and rewards speed. A competitor can launch a new offer at 2am and steal your position before you wake up.</p>
<p>sitetospend.com runs six AI agents that work continuously — analysing performance, making improvements, and responding to changes in real time. You get agency-level management without hiring an agency.</p>

<h2>The Self-Healing Agent</h2>
<p>Google disapproves ads more often than you'd expect — policy changes, new keyword restrictions, editorial issues. Every hour an ad is disapproved is lost traffic. The Self-Healing Agent:</p>
<ul>
  <li>Monitors all active ads for disapproval status</li>
  <li>Identifies the specific policy violation using Google's error codes</li>
  <li>Rewrites the ad copy using AI to be policy-compliant while maintaining your brand voice</li>
  <li>Resubmits the ad automatically</li>
</ul>
<p>Most disapprovals are resolved within hours, not days.</p>

<h2>The Budget Intelligence Agent</h2>
<p>Ad spend is rarely worth the same money at every hour of the day. A plumber's ad at 3am has little value; the same ad at 7am (when people discover a leak) is highly valuable. The Budget Intelligence Agent:</p>
<ul>
  <li>Analyses conversion data by hour of day and day of week</li>
  <li>Identifies your high-value and low-value windows</li>
  <li>Adjusts bid modifiers to concentrate spend where it performs</li>
  <li>Reallocates budget away from underperforming time slots</li>
</ul>
<p>Over time this produces the same number of conversions for meaningfully less spend.</p>

<h2>The Quality Score Agent</h2>
<p>Quality Score is Google's rating of your ad relevance (1–10). A higher score means lower cost-per-click for the same position. The Quality Score Agent monitors all your keywords and takes targeted action:</p>
<ul>
  <li><strong>Low expected CTR:</strong> generates tighter ad copy variations that feature the exact keyword</li>
  <li><strong>Poor ad relevance:</strong> flags keywords that belong in their own dedicated ad group</li>
  <li><strong>Poor landing page experience:</strong> recommends page improvements (keyword in H1, faster load time, matching search intent)</li>
  <li><strong>Stuck below threshold:</strong> pauses keywords that haven't improved after 21 days to protect your budget</li>
</ul>

<h2>The Ad Extension Agent</h2>
<p>Ad extensions (sitelinks, callouts, structured snippets, call extensions) increase your ad's real estate on the search results page — at no extra cost. Google rewards extensions with better Ad Rank. The Ad Extension Agent ensures every campaign has minimum coverage:</p>
<ul>
  <li>4 sitelinks — AI-generated, specific to your business and campaign</li>
  <li>4 callouts — highlights unique selling points</li>
  <li>1 structured snippet — your key services or products</li>
  <li>Call extension — if your business has a phone number</li>
</ul>
<p>Extensions with low CTR (after 500+ impressions) are automatically replaced with AI-generated alternatives.</p>

<h2>The Competitor Intelligence Agent</h2>
<p>Every week, the Competitor Intelligence Agent:</p>
<ul>
  <li>Reads your website to understand your business and positioning</li>
  <li>Searches Google to discover who is competing for your keywords</li>
  <li>Scrapes competitor sites to extract their messaging, pricing, and offers</li>
  <li>Identifies gaps — where they're weak, what they're not saying</li>
  <li>Generates a counter-strategy with specific ad copy recommendations</li>
</ul>
<p>This intelligence feeds directly into your creative strategy and keeps your ads a step ahead of the competition.</p>

<h2>The Creative Intelligence Agent</h2>
<p>Ad copy gets stale. What worked in month one may not work in month three. The Creative Intelligence Agent continuously:</p>
<ul>
  <li>A/B tests headline and description combinations</li>
  <li>Pauses underperforming variations</li>
  <li>Generates new variations using AI, informed by your brand guidelines and competitor analysis</li>
  <li>Improves Responsive Search Ad strength by adding better headline and description assets</li>
</ul>

<h2>How they work together</h2>
<p>The agents share a common data layer — keyword performance, quality scores, competitor intelligence, conversion data, brand guidelines — so their decisions compound. The Creative Agent uses competitor insights. The Budget Agent uses conversion data from the conversion tracking setup. The Quality Score Agent feeds recommendations back into the Creative Agent. This is what makes the system increasingly effective over time rather than just maintaining a steady state.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function smartBidding(): array
    {
        return [
            'slug'        => 'what-is-smart-bidding',
            'title'       => 'What is Smart Bidding and Why Your Conversion Data Matters',
            'description' => 'Smart Bidding is Google\'s machine learning bidding system. Here\'s how it works, why it needs conversion data, and how sitetospend.com makes it work harder for you.',
            'category'    => 'Google Ads',
            'read_time'   => '5 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>What is Smart Bidding?</h2>
<p>Smart Bidding is Google's machine learning system for setting bids at auction time. Instead of you setting a fixed bid for a keyword, Google's algorithm analyses dozens of signals in real time — the user's device, location, time of day, search history, browser — and sets the optimal bid for <em>that specific person, in that specific moment</em>.</p>
<p>The goal is to show your ad to people who are most likely to convert, and not waste money on people who aren't.</p>

<h2>How it works in practice</h2>
<p>Every time someone searches on Google, there's an auction. For each auction, Smart Bidding asks: "Based on everything I know about this person, what's the probability they'll convert?" It then bids accordingly:</p>
<ul>
  <li>High probability of converting → bids aggressively to win the impression</li>
  <li>Low probability → bids conservatively or sits out entirely</li>
</ul>
<p>This happens in milliseconds, for every auction, across your entire campaign.</p>

<h2>Why it needs conversion data</h2>
<p>Smart Bidding is only as good as the data it learns from. If you have no conversions recorded, Google has no idea what a "successful" visitor looks like. It defaults to showing your ad to everyone, which wastes budget on low-intent users.</p>
<p>The more conversions you feed it, the better it gets at identifying the signals that predict conversion. This is why sitetospend.com sets up a <strong>conversion value ladder</strong> — multiple tracked events at different stages of your funnel — rather than just tracking the final sale.</p>

<h2>The learning phase</h2>
<p>When Smart Bidding first activates, or after a significant change, the campaign enters a "learning phase." During this period Google is collecting data and your performance may fluctuate. The learning phase typically lasts 1–2 weeks and ends once Google has enough conversions to bid confidently.</p>
<p>You can accelerate this by:</p>
<ul>
  <li>Tracking micro-conversions (pricing page visits, form interactions) in addition to final conversions</li>
  <li>Assigning realistic dollar values to each event</li>
  <li>Keeping campaigns stable — large budget or targeting changes restart the learning phase</li>
</ul>

<h2>tROAS and tCPA bidding</h2>
<p>Once Smart Bidding has enough data, you can switch to value-based bidding strategies:</p>
<ul>
  <li><strong>Target ROAS (tROAS)</strong> — tell Google your target return on ad spend. Google adjusts bids to maximise conversion value while hitting your ROAS target. Best once you have conversion values set up.</li>
  <li><strong>Target CPA (tCPA)</strong> — tell Google your target cost per acquisition. Google tries to get as many conversions as possible at or below that cost.</li>
</ul>
<p>Both strategies require a minimum number of conversions in the past 30 days (typically 30–50) before they work reliably. This is another reason why tracking the full funnel matters — it gets you to that threshold faster.</p>

<h2>How sitetospend.com helps</h2>
<p>sitetospend.com sets up conversion tracking automatically, tracks multiple funnel events with appropriate values, and continuously monitors campaign performance to ensure Smart Bidding always has the richest possible data set. As your conversion history grows, our agents progressively refine bid strategies to extract more value from the same budget.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function competitorAnalysis(): array
    {
        return [
            'slug'        => 'how-competitor-analysis-works',
            'title'       => 'How Competitor Analysis Gives You an Unfair Advantage',
            'description' => 'Every week, sitetospend.com discovers your real competitors, scrapes their messaging, and generates specific counter-strategies to help your ads win.',
            'category'    => 'Platform',
            'read_time'   => '5 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>Why most businesses don't do competitor analysis</h2>
<p>Competitor analysis is genuinely valuable — knowing what your competitors are saying, what they're offering, and where they're weak lets you write ads that win on differentiation rather than just bidding more. But it's also time-consuming and requires constant upkeep. Most businesses do it once and then forget it as the market changes.</p>
<p>sitetospend.com runs competitor analysis automatically every week so it's always current.</p>

<h2>Step 1 — Competitor discovery</h2>
<p>The analysis starts by understanding your business. The agent reads your website content to extract your key services, target audience, and geographic focus. It then runs a series of Google searches using the keywords and phrases that describe your offering.</p>
<p>The businesses that appear consistently in those results — in both paid ads and organic listings — are your real competitors. These are saved to your account as your competitor set.</p>

<h2>Step 2 — Deep scraping</h2>
<p>For each competitor, the agent visits their website and extracts:</p>
<ul>
  <li><strong>Value propositions</strong> — what they claim makes them better</li>
  <li><strong>Pricing signals</strong> — whether they publish prices, offer packages, or compete on price</li>
  <li><strong>Key messaging</strong> — the language and angles they lead with</li>
  <li><strong>Trust signals</strong> — reviews, certifications, guarantees they highlight</li>
  <li><strong>Offers and CTAs</strong> — what they're asking visitors to do</li>
</ul>
<p>This paints a complete picture of what the market is saying, which makes your gaps immediately visible.</p>

<h2>Step 3 — Gap analysis</h2>
<p>The agent compares competitor messaging against your own positioning to identify:</p>
<ul>
  <li>Claims your competitors make that you don't counter</li>
  <li>Angles nobody in your market is addressing</li>
  <li>Weaknesses in their offers you can exploit (e.g. they don't offer guarantees, you do)</li>
  <li>Pricing and value positioning opportunities</li>
</ul>

<h2>Step 4 — Counter-strategy generation</h2>
<p>Using the gap analysis, the AI generates a specific counter-strategy: which angles to lead with in your ads, which competitor weaknesses to highlight, and what messaging will differentiate you in search results. This feeds directly into your campaign's ad copy refresh cycle.</p>
<p>The result isn't generic advice — it's specific recommendations based on what your actual competitors are saying right now.</p>

<h2>The War Room</h2>
<p>All competitor intelligence is stored in your sitetospend.com War Room — a continuously updated view of your competitive landscape. You can see each competitor's messaging, your gap analysis, and the counter-strategy generated. This is also available to your team if you use the multi-user features.</p>
<p>Because this runs weekly, your competitive intelligence is never more than 7 days old — unlike the one-off analysis most businesses do and then forget.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function gettingStarted(): array
    {
        return [
            'slug'        => 'getting-started',
            'title'       => 'Getting Started with sitetospend.com',
            'description' => 'A step-by-step guide to launching your first AI-managed Google Ads campaign — from signup to live ads in under 30 minutes, no prior experience needed.',
            'category'    => 'Getting Started',
            'read_time'   => '4 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>What happens when you sign up</h2>
<p>sitetospend.com is designed to get you from zero to a live, AI-managed campaign as quickly as possible. Here's exactly what the process looks like.</p>

<h2>Step 1 — Tell us about your business</h2>
<p>After verifying your email, you'll complete a short business profile. This includes:</p>
<ul>
  <li>Your website URL (we'll crawl it to understand your business, services, and brand)</li>
  <li>Your target industry and location</li>
  <li>Your advertising goal (leads, sales, calls, etc.)</li>
  <li>Your approximate monthly ad budget</li>
</ul>
<p>Our AI reads your website immediately — extracting your brand voice, value propositions, services, and visual style. This becomes the foundation for everything your campaigns say and look like.</p>

<h2>Step 2 — Connect your Google Ads account</h2>
<p>sitetospend.com connects to your existing Google Ads account or creates a new one under our managed MCC. We handle the technical setup:</p>
<ul>
  <li>Conversion action creation</li>
  <li>GTM container setup and tag deployment</li>
  <li>Audience list creation</li>
  <li>Campaign structure design based on your goals</li>
</ul>
<p>You don't need to touch Google Ads at all — we manage it on your behalf.</p>

<h2>Step 3 — Review and approve your first campaign</h2>
<p>Before anything goes live, we generate your first campaign for you to review:</p>
<ul>
  <li>Campaign name and structure</li>
  <li>Keywords (with match types and negatives)</li>
  <li>Ad copy (Responsive Search Ads with headlines and descriptions)</li>
  <li>Ad extensions (sitelinks, callouts, structured snippets)</li>
  <li>Budget allocation and bidding strategy</li>
</ul>
<p>You can approve as-is or request changes. Once approved, we deploy to Google Ads automatically.</p>

<h2>Step 4 — The agents take over</h2>
<p>Once your campaign is live, all six AI agents activate immediately:</p>
<ul>
  <li>The <strong>Self-Healing Agent</strong> monitors for disapprovals</li>
  <li>The <strong>Budget Intelligence Agent</strong> starts analysing performance by time of day</li>
  <li>The <strong>Quality Score Agent</strong> monitors keyword quality scores</li>
  <li>The <strong>Ad Extension Agent</strong> ensures full extension coverage</li>
  <li>The <strong>Competitor Intelligence Agent</strong> begins its first analysis run</li>
  <li>The <strong>Creative Intelligence Agent</strong> starts A/B testing your ad variations</li>
</ul>

<h2>What to expect in the first 30 days</h2>
<p>Week 1 is the data collection phase. Google's Smart Bidding is in learning mode — performance may fluctuate while it calibrates. This is normal and expected.</p>
<p>By the end of week 2, bidding typically stabilises and you'll start seeing the agents make meaningful improvements — new ad variations, extension additions, time-of-day adjustments.</p>
<p>By month 1, you'll have competitor intelligence in your War Room, A/B test results, and a campaign that's meaningfully better than it was on day one — all without any action from you.</p>

<h2>Getting help</h2>
<p>Every action the agents take is logged in your Activity Feed with a clear explanation of what happened and why. If you have questions about any action, or want to understand a particular optimisation, you can submit a support ticket directly from the activity log.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function whyGoogleAdsIsHard(): array
    {
        return [
            'slug'        => 'why-google-ads-is-so-hard-to-manage',
            'title'       => 'Why Google Ads Is So Hard to Manage',
            'description' => 'Google Ads looks simple to set up but is notoriously difficult to manage profitably. Here\'s why — and what a properly managed campaign actually requires.',
            'category'    => 'Google Ads',
            'read_time'   => '7 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>The illusion of simplicity</h2>
<p>Google makes it remarkably easy to spend money on Google Ads. You can have an account set up and ads running in under an hour. The interface is clean, the setup wizard is friendly, and Google's onboarding actively encourages you to get started quickly. This is by design — and it's the first trap.</p>
<p>Creating a campaign is easy. Creating a <em>profitable</em> campaign is genuinely hard. The gap between the two is where most businesses quietly lose thousands of pounds every year without understanding why.</p>

<h2>The auction never stops changing</h2>
<p>The Google Ads auction is not a fixed marketplace. It changes every day. New competitors enter your keywords. Existing competitors increase their bids. Seasonal demand shifts. Google updates its algorithm. A competitor launches a new offer that pulls clicks away from yours.</p>
<p>A campaign that was profitable six months ago can be unprofitable today — not because you did anything wrong, but because the environment changed around you. Static campaigns, set and forgotten, degrade. They require active management to stay competitive.</p>

<h2>The data problem</h2>
<p>Good Google Ads decisions require data. But new campaigns have no data. This creates a bootstrapping problem: you can't optimise without data, but you can't get data without spending money. The learning phase — the period where Google's Smart Bidding is calibrating — can take 2–4 weeks and costs real money before it reliably works.</p>
<p>Even once you have data, interpreting it correctly requires experience. Is a high CPC a problem, or is the conversion rate compensating for it? Is a low CTR a creative issue, a keyword match issue, or a landing page mismatch? Is the campaign limited by budget, by Quality Score, or by bidding strategy? These questions aren't answered by looking at a single metric.</p>

<h2>The 47 things that need to be right simultaneously</h2>
<p>A well-run Google Ads campaign requires:</p>
<ul>
  <li>Keyword research — finding the right terms, in the right match types, at the right volume</li>
  <li>Negative keywords — continuously updated to prevent waste</li>
  <li>Campaign and ad group structure — close enough groupings to maintain ad relevance</li>
  <li>Ad copy — relevant, compelling, Google-policy-compliant, continuously A/B tested</li>
  <li>Ad extensions — sitelinks, callouts, structured snippets, call extensions, all populated</li>
  <li>Landing pages — fast, relevant, with a clear conversion path</li>
  <li>Conversion tracking — correctly implemented, tracking the right events with the right values</li>
  <li>Bidding strategy — right strategy for the right campaign stage with sufficient data</li>
  <li>Audience targeting — remarketing lists, RLSA, customer match, in-market audiences</li>
  <li>Budget management — correctly distributed, not running out at the wrong time of day</li>
  <li>Dayparting — bid adjustments by hour and day based on actual conversion data</li>
  <li>Device bid adjustments — based on actual device performance</li>
  <li>Geographic targeting — correct radius, bid adjustments by location</li>
  <li>Quality Score management — diagnosing and fixing low-scoring keywords</li>
  <li>Disapproval monitoring — catching and fixing policy violations quickly</li>
</ul>
<p>Each of these is its own discipline. Each requires attention. Each degrades if ignored. Most businesses get 4 or 5 of them right. The best campaigns get all of them right, consistently, over months and years.</p>

<h2>Why agencies often don't solve the problem</h2>
<p>The traditional solution is to hire an agency. But agencies have a structural problem: they're paid a percentage of ad spend (typically 10–20%), which creates an incentive to <em>increase</em> your spend, not optimise it. A £3,000/month campaign generates more agency revenue than a £1,500/month campaign with the same return — even though the £1,500 version is objectively better for your business.</p>
<p>Agencies are also expensive. Add a 15% management fee to a £3,000 monthly budget and you're paying £450/month for management before a single ad is clicked. For small and medium businesses, this fee represents a significant portion of the potential value Google Ads can deliver.</p>

<h2>How sitetospend.com is different</h2>
<p>sitetospend.com replaces the agency model with autonomous AI agents that run 24/7. Every optimisation task — negative keyword management, Quality Score monitoring, ad copy testing, budget pacing, competitor analysis, disapproval fixing — is automated and runs continuously. There's no account manager who checks in once a week and charges 15% of spend. The system works every day, on every campaign, applying the same rigour to a £500/month budget that would only be affordable for a £50,000/month budget with a traditional agency.</p>
<p>The result is a properly managed campaign at a fraction of the cost — and without the six months of learning that managing it yourself would require.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function whyAdsStopWorking(): array
    {
        return [
            'slug'        => 'why-your-google-ads-stop-working',
            'title'       => 'Why Your Google Ads Stop Working',
            'description' => 'Google Ads that worked brilliantly at launch often decline over months. Here\'s the real reasons campaign performance drops — and how to reverse it.',
            'category'    => 'Google Ads',
            'read_time'   => '6 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>The honeymoon period</h2>
<p>Many Google Ads campaigns have a honeymoon period. In the first few weeks, before competitors notice you, before Google has fully calibrated your Quality Scores, before ad fatigue sets in, clicks can be cheap and conversions can be plentiful. Then, gradually or suddenly, performance drops. Cost-per-lead climbs. ROAS falls. The campaign that was working stops working.</p>
<p>This is so common it has a name among PPC professionals: campaign decay. Understanding why it happens is the first step to preventing it.</p>

<h2>Reason 1: Competitors react</h2>
<p>When a new advertiser appears in an auction and starts winning impressions, competitors notice. If your ads are showing in positions that used to belong to established players, they'll increase their bids to push you out. Your average CPC rises as a result. The keyword that cost £1.20 in month one costs £1.80 in month four — not because you changed anything, but because the auction got more competitive around you.</p>
<p>The response isn't simply to outbid them — that's an expensive race to the bottom. The correct response is to improve Quality Score (so you achieve the same position at lower cost) and to use competitor intelligence to differentiate your ads so they're clicked more often.</p>

<h2>Reason 2: Ad fatigue</h2>
<p>The same ads shown to the same audience repeatedly lose their effectiveness. Click-through rates decline. Quality Scores fall as CTR drops. This happens invisibly — your campaign looks structurally the same, but the performance numbers worsen. Most businesses don't notice until the decline is significant.</p>
<p>The solution is continuous creative refresh: new headline variations, new angle tests, new calls to action. This needs to happen proactively, not reactively after performance has already dropped.</p>

<h2>Reason 3: Keyword match type drift</h2>
<p>Broad match and phrase match keywords expand over time. A keyword that was generating relevant traffic in month one starts matching to increasingly tangential searches as Google learns and widens its interpretation. Search term reports fill with irrelevant queries consuming budget. Without a systematic weekly review of the Search Terms report and ongoing negative keyword additions, this drift is inevitable.</p>

<h2>Reason 4: The landing page stopped converting</h2>
<p>Sometimes the ads are performing fine — the clicks are coming — but the landing page conversion rate has declined. A website redesign that changed the CTA. A price increase that's no longer competitive. A slow page after a new plugin was added. Page speed degradation as the site grew. Any of these can cause a conversion rate drop that looks like an ads problem but isn't one.</p>
<p>Diagnosing this requires separating ad metrics (CTR, impression share, Quality Score) from post-click metrics (conversion rate, bounce rate, time on page). If ad metrics are stable but conversion rate fell, the problem is the landing page.</p>

<h2>Reason 5: Budget erosion</h2>
<p>As CPCs rise from competition, a fixed daily budget runs out earlier in the day. A campaign that previously ran comfortably all day now exhausts its budget by midday. Afternoon and evening searches — which for many businesses are peak buying hours — receive no impressions at all. Revenue falls, but the daily spend looks the same on a report.</p>

<h2>Reason 6: Smart Bidding has insufficient conversion data</h2>
<p>If your campaign goes through a quiet period — seasonal lull, campaign pause, tracking issue — Google's Smart Bidding models can lose their calibration. A bidding strategy trained on 90 days of conversion history can perform very differently after 3 weeks of low conversion data. Google's learning phase effectively restarts, and performance fluctuates while it recalibrates.</p>

<h2>How sitetospend.com prevents campaign decay</h2>
<p>Every cause of campaign decay described above has a corresponding agent that prevents it. The Creative Intelligence Agent continuously refreshes ad copy before fatigue sets in. The Budget Intelligence Agent detects when CPCs are rising and adjusts dayparting to protect performance. The Self-Healing Agent monitors Search Term drift and maintains negative keyword lists weekly. The Quality Score Agent catches CTR declines before they become Quality Score problems. Together, these agents don't just fix problems — they prevent them from developing in the first place.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function hiddenCostOfManaging(): array
    {
        return [
            'slug'        => 'true-cost-of-managing-google-ads-yourself',
            'title'       => 'The True Cost of Managing Google Ads Yourself',
            'description' => 'The monthly fee is the visible cost. The real cost of DIY Google Ads management is hidden in wasted spend, missed optimisations, and your own time. Here\'s the full picture.',
            'category'    => 'Platform',
            'read_time'   => '5 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>The maths most businesses don't do</h2>
<p>When businesses consider whether to manage Google Ads themselves, they typically think about two numbers: the ad budget and the agency fee. "I'm spending £2,000/month on ads. An agency wants 15%, that's £300. I'll manage it myself and save £300." This calculation is almost always wrong.</p>
<p>It ignores the three real costs: wasted ad spend, the opportunity cost of your time, and the revenue lost from suboptimal performance.</p>

<h2>Cost 1: Wasted ad spend</h2>
<p>An unmanaged or poorly managed Google Ads account wastes money in predictable ways:</p>
<ul>
  <li><strong>Irrelevant clicks</strong> — without systematic negative keyword management, 15–30% of clicks typically come from non-converting search queries</li>
  <li><strong>Poor Quality Scores</strong> — keywords with low Quality Scores cost 2–4x more per click for the same position as high-QS keywords</li>
  <li><strong>Inefficient time-of-day spend</strong> — without dayparting, budget is spread evenly including the hours with the worst conversion rates</li>
  <li><strong>Disapproved ads</strong> — ads that violate policy and go unnoticed can result in campaigns running with few active ads for days</li>
  <li><strong>Suboptimal bids</strong> — manual bidding or misconfigured Smart Bidding is typically 20–30% less efficient than well-configured automated bidding</li>
</ul>
<p>On a £2,000/month account, conservative estimates put wasted spend at £400–700/month. That's the agency fee paid twice over — and instead of getting management, you're getting nothing back.</p>

<h2>Cost 2: Your time</h2>
<p>Properly managing a Google Ads account takes time. Not the 20 minutes a week that Google's automated recommendations suggest, but real time:</p>
<ul>
  <li>Search Terms report review — 30–60 minutes weekly</li>
  <li>Ad performance review and creative refresh — 60–90 minutes weekly</li>
  <li>Keyword and Quality Score analysis — 30 minutes weekly</li>
  <li>Campaign structure adjustments — periodic, but often 2–3 hours when needed</li>
  <li>Staying current on Google Ads changes — platform updates, new features, policy changes happen constantly</li>
</ul>
<p>A conservative total: 3–5 hours per week. If your time is worth £50/hour, that's £600–1,000/month. If you're a business owner whose time is worth more, the number is higher. Most businesses undercount this because they do it in scattered 15-minute sessions and never add it up.</p>

<h2>Cost 3: The revenue you didn't make</h2>
<p>The hardest cost to see is the revenue that a better-managed campaign would have generated but didn't. A campaign running at £15 cost-per-lead instead of £8 isn't just wasting £7 — it's generating half the number of leads from the same budget. Over 12 months on a £2,000/month account, the difference between average and excellent management can easily represent 200–400 additional leads.</p>
<p>At any reasonable lead-to-customer conversion rate and customer value, this revenue gap dwarfs the cost of professional management.</p>

<h2>What you actually need vs what you pay for</h2>
<p>The ideal scenario is a campaign that gets the attention of a senior PPC specialist every day — reviewing search terms, testing copy, monitoring Quality Scores, adjusting bids. But that level of human attention costs £2,000–4,000/month in agency fees, which is only economical for large-budget accounts.</p>
<p>sitetospend.com makes daily expert-level attention economically viable for any budget. The AI agents do the work of a senior PPC team — continuous, every day, automatically — at a flat subscription fee rather than a percentage of spend. For a £2,000/month ad budget, that's the difference between paying £300+/month for weekly check-ins or a fraction of that for daily automated optimisation.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function whySmallBusinessLoses(): array
    {
        return [
            'slug'        => 'why-small-businesses-lose-on-google-ads',
            'title'       => 'Why Small Businesses Lose on Google Ads',
            'description' => 'Large advertisers have dedicated teams, proprietary tools, and years of data. Here\'s how small businesses can compete — and where the real advantages lie.',
            'category'    => 'Google Ads',
            'read_time'   => '6 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>The unfair fight</h2>
<p>A national insurance company competing on Google Ads has a team of PPC specialists, a data science team, proprietary bidding software, years of conversion data, and a seven-figure monthly budget. A small insurance broker has a business owner who checks their Google Ads account every couple of weeks and a £1,500 monthly budget.</p>
<p>On paper, this looks like an unwinnable fight. In practice, small businesses can and do compete effectively with large advertisers on Google — but only if they understand where the real advantages lie and how to exploit them.</p>

<h2>Why big budgets don't automatically win</h2>
<p>Google's auction is intentionally designed so money alone doesn't guarantee victory. Ad Rank — the formula that determines who shows where — rewards quality as well as bid. A small advertiser with a highly relevant, well-written ad targeting a specific long-tail keyword can outrank a national advertiser bidding on broad terms.</p>
<p>Large advertisers often have sprawling accounts with thousands of keywords, many of which are poorly maintained. Their ads are generic, written to appeal to a national audience. Their landing pages are corporate and don't always match search intent precisely. These are exploitable weaknesses.</p>

<h2>Where small businesses actually win</h2>
<p><strong>Specificity.</strong> A national plumbing franchise targeting "plumber" loses on volume but wins on brand recognition. A local plumber targeting "emergency boiler repair Camden" with a highly relevant ad and a page specifically about emergency boiler repair in Camden can achieve higher Quality Scores, better CTR, and lower CPCs for that specific search. Local specificity is a genuine moat that large advertisers can't easily replicate.</p>
<p><strong>Speed.</strong> A small business can change its offer, update its ads, and adjust its landing page in hours. A large advertiser needs approvals, compliance reviews, and brand guidelines sign-off. When a competitor makes a mistake, you can respond immediately. When a seasonal opportunity emerges, you can capitalise on it before larger competitors' internal processes allow them to react.</p>
<p><strong>Relationship.</strong> Small businesses often convert at higher rates from phone calls and direct enquiries because they can personalise the response. An ad that says "Call Josh directly — we'll have a quote to you today" outperforms a corporate form submission for many service businesses.</p>

<h2>Where small businesses systematically lose</h2>
<p>The areas where small businesses genuinely struggle are the ones that require sustained, expert attention:</p>
<ul>
  <li><strong>Data volume</strong> — large advertisers accumulate conversion data faster, giving their Smart Bidding better signals sooner</li>
  <li><strong>Continuous optimisation</strong> — big advertisers have people checking accounts daily; small businesses check monthly if they're lucky</li>
  <li><strong>Competitive intelligence</strong> — large advertisers have tools to monitor competitor activity; small businesses typically fly blind</li>
  <li><strong>Testing infrastructure</strong> — large advertisers run structured A/B tests; small businesses run the same ads for months</li>
</ul>

<h2>How sitetospend.com closes the gap</h2>
<p>The capabilities that large advertisers pay teams to provide — daily optimisation, competitive monitoring, continuous creative testing, conversion tracking infrastructure — are exactly what sitetospend.com's AI agents deliver automatically. A small business using sitetospend.com gets the same quality of account management as a large advertiser's in-house team, at a fraction of the cost.</p>
<p>Combined with the natural advantages small businesses hold — local specificity, speed, and the ability to convert enquiries personally — this creates a genuinely competitive position in any local or niche market.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function campaignStructureMistakes(): array
    {
        return [
            'slug'        => 'google-ads-campaign-structure-mistakes',
            'title'       => 'The Google Ads Campaign Structure Mistakes That Kill Performance',
            'description' => 'Poor campaign structure is the root cause of most Google Ads underperformance. Here are the most common structural mistakes — and how to fix them.',
            'category'    => 'Google Ads',
            'read_time'   => '6 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>Why structure matters more than budget</h2>
<p>Two Google Ads accounts with identical budgets and identical keywords can produce dramatically different results depending purely on how they're structured. Campaign structure determines ad relevance, Quality Score, conversion tracking accuracy, and budget distribution. Get it wrong and every other optimisation you do is working against a structural handicap.</p>

<h2>Mistake 1: One campaign for everything</h2>
<p>The most common mistake is a single campaign containing all your services, all your locations, and all your products — with one shared budget. This makes it impossible to:</p>
<ul>
  <li>Allocate budget differently to different services or products based on their profitability</li>
  <li>Set different bid strategies for high-intent vs low-intent keywords</li>
  <li>Understand which services or areas are actually performing</li>
  <li>Optimise bidding separately for different goals</li>
</ul>
<p>The fix: one campaign per product/service line or geographic area, with its own budget and bidding strategy. Yes, this creates more campaigns to manage — which is exactly why automation helps.</p>

<h2>Mistake 2: Ad groups that are too broad</h2>
<p>Ad relevance — one of the three Quality Score components — measures how closely your ad matches a search query. If a single ad group contains 50 loosely related keywords, no single ad can be highly relevant to all of them. Your Quality Scores suffer across the board, raising CPCs.</p>
<p>The correct approach is tight ad groups: 5–15 closely related keywords per ad group, each served by ad copy that directly addresses those specific terms. This is called Single Keyword Ad Groups (SKAGs) in its most extreme form, but even moderately tight groupings produce measurably better Quality Scores than large catch-all ad groups.</p>

<h2>Mistake 3: Match type mismanagement</h2>
<p>Keyword match types — broad, phrase, and exact — control how closely a search query must match your keyword to trigger your ad. Broad match is powerful but dangerous: it allows Google to match your keyword to semantically related queries, which often includes irrelevant ones. Most campaigns need a deliberate match type strategy:</p>
<ul>
  <li><strong>Exact match</strong> for your highest-value, best-converting terms — maximum control</li>
  <li><strong>Phrase match</strong> for moderate-volume terms where some variation is acceptable</li>
  <li><strong>Broad match</strong> only with Smart Bidding and strong conversion data — allows Google to find new converting queries</li>
</ul>
<p>Running broad match keywords without robust negative keyword lists and sufficient conversion data is one of the fastest ways to drain a budget.</p>

<h2>Mistake 4: Mixing campaigns with different goals</h2>
<p>A campaign that mixes brand terms (your company name), competitor terms, and generic service keywords is difficult to manage and report on. Someone searching your brand name has very different intent from someone searching a generic service term — they should be in different campaigns with different bid strategies, budgets, and goals.</p>
<p>Brand campaigns typically warrant higher bids (protecting your brand terms is cheap and high-converting), while generic terms require more aggressive optimisation and carry higher costs.</p>

<h2>Mistake 5: No separation between Search and Display</h2>
<p>Google's default campaign setup often enables both Search and Display network targeting in the same campaign. These are fundamentally different advertising channels — Search reaches people actively searching, Display shows banner ads on websites. Combining them in one campaign mixes their performance data, making it impossible to optimise either properly. Always run them as separate campaigns.</p>

<h2>How sitetospend.com builds campaign structure</h2>
<p>When sitetospend.com creates your campaigns, it applies a structured framework: separate campaigns by service and intent type, tight ad groups with closely related keywords, appropriate match type distributions, and brand campaigns isolated from generic campaigns. This structure is established at launch and maintained over time — the agents won't collapse well-structured campaigns into inefficient arrangements, and any changes preserve the structural integrity that underpins campaign performance.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function landingPageConversions(): array
    {
        return [
            'slug'        => 'landing-page-conversion-rate-optimisation',
            'title'       => 'Landing Page CRO: Why Your Ads Click But Don\'t Convert',
            'description' => 'Getting clicks is only half the battle. If your landing page doesn\'t convert, every click is wasted money. Here\'s how to diagnose and fix landing page conversion problems.',
            'category'    => 'Platform',
            'read_time'   => '6 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>The click is not the win</h2>
<p>A common misconception in paid advertising is that the goal is to get as many clicks as possible. Clicks are a cost, not a result. The result is a conversion — an enquiry, a purchase, a booked call. A campaign that generates 200 clicks at 2% conversion rate produces 4 conversions. A campaign that generates 100 clicks at 8% conversion rate produces 8 conversions at half the spend.</p>
<p>Landing page conversion rate is often the highest-leverage variable in a Google Ads account — higher than any bid adjustment, keyword change, or ad copy test. Yet it's the variable most businesses neglect.</p>

<h2>The message match problem</h2>
<p>The most common landing page failure is message mismatch. Someone searches "emergency boiler repair London," clicks an ad that promises "Fast Emergency Boiler Repair — Same Day Response," and lands on your homepage. The homepage talks about your company history, your range of services, and has a generic contact form buried below the fold. The searcher, who wanted immediate reassurance that you offer emergency same-day repairs, leaves within seconds.</p>
<p>Every ad should lead to a page where the headline and primary content directly matches what the ad promised. If your ad targets emergency boiler repair, the landing page should lead with "Emergency Boiler Repair" — in the H1, in the first paragraph, in the CTA. This isn't just good UX — it directly improves your Quality Score's "landing page experience" component, lowering your CPCs.</p>

<h2>The five elements of a high-converting landing page</h2>
<ol>
  <li><strong>Headline that matches the ad</strong> — the user should see immediate confirmation they're in the right place</li>
  <li><strong>Clear, specific value proposition</strong> — what you offer, for whom, and why you're the right choice</li>
  <li><strong>Social proof above the fold</strong> — reviews, number of customers, years in business, recognisable client logos</li>
  <li><strong>Single, prominent CTA</strong> — one action to take, not four competing options. "Get a Free Quote" or "Call Now" — not both plus a newsletter signup and a download</li>
  <li><strong>Fast load time</strong> — every additional second of load time reduces conversion rate by approximately 7%. Google considers page speed in Quality Score. A page that takes 6 seconds to load on mobile is silently killing your campaign</li>
</ol>

<h2>What good conversion rates actually look like</h2>
<p>Conversion rates vary widely by industry and goal type, but as rough benchmarks:</p>
<ul>
  <li>Local service businesses (plumber, electrician, cleaner) — 8–15% from a well-targeted search ad</li>
  <li>Professional services (accountant, solicitor, consultant) — 5–10%</li>
  <li>E-commerce purchase — 2–5%</li>
  <li>Lead generation form completion — 3–8%</li>
  <li>Phone call from a mobile ad — 15–30% (phone calls convert better than form fills)</li>
</ul>
<p>If your conversion rate is below half these benchmarks, the landing page is almost certainly the problem — not the ads.</p>

<h2>How to diagnose a landing page problem</h2>
<p>If your Google Ads CTR is healthy (above 3–5% for Search) but conversions are low, isolate the problem to the landing page:</p>
<ul>
  <li>Check bounce rate — if over 70%, people are leaving immediately after seeing the page</li>
  <li>Check page speed — use Google PageSpeed Insights. Under 3 seconds is the target</li>
  <li>Check mobile experience — over 60% of searches are on mobile. Does your page work on a phone?</li>
  <li>Check message match — does your landing page headline match your ad copy?</li>
  <li>Check CTA visibility — can someone see what to do next without scrolling?</li>
</ul>

<h2>How sitetospend.com monitors landing page performance</h2>
<p>sitetospend.com tracks conversion data at the landing page level, not just the campaign level. The Quality Score Agent monitors "landing page experience" scores per keyword and flags when pages drop below acceptable thresholds. When a page consistently underperforms relative to ad click quality — high CTR, low conversion — the system raises a recommendation with specific improvement priorities: headline alignment, speed, CTA placement, or mobile optimisation. You'll see these recommendations in your Activity Feed with the data that supports them.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function facebookAdsExplained(): array
    {
        return [
            'slug'        => 'facebook-ads-for-small-business',
            'title'       => 'Facebook Ads for Small Business: What Actually Works',
            'description' => 'Facebook Ads can be transformative for small businesses — but most campaigns fail due to the same avoidable mistakes. Here\'s what actually works.',
            'category'    => 'Platform',
            'read_time'   => '7 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>Why Facebook Ads is different from Google Ads</h2>
<p>Google Ads captures people who are already looking for something. Facebook Ads reaches people who aren't looking for anything — they're scrolling, watching videos, seeing what friends are up to. This fundamental difference shapes everything about how Facebook advertising must be approached.</p>
<p>On Google, you match your ad to intent that already exists. On Facebook, you must create intent — interrupt someone's scroll, make them care about something they weren't thinking about, and move them toward a decision. This is harder creatively, requires different targeting logic, and demands a different relationship between your ads and your landing pages.</p>

<h2>Why most Facebook Ads campaigns fail</h2>
<p>The most common reason Facebook campaigns fail is treating it like a cheaper Google Ads. Businesses take the same messaging, the same offer, the same landing page — and push it to a Facebook audience. The results are predictably poor, because the audience has no active intent, the creative doesn't stop a scroll, and the funnel isn't built for cold traffic.</p>
<p>The second most common failure is giving up too early. Facebook's algorithm requires a learning phase — typically 50 conversions per ad set — before it can properly optimise delivery. Most small business campaigns are shut down for "poor performance" while still in the learning phase.</p>

<h2>The Facebook Ads funnel</h2>
<p>Effective Facebook advertising requires thinking in three stages:</p>
<ol>
  <li><strong>Awareness (cold audience)</strong> — reaching people who've never heard of you. The creative must stop the scroll and introduce your value proposition. The goal here isn't a sale — it's a click, a video view, or a page visit. Cast wide.</li>
  <li><strong>Consideration (warm audience)</strong> — reaching people who've engaged with your brand: visited your website, watched your video, interacted with a post. This audience knows you. The creative can be more specific, the offer more direct.</li>
  <li><strong>Conversion (hot audience)</strong> — reaching people who've shown high intent: visited your pricing page, added to cart, started an enquiry but didn't finish. This is where your most direct conversion messaging belongs. These audiences are small but convert at very high rates.</li>
</ol>
<p>Running only conversion campaigns to cold audiences is like asking someone to marry you on a first date. Running only awareness campaigns with no follow-up is like making a good first impression and then never calling. The full funnel compounds all three stages.</p>

<h2>Creative is the targeting on Facebook</h2>
<p>A common misconception is that Facebook's detailed targeting options — interests, demographics, behaviours — are the primary lever for performance. In practice, creative quality is more important. Facebook's algorithm is sophisticated enough to find your audience if your creative is good. But no amount of targeting precision makes a bad creative perform.</p>
<p>Effective Facebook creative typically:</p>
<ul>
  <li>Stops the scroll in the first 1–2 seconds (strong visual or opening line)</li>
  <li>Addresses a specific pain point or desire the audience has</li>
  <li>Makes the value proposition immediately clear</li>
  <li>Has a single, unambiguous call to action</li>
  <li>Looks native — too "salesy" and people scroll past; authentic-feeling content performs better</li>
</ul>

<h2>Facebook Pixel and conversion tracking</h2>
<p>Facebook's Pixel is a tracking tag on your website that records what visitors do after clicking an ad. Without it, Facebook has no idea which ad combinations lead to conversions — its algorithm can't optimise, and your reporting is meaningless. The Pixel should be the very first thing set up before any Facebook campaign goes live.</p>
<p>The Conversions API (CAPI) should also be implemented alongside the Pixel to capture conversions that browser-based tracking misses due to iOS privacy restrictions. Since Apple's iOS 14 changes, Pixel-only tracking can miss 20–40% of conversions.</p>

<h2>How sitetospend.com manages Facebook Ads</h2>
<p>sitetospend.com sets up and manages Facebook campaigns using Spectra's Business Manager — a professional management infrastructure that handles Pixel implementation, Conversions API setup, audience creation, and campaign management from a single platform. The same AI agents that optimise Google Ads — creative testing, audience refinement, budget pacing — apply to Facebook campaigns too. Remarketing audiences are built automatically from your website visitors, and the Creative Intelligence Agent continuously tests new ad variations to prevent audience fatigue and maintain campaign performance over time.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function negativeKeywords(): array
    {
        return [
            'slug'        => 'negative-keywords-explained',
            'title'       => 'Google Ads Negative Keywords Explained',
            'description' => 'Negative keywords are the fastest way to cut wasted spend on Google Ads. Here\'s how they work, why most accounts have too few, and how sitetospend.com manages them automatically.',
            'category'    => 'Google Ads',
            'read_time'   => '6 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>What is a negative keyword?</h2>
<p>A negative keyword tells Google: <em>do not show my ad when someone includes this word in their search</em>. Where regular keywords attract clicks, negative keywords repel irrelevant ones. They're a filter that stops your budget being consumed by searches that will never lead to a customer.</p>
<p>Example: a plumber running Google Ads for "emergency plumber London" without a negative keyword for "job" or "course" will have their ad shown to people searching "plumber London job" or "plumbing course London." These people aren't going to hire a plumber — they're looking for employment or training. Every click from them is pure waste.</p>

<h2>The three negative keyword match types</h2>
<p>Like regular keywords, negative keywords come in three match types:</p>
<ul>
  <li><strong>Broad match negative</strong> — blocks searches containing all the words in any order. Negative broad <em>plumber job</em> would block "job for plumber" and "plumber wanted jobs London."</li>
  <li><strong>Phrase match negative</strong> — blocks searches that contain the exact phrase in order. Negative phrase <em>"plumber job"</em> blocks "london plumber job" but not "job plumber london."</li>
  <li><strong>Exact match negative</strong> — blocks only that exact search query. Negative exact <em>[plumber job]</em> blocks only the search "plumber job," nothing else.</li>
</ul>
<p>For most use cases, phrase match negatives offer the right balance of coverage and precision.</p>

<h2>Why most Google Ads accounts have too few negatives</h2>
<p>Setting up negatives requires you to look at your actual search terms report — the real queries that triggered your ads — and identify the bad ones. This is tedious, requires experience, and needs to be done every week as new irrelevant queries accumulate. Most businesses set up a handful of obvious negatives at launch and then never revisit them. After 6 months, a significant percentage of their budget is typically being wasted on irrelevant traffic.</p>
<p>The Search Terms report consistently reveals surprises: competitor brand names, job-seeker queries, research queries, unrelated service terms. Without a systematic weekly review, waste compounds silently.</p>

<h2>Campaign-level vs account-level negatives</h2>
<p>Negative keywords can be applied at the ad group level, campaign level, or across the entire account via a <strong>negative keyword list</strong>. Account-level lists are the most powerful — you define them once and they apply everywhere. Common account-level negative lists include:</p>
<ul>
  <li>Job seekers — "jobs," "careers," "salary," "vacancy," "apply," "hiring"</li>
  <li>DIY / free — "DIY," "free," "how to," "yourself," "tutorial"</li>
  <li>Competitors — competitor brand names if you don't want to bid on them</li>
  <li>Irrelevant industries — terms from adjacent industries that share keywords with yours</li>
</ul>

<h2>How sitetospend.com manages negatives automatically</h2>
<p>Every week, sitetospend.com's agents review your Search Terms report and automatically identify search queries that:</p>
<ul>
  <li>Have generated more than 3 clicks with zero conversions</li>
  <li>Match known irrelevant patterns (job seeker terms, DIY terms, competitor names)</li>
  <li>Show up repeatedly across multiple weeks</li>
</ul>
<p>Identified waste terms are added to your campaign's negative keyword lists automatically. Over time, your campaign becomes increasingly efficient — the same budget reaches a progressively higher proportion of genuinely intent-driven searchers.</p>
<p>You can review all automatically added negatives in your Activity Feed, and override any addition if you disagree with the categorisation.</p>

<h2>How much can negatives save you?</h2>
<p>In a typical Google Ads account that hasn't been actively managed, 15–30% of clicks can come from irrelevant queries. For a business spending £2,000/month on Google Ads, that's £300–£600 every month going to people who were never going to buy. Systematic negative keyword management typically produces a 20–40% improvement in cost-per-conversion in the first 60 days — without spending more money, purely by stopping waste.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function adRankExplained(): array
    {
        return [
            'slug'        => 'what-is-ad-rank',
            'title'       => 'What is Ad Rank? How Google Decides Your Ad Position',
            'description' => 'Ad Rank determines your ad\'s position on Google\'s search results page — and it\'s not just about bid. Here\'s the full picture and how to improve it.',
            'category'    => 'Google Ads',
            'read_time'   => '5 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>The Google Ads auction isn't just about money</h2>
<p>Many people assume the advertiser who bids the most wins the top spot in Google search results. This is wrong — and understanding why is one of the most important things you can learn about Google Ads.</p>
<p>Google uses a formula called <strong>Ad Rank</strong> to decide who shows where. A business bidding £0.50 can outrank one bidding £2.00 if their ad quality is high enough. This is intentional: Google earns more from high-quality, relevant ads because people actually click them.</p>

<h2>The Ad Rank formula</h2>
<p>Ad Rank is calculated from five components at the time of each auction:</p>
<ol>
  <li><strong>Your bid</strong> — the maximum amount you're willing to pay per click</li>
  <li><strong>Expected CTR</strong> — Google's prediction of how often your ad will be clicked when shown</li>
  <li><strong>Ad relevance</strong> — how closely your ad matches the intent of the user's search query</li>
  <li><strong>Landing page experience</strong> — how relevant, trustworthy, and fast your landing page is</li>
  <li><strong>Ad extensions</strong> — whether you have sitelinks, callouts, and other extensions eligible to show</li>
</ol>
<p>Components 2, 3, and 4 together make up your <strong>Quality Score</strong> (rated 1–10 per keyword). A higher Quality Score means Google thinks your ad is genuinely useful to searchers — and rewards you with better position at lower cost.</p>

<h2>What this means in practice: the auction</h2>
<p>Every Google search triggers an instant auction among all advertisers who've bid on relevant keywords. The winner isn't necessarily the highest bidder — it's whoever has the highest Ad Rank. And crucially, the winner doesn't pay their maximum bid: they pay just enough to beat the Ad Rank of the advertiser below them.</p>
<p>This means a high-Quality-Score advertiser can win auctions and pay less per click than a lower-quality competitor — sometimes dramatically less. A Quality Score of 8 vs 4 on the same keyword can mean paying 50% less for the same position.</p>

<h2>How Ad Rank affects ad position and eligibility</h2>
<p>There are typically 3–4 paid positions at the top of Google's search results, and 3 at the bottom. Your Ad Rank determines:</p>
<ul>
  <li>Whether your ad shows at all (your Ad Rank must exceed a minimum threshold)</li>
  <li>Which position you appear in</li>
  <li>Whether your ad extensions are eligible to show</li>
  <li>How much you pay per click</li>
</ul>
<p>Ads that appear in position 1–3 at the top of the page get the most clicks. Position 1 (the very top) gets roughly 2–3x more clicks than position 3, which is why Ad Rank matters so much to your traffic volume.</p>

<h2>Improving Ad Rank without increasing bids</h2>
<p>The most cost-effective way to improve Ad Rank is to improve Quality Score — because it reduces what you need to pay to achieve the same position. The three Quality Score components each have specific levers:</p>
<ul>
  <li><strong>Expected CTR:</strong> Write tighter, more compelling headlines that include the exact keyword. Use emotional triggers and clear value propositions. Test multiple variations.</li>
  <li><strong>Ad relevance:</strong> Ensure your ad copy directly addresses the intent behind each keyword. Avoid grouping loosely related keywords into the same ad group.</li>
  <li><strong>Landing page experience:</strong> The keyword should appear in the page's H1. The page should load in under 3 seconds. The content should clearly deliver what the ad promised.</li>
</ul>
<p>Adding extensions also directly boosts Ad Rank. Google's own data shows that adding sitelinks increases Ad Rank — not just CTR — because extensions are factored into the formula separately.</p>

<h2>How sitetospend.com optimises Ad Rank</h2>
<p>The Quality Score Agent monitors every keyword's Ad Rank components weekly. When a keyword's Quality Score drops or stagnates, the agent diagnoses which component is the problem and takes specific action: new ad copy, keyword restructuring, or landing page recommendations. The Ad Extension Agent ensures every campaign always has full extension coverage — one of the easiest Ad Rank improvements available. Together, these agents progressively improve Ad Rank across your account, lowering your effective cost-per-click over time.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function responsiveSearchAds(): array
    {
        return [
            'slug'        => 'how-responsive-search-ads-work',
            'title'       => 'How Responsive Search Ads Work',
            'description' => 'Responsive Search Ads let you write up to 15 headlines and 4 descriptions. Google tests combinations to find what performs. Here\'s how to make RSAs work and how sitetospend.com optimises them continuously.',
            'category'    => 'Google Ads',
            'read_time'   => '5 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>What is a Responsive Search Ad?</h2>
<p>A Responsive Search Ad (RSA) is Google's standard ad format for Search campaigns. Instead of writing one fixed ad, you provide up to <strong>15 headlines</strong> and <strong>4 descriptions</strong>. Google automatically tests different combinations of these assets to discover which perform best for different searches and users.</p>
<p>When your ad shows, Google picks 3 headlines and 2 descriptions from your pool, assembles them in an order it believes will perform best, and displays them. Over time, Google learns which combinations drive the most clicks and conversions — and shows those combinations more often.</p>

<h2>Why RSAs replaced Expanded Text Ads</h2>
<p>Google sunset Expanded Text Ads (ETAs) in June 2022. ETAs had fixed headlines and descriptions — you wrote exactly what would show, every time. RSAs are more flexible: they can adapt to the context of a search query, the device, and the user's characteristics. A user searching from a mobile device might see a headline emphasising speed. Someone searching a more specific query might see a headline containing their exact search term.</p>
<p>The trade-off is control: with RSAs you can't guarantee which combination shows. The solution is to write assets that work well individually and in any combination.</p>

<h2>Ad Strength — and why it matters</h2>
<p>Google rates every RSA with an <strong>Ad Strength</strong> score: Poor, Average, Good, or Excellent. This score reflects how well your assets are optimised for the RSA format:</p>
<ul>
  <li>Are your headlines diverse (not repeating the same words)?</li>
  <li>Do you include the keyword in at least one headline?</li>
  <li>Have you filled as many asset slots as possible?</li>
  <li>Are your descriptions unique and benefit-focused?</li>
</ul>
<p>Ad Strength is directly correlated with Ad Rank. An "Excellent" RSA will achieve a higher position at lower cost than a "Poor" one with the same bid. Getting to "Good" or "Excellent" should be the immediate goal for every RSA in your account.</p>

<h2>How to write headlines that work in any combination</h2>
<p>Because Google combines headlines in different orders, each headline must stand alone as a complete, coherent thought. Common mistakes:</p>
<ul>
  <li><strong>Fragmented headlines</strong> — "Get a Free" as one headline and "Quote Today" as another. If Google shows them non-consecutively, neither makes sense.</li>
  <li><strong>Repetition</strong> — four headlines all saying "London's Best Plumber" in different wording. Google penalises this and your Ad Strength suffers.</li>
  <li><strong>Missing keyword insertion</strong> — at least one headline should contain the primary keyword so searchers immediately recognise relevance.</li>
</ul>
<p>Aim for headlines that cover: the keyword (relevance), a unique benefit (why you), social proof (trust), urgency or offer (action trigger), and a brand name (memorability).</p>

<h2>Pinning — and when to use it</h2>
<p>Google allows you to "pin" a headline to position 1, 2, or 3 — guaranteeing it always shows. This trades flexibility for control. Use pinning sparingly: pinning too many assets reduces the combination pool and lowers Ad Strength. Reserve pinning for legally required text (e.g. "T&Cs apply"), brand names you always want visible, or a headline so high-performing you never want Google to rotate it out.</p>

<h2>How sitetospend.com optimises RSAs continuously</h2>
<p>The Creative Intelligence Agent monitors RSA asset performance weekly. Assets labelled "Low" performing by Google (shown less often because they underperform) are replaced with AI-generated alternatives that draw on your brand guidelines, competitor analysis, and conversion data. New combinations are continuously introduced and tested. Over time, your RSAs evolve toward a higher-performance set of assets — with no manual effort required. The agent also ensures every RSA has an "Excellent" or "Good" Ad Strength rating, and alerts when any ad drops below this threshold.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function aiAdCopywriting(): array
    {
        return [
            'slug'        => 'how-ai-writes-your-ad-copy',
            'title'       => 'How AI Writes and Improves Your Google Ad Copy',
            'description' => 'sitetospend.com uses AI to generate, test, and improve your Google Ad copy automatically — informed by your brand, your competitors, and real conversion data.',
            'category'    => 'Platform',
            'read_time'   => '5 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>Why ad copy matters more than most advertisers realise</h2>
<p>Two businesses targeting identical keywords with identical bids can get dramatically different results based purely on their ad copy. Copy that closely matches search intent gets clicked more — and a higher click-through rate improves Quality Score, which in turn lowers your cost-per-click and improves your position. The compounding effect of better ad copy is substantial.</p>
<p>Despite this, most businesses write their initial ads at launch and rarely revisit them. Stale copy that was written 18 months ago, before the market shifted and before competitors changed their messaging, is quietly underperforming every day.</p>

<h2>How sitetospend.com reads your business before writing a word</h2>
<p>The AI doesn't generate generic marketing copy. Before writing anything, it builds a complete understanding of your business by crawling your website and extracting:</p>
<ul>
  <li><strong>Brand voice</strong> — formal vs. conversational, technical vs. plain-English, premium vs. accessible</li>
  <li><strong>Value propositions</strong> — what you genuinely offer that others don't</li>
  <li><strong>Services and products</strong> — the specific things you sell and their key benefits</li>
  <li><strong>Social proof signals</strong> — years in business, customer count, reviews, guarantees</li>
  <li><strong>Geographic targeting</strong> — location-specific terms that improve relevance</li>
</ul>
<p>This brief is stored in your account and used as the foundation for all copy generation. It's refreshed periodically as your site evolves.</p>

<h2>Incorporating competitor intelligence</h2>
<p>Copy written in a vacuum misses the most important question: <em>why should a customer choose you over the competitors showing ads right beside yours?</em> sitetospend.com's weekly competitor analysis feeds directly into copy generation. When the system knows that your main competitor emphasises price but not expertise, it generates headlines that lead with your credentials and experience. When a competitor runs a limited-time offer, the system can respond with a counter-offer headline.</p>
<p>This means your copy is always positioned relative to the competitive landscape, not written as if you're advertising in isolation.</p>

<h2>The continuous testing loop</h2>
<p>AI-generated copy isn't assumed to be correct — it's tested. Every RSA runs with multiple headline and description variations. The Creative Intelligence Agent monitors performance weekly:</p>
<ul>
  <li>Assets with a "Low" performance label (Google's own signal that the asset underperforms) are identified</li>
  <li>New replacement assets are generated using AI, informed by what's working well</li>
  <li>The new assets are deployed, and the testing cycle continues</li>
</ul>
<p>Over time this produces a progressively better-performing set of assets. The account improves every week, not just at launch.</p>

<h2>Character limits and Google's requirements</h2>
<p>The AI is constrained to Google's technical requirements: headlines must be 30 characters or fewer, descriptions 90 characters or fewer. It also avoids common policy violations — excessive punctuation, prohibited claims, trademark misuse — that would trigger a disapproval. Headlines and descriptions are generated in batches to maximise diversity across the asset pool, specifically to achieve an "Excellent" Ad Strength rating.</p>

<h2>What you can customise</h2>
<p>While the AI handles ongoing generation and testing, you retain full control. You can pin specific headlines to guaranteed positions (e.g. your brand name always appears), add specific messaging you want included (a limited offer, a seasonal promotion), or pause any variation you don't want shown. Changes you make are respected by the optimisation system — it won't overwrite pinned or manually-specified assets.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function audienceTargeting(): array
    {
        return [
            'slug'        => 'google-ads-audience-targeting',
            'title'       => 'Google Ads Audience Targeting Explained',
            'description' => 'Audience targeting lets you bid based on who is searching, not just what they\'re searching. Here\'s how Google\'s remarketing, RLSA and Customer Match tools work — and how we use them.',
            'category'    => 'Google Ads',
            'read_time'   => '6 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>Why keywords alone aren't enough</h2>
<p>Keyword targeting tells Google what topic to show your ad for. Audience targeting tells Google <em>who</em> to show your ad to. The combination is far more powerful than either alone. Two people can search the same keyword with very different purchase intent: someone who visited your pricing page yesterday is much more likely to convert than a first-time visitor who found you while comparing options.</p>
<p>Audience targeting lets you recognise the difference — and bid accordingly.</p>

<h2>Remarketing lists — reaching people who already know you</h2>
<p>Remarketing lists are built from a tracking tag on your website (placed automatically by sitetospend.com via GTM). As visitors browse your site, they're added to audience lists based on their behaviour:</p>
<ul>
  <li><strong>All website visitors</strong> — everyone who landed on any page</li>
  <li><strong>Pricing page visitors</strong> — high commercial intent</li>
  <li><strong>Contact page visitors</strong> — very high intent</li>
  <li><strong>Abandoned enquiry</strong> — started a form but didn't complete it</li>
  <li><strong>Past customers</strong> — people who converted previously</li>
</ul>
<p>By default, Google's Search campaigns target anyone searching your keywords — including people who've never heard of you. Adding remarketing audiences lets you give these high-intent, familiar visitors a bid boost, ensuring you're more competitive for the searchers who already have a relationship with your brand.</p>

<h2>RLSA — Remarketing Lists for Search Ads</h2>
<p>RLSA (Remarketing Lists for Search Ads) is the specific feature that combines audience lists with search campaigns. When someone on your remarketing list then searches for your keywords, you can bid more aggressively — because you know they already know you.</p>
<p>This is powerful in competitive markets where you can't afford to bid high for every search. By reserving your highest bids for searchers who've already shown interest, you spend more efficiently on the people most likely to convert.</p>

<h2>Customer Match — uploading your customer list</h2>
<p>Customer Match lets you upload a list of customer email addresses. Google matches them to signed-in Google accounts. You can then:</p>
<ul>
  <li>Bid more aggressively to win back lapsed customers</li>
  <li>Exclude existing customers from campaigns designed to win new business</li>
  <li>Create a "similar audiences" list — people who look like your existing customers, for prospecting</li>
</ul>
<p>Customer Match requires a minimum of 1,000 matched customers to be statistically usable, and a Google Ads account with a good compliance history.</p>

<h2>In-Market and Affinity audiences</h2>
<p>Beyond your own first-party data, Google offers:</p>
<ul>
  <li><strong>In-Market audiences</strong> — people Google has identified as actively researching purchases in a specific category. For example, "In-Market for Home Improvement Services" or "In-Market for B2B Software." Adding these as bid adjustments ensures you bid more aggressively when a qualified searcher sees your ad.</li>
  <li><strong>Affinity audiences</strong> — people with longer-term interests aligned with your business. Less immediately intent-driven than In-Market, but useful for brand awareness.</li>
</ul>

<h2>How sitetospend.com sets up audience targeting</h2>
<p>When your campaign launches, sitetospend.com automatically creates audience lists using the GTM tag deployed to your site, layers RLSA audiences onto your Search campaigns with appropriate bid adjustments, and adds relevant In-Market audiences based on your industry. As your remarketing lists grow (they start empty and build up over 30–60 days), the audience signals become increasingly powerful — and the agents automatically increase bid adjustments as conversion data confirms which audiences perform best.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function budgetPacing(): array
    {
        return [
            'slug'        => 'how-budget-pacing-works',
            'title'       => 'How Google Ads Budget Pacing Works',
            'description' => 'Spending your Google Ads budget at the right times of day — not just as fast as possible — is critical to performance. Here\'s how intelligent budget pacing and dayparting works.',
            'category'    => 'Platform',
            'read_time'   => '5 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>The problem with "standard" budget delivery</h2>
<p>By default, Google tries to spread your daily budget evenly throughout the day. This sounds sensible but ignores a critical reality: not all hours are equal. For most businesses, 8am–6pm on weekdays dramatically outperforms midnight on Sunday. Spreading spend evenly means wasting budget in low-value windows.</p>
<p>Conversely, if your budget runs out by 2pm because mornings are busy, you're invisible to everyone searching in the afternoon and evening. Pacing matters in both directions.</p>

<h2>How ad scheduling (dayparting) works</h2>
<p>Google Ads lets you apply bid modifiers by hour of day and day of week. A +30% modifier at 8am means you're willing to bid 30% more during that hour — making you more competitive when conversion rates are highest. A -50% modifier at 2am means you're largely opting out of overnight traffic where your particular business sees few conversions.</p>
<p>Setting these modifiers correctly requires a meaningful amount of conversion data — typically 60–90 days of account history — to identify genuine performance patterns rather than noise. Done well, dayparting is one of the most reliable levers for improving cost-per-conversion.</p>

<h2>The Budget Intelligence Agent's approach</h2>
<p>sitetospend.com's Budget Intelligence Agent performs a weekly analysis of your conversion data broken down by hour and day. It builds a performance heat map showing which time windows produce conversions at what cost. It then:</p>
<ol>
  <li>Identifies your top-performing windows (highest conversion rate, lowest CPA)</li>
  <li>Identifies underperforming windows (spend with few or no conversions)</li>
  <li>Adjusts bid modifiers to concentrate budget on high-value windows</li>
  <li>Applies negative adjustments to low-value windows to preserve budget for better slots</li>
</ol>
<p>The analysis runs weekly so it adapts to seasonal patterns — your business may have very different peak hours in December versus June.</p>

<h2>Device bid adjustments</h2>
<p>Beyond time of day, conversion rates often vary significantly by device. A B2B service business may see much higher conversion rates on desktop (people at their desk, comparing suppliers) than mobile. A restaurant or consumer service may see the reverse (people checking their phone while on the go). The same bid adjustment logic applies:</p>
<ul>
  <li>Identify conversion rate and CPA by device</li>
  <li>Increase bids on high-converting devices</li>
  <li>Reduce bids on low-converting devices</li>
</ul>
<p>Device modifiers can range from -90% (effectively pausing a device type) to +900%.</p>

<h2>Geographic bid adjustments</h2>
<p>If you serve multiple locations, performance typically varies by area. A London-based business may find that searches from Zone 1 convert at twice the rate of searches from outer areas — reflecting proximity to the service. Geographic bid adjustments ensure your budget concentrates on the areas that produce the most business, rather than spreading equally across a wide radius.</p>

<h2>Budget vs. bid — understanding the relationship</h2>
<p>Budget sets the maximum daily spend. Bids determine how aggressively you compete in each auction. The two must work together: a high bid with a small budget means you win auctions but run out of budget early, missing afternoon and evening traffic. A low bid with a large budget means you never run out of budget but you're rarely winning competitive auctions. The Budget Intelligence Agent balances both — setting bids that keep you competitive in priority windows while ensuring the budget lasts across your full active day.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function multiPlatformAdvertising(): array
    {
        return [
            'slug'        => 'multi-platform-advertising',
            'title'       => 'Why Multi-Platform Advertising Works',
            'description' => 'Running ads on Google, Facebook, Microsoft, and LinkedIn reaches customers at every stage of the buying journey. Here\'s how each platform differs and how to manage them together.',
            'category'    => 'Platform',
            'read_time'   => '7 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>Why one platform is never enough</h2>
<p>Google Ads reaches people who are actively searching for what you sell. That's powerful — but it only captures demand that already exists. What about the potential customers who don't yet know they need you, or who know they need a solution but haven't started searching for providers?</p>
<p>A multi-platform strategy lets you capture existing demand (Google, Microsoft) <em>and</em> create new demand (Facebook, LinkedIn). Together, these platforms cover the full customer journey — from initial awareness to active purchase intent.</p>

<h2>Google Ads — capturing purchase intent</h2>
<p>Google Search is the gold standard for capturing high-intent demand. When someone searches "emergency plumber London" or "accountant for small business," they have an immediate, specific need. Google's Search network puts your ad directly in front of that intent at the moment it exists.</p>
<p>Google also offers Display (banner ads across the web), Shopping (product listings), YouTube (video ads), and Performance Max (AI-driven cross-channel campaigns). For most businesses starting out, Search campaigns are the foundation.</p>
<p>Google Ads' biggest strength: <strong>intent targeting</strong>. Its biggest weakness: it only reaches people actively searching — not the much larger pool of potential customers who aren't looking yet.</p>

<h2>Microsoft Ads (Bing) — the overlooked opportunity</h2>
<p>Microsoft Advertising runs on Bing, Yahoo, and DuckDuckGo. It's often dismissed because its search volume is lower than Google. But this misunderstands the opportunity:</p>
<ul>
  <li>Bing's audience skews older and higher-income — often a better demographic for B2B and premium consumer services</li>
  <li>Competition is lower — many advertisers ignore Bing entirely, meaning lower cost-per-click for the same keywords</li>
  <li>Import from Google — campaigns can be imported directly from Google Ads, keeping setup effort minimal</li>
</ul>
<p>For most businesses, Microsoft Ads delivers 15–30% more volume on top of Google, at a lower CPC. It's one of the most overlooked easy wins in digital advertising.</p>

<h2>Facebook and Instagram Ads — creating demand</h2>
<p>Facebook doesn't have a search bar waiting for purchase intent. Instead, it has extraordinary targeting: 2.9 billion people who have told Facebook their age, location, interests, job, and life events. You can show your ad to "homeowners aged 35–55 in London who are interested in home improvement" before they've thought about contacting anyone.</p>
<p>Facebook Ads are most powerful for:</p>
<ul>
  <li><strong>Remarketing</strong> — showing ads to people who visited your website but didn't convert</li>
  <li><strong>Lookalike audiences</strong> — reaching new people who share characteristics with your existing customers</li>
  <li><strong>Brand awareness</strong> — reaching a broad qualified audience before they enter a search phase</li>
  <li><strong>Lead generation</strong> — Facebook's native lead forms keep users on-platform and have high completion rates</li>
</ul>

<h2>LinkedIn Ads — B2B precision</h2>
<p>LinkedIn Ads are the most expensive advertising platform on a CPM basis — and often worth every penny for B2B businesses. LinkedIn's unique advantage is professional targeting: you can reach people by job title, seniority, company size, industry, and skills. No other platform lets you show an ad specifically to "Finance Directors at companies with 200–1,000 employees in financial services."</p>
<p>LinkedIn is particularly effective for high-value B2B products and services where reaching the right decision-maker is more important than reaching volume.</p>

<h2>How sitetospend.com manages all platforms together</h2>
<p>sitetospend.com manages campaigns on all four platforms from a single account. The AI agents — Self-Healing, Budget Intelligence, Quality Score, Creative Intelligence — work across all connected platforms, applying the same continuous optimisation to each. Your brand assets, messaging, and competitor intelligence are shared across platforms, ensuring a consistent voice and coherent strategy whether a customer encounters you on Google, Facebook, or LinkedIn.</p>
<p>Attribution is unified: sitetospend.com tracks conversions across all platforms and reports total ad spend, total conversions, and blended ROAS — so you can see where your budget is working hardest across your entire advertising ecosystem.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function understandingRoas(): array
    {
        return [
            'slug'        => 'understanding-roas',
            'title'       => 'What is ROAS and How Do You Improve It?',
            'description' => 'Return on Ad Spend (ROAS) measures how much revenue you get for every pound spent on ads. Here\'s what it means, how to set a target for your margins, and how to improve it.',
            'category'    => 'Google Ads',
            'read_time'   => '5 min read',
            'published'   => '2026-05-03',
            'content'     => <<<HTML
<h2>What is ROAS?</h2>
<p>Return on Ad Spend (ROAS) measures how much revenue you generate for every pound spent on advertising. It's the fundamental metric for determining whether your campaigns are profitable.</p>
<p>The formula is simple:</p>
<pre><code>ROAS = Revenue from Ads ÷ Ad Spend × 100

Example: £10,000 revenue from £2,000 ad spend = 500% ROAS (or 5x ROAS)</code></pre>
<p>A 500% ROAS means every £1 you spend returns £5 in revenue. Whether that's profitable depends on your margins — which is why ROAS is a starting point, not the finish line.</p>

<h2>ROAS vs. ROI — understanding the difference</h2>
<p>ROAS measures revenue relative to ad spend. ROI (Return on Investment) measures <em>profit</em> relative to total costs. A business with high ROAS can still have poor ROI if its margins are thin.</p>
<p>Example: a business with 20% gross margins (e.g. a product that costs 80p to make and sells for £1) needs a 500% ROAS just to break even on ad spend. A business with 70% margins (software, services) might be highly profitable at 200% ROAS.</p>
<p>Your <strong>target ROAS</strong> should be based on your margins, not on benchmarks from other industries. The minimum viable ROAS = 100% ÷ gross margin %.</p>

<h2>How to calculate your target ROAS</h2>
<p>Work through this calculation:</p>
<ol>
  <li><strong>Gross margin %</strong> — what percentage of revenue is gross profit? E.g. if you keep 40p from every £1 of revenue, your gross margin is 40%.</li>
  <li><strong>Breakeven ROAS</strong> — 100 ÷ 40% = 250%. At 250% ROAS, ad spend exactly equals gross profit. You're covering the cost of the ads but making nothing extra.</li>
  <li><strong>Target ROAS</strong> — add headroom above breakeven based on your profitability goals. If you want ad spend to represent no more than 20% of revenue, your target ROAS is 500%.</li>
</ol>

<h2>Why ROAS fluctuates — and what to do about it</h2>
<p>ROAS changes based on competition (higher CPCs reduce ROAS), seasonality (Christmas boosts ecommerce ROAS dramatically), campaign structure, and ad quality. Common reasons ROAS falls:</p>
<ul>
  <li>CPC increases due to increased competition — more advertisers entering your market</li>
  <li>Conversion rate drops — landing page issues, seasonal drop in demand, offer no longer compelling</li>
  <li>Budget waste — irrelevant traffic draining spend that could go to converting searches</li>
  <li>Ad fatigue — the same ads shown too many times to the same audience</li>
</ul>

<h2>How sitetospend.com reports and optimises ROAS</h2>
<p>sitetospend.com tracks revenue values for each conversion event — not just counting conversions, but assigning values that reflect their real-world worth. This powers accurate ROAS reporting in your dashboard. More importantly, it enables Google's tROAS (Target ROAS) Smart Bidding strategy, where Google optimises bids specifically to hit your ROAS target.</p>
<p>Once your account has 30+ conversions with value data in the past 30 days, the agents will recommend transitioning to tROAS bidding — typically producing 15–30% ROAS improvement over manual bidding for the same spend. The Budget Intelligence Agent also monitors ROAS by time of day, device, and geography, making bid adjustments wherever ROAS is consistently above or below target.</p>

<h2>ROAS targets for different business types</h2>
<p>While every business is different, these are rough starting points by category:</p>
<ul>
  <li><strong>Ecommerce (low margin, e.g. electronics)</strong> — 600–1,000% ROAS needed to be profitable</li>
  <li><strong>Ecommerce (mid margin, e.g. fashion)</strong> — 300–500% ROAS target</li>
  <li><strong>Lead generation (high-value B2B)</strong> — 200–400% ROAS, though often measured as cost-per-lead instead</li>
  <li><strong>Local services (plumber, dentist)</strong> — £20–80 cost-per-lead target more useful than ROAS</li>
  <li><strong>SaaS / software</strong> — ROAS often measured on LTV basis: a £200 acquisition cost for a £2,000/year customer is 1,000% ROAS on a 12-month view</li>
</ul>
HTML,
        ];
    }
}
