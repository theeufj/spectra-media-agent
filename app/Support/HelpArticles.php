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
            'title'       => 'How Conversion Tracking Works',
            'description' => 'Learn how sitetospend.com tracks what happens after someone clicks your ad, and how we set it all up automatically — no developer needed.',
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
            'description' => 'A step-by-step guide to launching your first AI-managed ad campaign — from signup to live campaign in under 30 minutes.',
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
}
