<?php

namespace App\Support;

class HelpArticles
{
    /** @return list<array<string, mixed>> */
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

    /** @return array<string, mixed>|null */
    public static function find(string $slug): ?array
    {
        foreach (self::all() as $article) {
            if ($article['slug'] === $slug) {
                return $article;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public static function index(): array
    {
        return array_map(fn ($a) => array_diff_key($a, ['content' => '']), self::all());
    }

    /** @return list<array<string, mixed>> */
    public static function related(string $slug): array
    {
        $article = self::find($slug);
        if ($article === null) {
            return [];
        }
        $clusters = [
            ['how-conversion-tracking-works', 'what-is-smart-bidding', 'understanding-roas', 'why-your-google-ads-stop-working'],
            ['how-budget-pacing-works', 'true-cost-of-managing-google-ads-yourself', 'google-ads-campaign-structure-mistakes', 'getting-started'],
            ['negative-keywords-explained', 'google-ads-campaign-structure-mistakes', 'what-is-ad-rank', 'how-responsive-search-ads-work'],
        ];
        $preferred = [];
        foreach ($clusters as $cluster) {
            if (in_array($slug, $cluster, true)) {
                $preferred = [...$preferred, ...$cluster];
            }
        }
        $articles = array_values(array_filter(self::index(), fn (array $item) => $item['slug'] !== $slug));
        usort($articles, fn (array $a, array $b) => (
            (in_array($b['slug'], $preferred, true) ? 2 : ($b['category'] === $article['category'] ? 1 : 0))
            <=> (in_array($a['slug'], $preferred, true) ? 2 : ($a['category'] === $article['category'] ? 1 : 0))
        ));

        return array_slice($articles, 0, 3);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function conversionTracking(): array
    {
        return [
            'slug' => 'how-conversion-tracking-works',
            'title' => 'Google Ads Conversion Tracking: Test Checklist',
            'description' => 'Test Google Ads conversion tracking from a real form or purchase to the right conversion action. Includes a worked lead example and GTM checks.',
            'category' => 'Google Ads',
            'read_time' => '7 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-08',
            'content' => <<<'HTML'
<h2>Track the outcome you would pay for</h2>
<p>Conversion tracking connects an ad interaction with a useful business action: a purchase, completed enquiry or qualified phone call. A loaded page or a clicked button can help diagnose a journey, but neither proves that a customer finished it.</p>
<p>Start by writing the event in plain language: “a visitor successfully submits the quote form”, rather than “someone clicks Submit”. The event definition should survive a failed form, a page refresh and a visitor returning later.</p>
<h2>An installed GTM container is only the starting point</h2>
<p>Google Tag Manager loads the tags published in a container. Its snippet does not prove a Google Ads conversion tag exists, uses the right account or fires at the right time. Check the container ID on the website, the published version and the trigger attached to the conversion tag.</p>
<p>Site to Spend can prepare conversion actions and publish tracking where the required permissions and integration are available. A website owner may still need to install the container or expose a reliable success event. Access to an existing container is required to change it.</p>
<h2>Choose primary and secondary conversions deliberately</h2>
<p>For a standard goal, a primary action is eligible for bidding when the campaign uses that goal. Secondary actions normally appear in “All conversions” for observation; a custom goal can also use secondary actions for bidding. Google explains the distinction in its <a href="https://support.google.com/google-ads/answer/11461796?hl=en">primary and secondary conversion guide</a>.</p>
<p>A useful starting point is a completed enquiry as the primary outcome, with pricing views and contact-button clicks recorded separately. Do not promote easy page visits into the main bidding goal simply to make the conversion count larger.</p>
<h2>Worked example: one enquiry, one conversion</h2>
<p><strong>Hypothetical implementation example, not a customer result.</strong> A business has a quote form. After the server accepts it, the site shows a confirmation and emits a dedicated <code>quote_request_success</code> event.</p>
<ol><li>A visitor clicks an ad and lands on the service page.</li><li>The form fails validation. No success event should fire.</li><li>The visitor fixes the form and submits it. The accepted submission fires the success event once.</li><li>The confirmation is refreshed. The same lead should not be counted again.</li></ol>
<p>Use a submission or transaction identifier where your integration supports deduplication. Keep the identifier free of personal details. For a purchase, use the actual order value and currency; a lead value should be a defensible estimate, not a pretend sale.</p>
<h2>Test the complete path before changing bids</h2>
<ul><li>Open the site in <a href="https://support.google.com/tagmanager/answer/6107056?hl=en">GTM Preview and Tag Assistant</a> and confirm you are testing the live container.</li><li>Complete the real success action and verify that the intended tag fires once, after success.</li><li>Check the Google Ads conversion ID and label against the intended customer account and action.</li><li>Confirm the campaign uses that conversion goal, with the appropriate primary action.</li><li>Test the consent states relevant to your visitors. Do not bypass consent controls to make a test look successful.</li><li>Check a later real ad-attributed conversion in Google Ads after reporting has processed it.</li></ul>
<p>Tag Assistant confirms implementation behaviour. A direct test visit without an attributable ad interaction does not prove a paid-ad conversion will appear in reports. “No recent conversions” can also reflect low volume; distinguish it from a tag failing to fire.</p>
<h2>Keep website analytics and Google Ads reporting separate</h2>
<p>Your website can count a successful enquiry even when Google Ads has no eligible ad interaction to attribute it to. Different attribution windows and reporting delays can also produce different totals. Reconcile a small sample of actual leads rather than demanding that every dashboard agree instantly.</p>
<p>Once the signal is trustworthy, use it to assess <a href="/blog/how-budget-pacing-works">budget pacing</a> and <a href="/blog/why-your-google-ads-stop-working">campaign delivery problems</a>. If you want help preparing a campaign, compare <a href="/google-ads-management">ongoing management</a> with a <a href="/google-ads-setup">one-time setup and handover</a>.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function aiAgents(): array
    {
        return [
            'slug' => 'how-ai-agents-work',
            'title' => 'How AI Agents Monitor and Adjust Your Ads',
            'description' => 'Follow an AI campaign decision from business context to a verified platform outcome. Learn what agents monitor and what still needs your input.',
            'category' => 'Platform',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>An agent needs a goal, evidence and a supported action</h2>
<p>Generating a campaign and managing it are different jobs. Generation prepares a proposal from your business sources. Ongoing management reads platform status and performance, decides whether a change is justified and checks the outcome. More automated changes do not necessarily mean better advertising.</p>
<p>Site to Spend's agents work with your confirmed business profile, campaign goal, available reporting and the permissions of the customer advertising account. Their roles include competitor research, delivery and policy monitoring, budget review, creative testing and audience work. The managed plan keeps this work running after deployment; one-time setup ends with a paused handover.</p>
<h2>What each part contributes</h2>
<ul><li><strong>Business context:</strong> the service, offer, audience, locations and claims the campaign may use.</li><li><strong>Platform evidence:</strong> account restrictions, campaign and ad eligibility, spend, search terms and conversion signals.</li><li><strong>A change:</strong> a supported edit such as correcting copy, excluding unsuitable intent or adjusting a campaign setting.</li><li><strong>Verification:</strong> a later platform check showing whether the problem is resolved.</li></ul>
<p>These are separate checkpoints. A successful API response means Google accepted a request; it does not prove that an ad is approved, has served or produced a useful lead.</p>
<h2>Worked example: a disapproved destination</h2>
<p><strong>Hypothetical diagnostic example, not a customer result.</strong> An ad stops serving because its destination returns an error. The campaign is still enabled, and its daily budget has not changed.</p>
<ol><li>Read the ad's policy status and identify the affected destination.</li><li>Check that the page loads and offers what the ad promises.</li><li>If a valid replacement page exists for the same offer, make a supported destination edit and record the reason.</li><li>Verify Google's resulting review state and actual delivery.</li></ol>
<p>If the business website is down, an ad rewrite alone cannot repair it. The account owner or website administrator must act. An agent should make that dependency visible rather than repeatedly claiming that the campaign was fixed.</p>
<h2>Inspect an action record</h2>
<p>Look for the affected campaign, the observation that prompted a decision, what changed, when it changed and the verification result. If a report says “improved performance”, ask which metric, which period and how much relevant data support the conclusion.</p>
<p>A few clicks can produce large percentage swings. A lead that becomes a sale several days later also changes the interpretation of recent results. Reliable <a href="/blog/how-conversion-tracking-works">conversion tracking</a> and a sensible review period are prerequisites for performance decisions.</p>
<h2>What still needs you</h2>
<p>You must confirm accurate business facts, choose a budget and complete required billing, identity or website steps. Google controls advertising review and auctions. Its <a href="https://support.google.com/google-ads/answer/9208915?hl=en">Search delivery guidance</a> is useful when diagnosing restrictions.</p>
<p>Use <a href="/blog/why-your-google-ads-stop-working">our repair checklist</a> to judge whether an outcome was verified. Compare <a href="/ai-ads-management">AI management</a> and <a href="/google-ads-setup">one-time setup</a> before deciding who should own the ongoing work.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function smartBidding(): array
    {
        return [
            'slug' => 'what-is-smart-bidding',
            'title' => 'Smart Bidding and Reliable Conversion Data',
            'description' => 'Choose a Google bidding goal using genuine enquiries or sales. Includes a target CPA example and checks before judging a learning campaign.',
            'category' => 'Google Ads',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>Smart Bidding optimises the signal you supply</h2>
<p>Google's Smart Bidding sets auction-time bids towards conversions or conversion value. Strategies include Maximize conversions, Target CPA, Maximize conversion value and Target ROAS. The right choice depends on what you measure and the business outcome you want. Google's <a href="https://support.google.com/google-ads/answer/7065882?hl=en">Smart Bidding guide</a> describes these strategies and their data considerations.</p>
<p>If your main conversion is a pricing-page view, an automated strategy can pursue visits to that page rather than paid customers. Correct measurement matters before a clever target or a larger budget.</p>
<h2>Distinguish the goal from a limit or guarantee</h2>
<p>A target CPA is a desired average cost per action, not a guaranteed price for every enquiry. A target ROAS is a desired relationship between measured value and advertising cost, not guaranteed profit. Very restrictive targets can limit delivery; removing every constraint can also expose you to spend you did not intend.</p>
<p>Check the strategy's current requirements for your campaign type. Avoid treating a fixed conversion count quoted in an old guide as a universal requirement for every strategy and account.</p>
<h2>Worked example: a lead campaign with a target</h2>
<p><strong>Hypothetical calculation, not a forecast.</strong> A campaign spent A$400 and recorded eight genuine enquiries, giving an observed A$50 cost per enquiry. The business now proposes an A$10 target CPA.</p>
<p>The target expresses an ambition five times lower than the observed cost; entering it does not create cheaper customers. First check lead quality, conversion accuracy and the period measured. Then consider whether the offer, query intent or landing page offers a realistic path to a lower cost. A tighter target alone may reduce the auctions the campaign can enter.</p>
<h2>Before you change the bidding strategy</h2>
<ol><li>Test the successful form or purchase event and its deduplication.</li><li>Confirm the campaign uses the intended conversion goal.</li><li>For value-based bidding, check value and currency against actual transactions or a documented lead-value estimate.</li><li>Review complete periods that allow for conversion delay.</li><li>Record current spend, volume, qualified outcomes and the proposed change.</li></ol>
<p>Do not count a contact-button click as a completed lead merely to create more data. Keep exploratory events separate from the outcome the business values. Our <a href="/blog/how-conversion-tracking-works">conversion checklist</a> explains the practical checks.</p>
<h2>Evaluate a change without creating constant churn</h2>
<p>Allow a relevant observation period and enough outcomes to interpret the result. Google recommends assessing Smart Bidding over longer periods with meaningful conversion volume; that is evaluation guidance, not a promise that every new campaign must wait for one identical threshold.</p>
<p>A low-volume campaign can produce a noisy average. Consider the business's conversion cycle and recent setting changes before declaring a winner or repeatedly resetting the plan.</p>
<h2>How management fits around bidding</h2>
<p>Site to Spend does not replace Google's auction system. Its managed flow works on campaign preparation, supported settings and monitoring around the chosen goal. Use <a href="/blog/how-budget-pacing-works">budget pacing</a>, <a href="/blog/understanding-roas">ROAS and margin</a> and <a href="/google-ads-management">management scope</a> together when deciding whether a bidding change is warranted.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function competitorAnalysis(): array
    {
        return [
            'slug' => 'how-competitor-analysis-works',
            'title' => 'Competitor Research for Google Ads',
            'description' => 'Turn competitor offers into campaign hypotheses without copying claims or guessing spend. Includes a comparison example and Auction Insights checks.',
            'category' => 'Platform',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>A competitor report should inform a decision</h2>
<p>A list of nearby businesses is not a campaign adjustment. Useful competitor research identifies a relevant alternative your buyer might consider, verifies what it offers and proposes a change your own business can honestly support.</p>
<p>Site to Spend reads your business sources and competitor websites for offers, messaging and positioning. Those observations can inform strategy and supported campaign changes. A business appearing in search results is not proof that it bids against you on every keyword.</p>
<h2>Separate observed facts from hypotheses</h2>
<p>Record the source URL and observation date. A price published on a page is a fact about that page at that time, subject to its conditions. “Customers care most about price” is a hypothesis. “This advertiser spends A$10,000 each month” is not established by seeing its ad.</p>
<p>Google's <a href="https://support.google.com/google-ads/answer/2579754?hl=en">Auction Insights</a> reports advertisers participating in overlapping auctions when activity thresholds are met. It provides relative metrics such as overlap and impression share; it does not expose a competitor's complete budget, conversions or profitability.</p>
<h2>Worked example: convenience versus price</h2>
<p><strong>Hypothetical comparison, not competitor evidence.</strong> A local service business sees one rival lead with a discount and another with online booking. Its own verified offer is weekend appointments at the published standard rate.</p>
<ol><li>Confirm that weekend appointments are genuinely available in the targeted area.</li><li>Use the booking page as the destination, with availability visible.</li><li>Test copy about weekend availability against the current service-focused message.</li><li>Measure completed bookings and accepted enquiries, not just clicks.</li></ol>
<p>Copying the rival's discount would advertise a price the business does not offer. Claiming “cheapest” would need supporting evidence. The useful idea is a truthful difference that answers the buyer's need.</p>
<h2>Build a compact comparison record</h2>
<ul><li>Competitor and source URL.</li><li>Service and area that actually overlap.</li><li>Observed offer, with restrictions and date.</li><li>Your verified difference.</li><li>Proposed ad, keyword or page hypothesis.</li><li>Outcome and review period that will test it.</li></ul>
<p>For keyword suggestions, apply the offer test from our <a href="/blog/google-ads-campaign-structure-mistakes">campaign structure guide</a>. A competitor with a broad service catalogue may attract searches that are unsuitable for your narrower business.</p>
<h2>Do not change everything from one report</h2>
<p>Choose a change that can be evaluated. If copy, landing page, targeting and budget all change together, a later improvement is hard to explain. Also check current delivery before making a creative decision: an account hold is not a messaging problem.</p>
<p>Keep the original settings, proposed rationale and follow-up observations. A competitor report can be stale, and a platform recommendation can be inappropriate for your budget or goal.</p>
<h2>Make the report useful after launch</h2>
<p>Review whether the relevant offer changed, whether your differentiator is still true and whether the campaign outcomes support the experiment. Connect research with <a href="/blog/how-ai-writes-your-ad-copy">copy review</a>, <a href="/blog/how-conversion-tracking-works">measurement</a> and the activity history described in <a href="/blog/how-ai-agents-work">our agent guide</a>. Research is context for a decision, not a guarantee of winning auctions.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function gettingStarted(): array
    {
        return [
            'slug' => 'getting-started',
            'title' => 'Getting Started: Profile to Reviewed Ads',
            'description' => 'Prepare your website, confirm business facts and choose a goal before deploying. Follow the managed and one-time Google Ads paths without surprises.',
            'category' => 'Getting Started',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>Prepare the facts before the campaign</h2>
<p>Site to Spend starts from your business website and supplied sources. The campaign can only be as useful as the offer, audience and destination it has to work with. A page that says “we help businesses grow” gives less direction than one describing a specific service, service area and next action.</p>
<p>Before signing up, have a readable website, an email you can verify and someone able to handle account and website tasks. Creating the platform account is separate from completing Google advertiser verification or installing website tracking.</p>
<h2>Your onboarding checklist</h2>
<ol><li><strong>Verify your email:</strong> use an address you can access, with the correct spelling.</li><li><strong>Enter the website:</strong> include useful source pages for the offer you want to advertise.</li><li><strong>Review the business profile:</strong> confirm services, locations, tone and any extracted prices.</li><li><strong>Choose the goal:</strong> describe the useful enquiry, signup or sale.</li><li><strong>Choose the service:</strong> ongoing management or a one-time Google Ads build.</li><li><strong>Review the campaign and budget:</strong> check ads, search intent and destination before deployment.</li></ol>
<p>A generated draft does not authorise spending. The campaign budget must be confirmed, and deployment remains subject to payment, account readiness and platform eligibility.</p>
<h2>Worked example: a narrow first offer</h2>
<p><strong>Hypothetical onboarding example.</strong> An accountant serves small retail businesses and wants enquiries about bookkeeping. Its website also mentions tax returns and payroll.</p>
<p>For the first campaign, confirm bookkeeping as the offer, choose the actual service area and point ads to a bookkeeping page. Check that keyword suggestions concern buying that service rather than accounting jobs or software tutorials. Define a successful enquiry before choosing a bidding goal.</p>
<p>This gives the draft a concrete brief. You can add another campaign later when a different service needs its own message or budget.</p>
<h2>Managed plans and one-time setup have different endings</h2>
<p>A managed plan includes ongoing monitoring and supported changes after launch. Its subscription fee and advertising budget are separate; managed delivery uses ad-spend credits reconciled against actual spend.</p>
<p>The <a href="/google-ads-setup">one-time Google Ads package</a> commissions a build after payment is confirmed. Account provisioning triggers an administrator invitation to the signup email. The campaign is handed over paused, without recurring management, so you can inspect it, complete your own Google billing and decide when to enable it.</p>
<h2>Complete the website measurement step</h2>
<p>An installed tag-manager snippet is not a finished conversion setup. The intended success event needs to fire once and use the right account and action. Website access or container permissions may be required.</p>
<p>Test the real enquiry or checkout path using our <a href="/blog/how-conversion-tracking-works">conversion checklist</a>. Google's <a href="https://support.google.com/google-ads/answer/1722022?hl=en">conversion measurement guide</a> explains the available measurement approaches.</p>
<h2>Check readiness and the outcome after deployment</h2>
<p>Review account billing, access, dates, location settings and ad status. “Created” and “enabled” do not establish that ads are serving. Look for actual impressions and later qualified outcomes in reporting.</p>
<p>Compare <a href="/pricing">current prices and inclusions</a>, use the <a href="/blog/how-budget-pacing-works">budget example</a> and keep the <a href="/blog/why-your-google-ads-stop-working">delivery checklist</a> available for the first review.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function whyGoogleAdsIsHard(): array
    {
        return [
            'slug' => 'why-google-ads-is-so-hard-to-manage',
            'title' => 'Why Google Ads Needs a Clear Review Process',
            'description' => 'Separate delivery, search intent and measurement problems instead of changing settings blindly. Includes a five-level review and incident example.',
            'category' => 'Getting Started',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>One dashboard contains several different systems</h2>
<p>A Google Ads campaign combines account access, billing, advertising review, targeting, auctions, website experience and conversion measurement. A problem in any layer can affect results, but the remedy is not the same in every layer.</p>
<p>The difficult part is often deciding which evidence to trust and what to change first. An enabled label, an attractive headline or a high diagnostic score cannot establish a profitable customer journey.</p>
<h2>Review five levels in order</h2>
<ol><li><strong>Account readiness:</strong> access, billing, verification and any restrictions.</li><li><strong>Delivery:</strong> campaign dates, schedule, enabled resources and policy status.</li><li><strong>Intent:</strong> queries, location and the offer you actually sell.</li><li><strong>Destination:</strong> the promise in the ad and the action on the page.</li><li><strong>Outcome:</strong> reliable conversion data and qualified customer results.</li></ol>
<p>Google's <a href="https://support.google.com/google-ads/answer/9208915?hl=en">Search delivery troubleshooting</a> supports the early checks. Our <a href="/blog/why-your-google-ads-stop-working">diagnostic checklist</a> connects them with the website and business outcome.</p>
<h2>Worked example: when more settings create less clarity</h2>
<p><strong>Hypothetical incident.</strong> A lead campaign shows fewer reported enquiries after a website update. In response, someone raises the budget, changes the bid strategy and rewrites all the ads on the same day.</p>
<p>The form update had removed the success event. None of those advertising changes repairs the missing signal. They also make the later trend harder to interpret because several variables changed together.</p>
<p>A clearer process first tests the form and tracking, records when measurement stopped, repairs the event and annotates the affected reporting period. Then it reviews whether the campaign itself needs a separate change.</p>
<h2>Use diagnostics for questions they can answer</h2>
<p>Quality Score highlights components of the ad experience; it is not the auction formula or a business outcome. Ad Strength highlights asset opportunities; it does not certify serving. A keyword estimate is not actual customer demand at a guaranteed click price.</p>
<p>Read <a href="/blog/what-is-ad-rank">Ad Rank and Quality Score</a> and <a href="/blog/how-responsive-search-ads-work">responsive ad guidance</a> before turning a diagnostic into the sole optimisation target.</p>
<h2>Write down the decision, not just the new setting</h2>
<ul><li>What changed in the observed problem, and when?</li><li>Which source and complete period support the observation?</li><li>What alternative explanation did you check?</li><li>Which single intervention is justified?</li><li>What outcome will confirm it helped?</li><li>Does it require the account owner's or website team's input?</li></ul>
<p>This record is useful for human and automated management. A successful update request is not the final checkpoint; platform review and delivery must be verified where they matter.</p>
<h2>Choose who owns the ongoing review</h2>
<p>DIY management means owning the incident process as well as the initial setup. A one-time build gives you a prepared account and paused handover, after which the ongoing work is yours. A managed plan includes monitoring and supported changes, while platform decisions and business facts still require care.</p>
<p>Use <a href="/blog/how-ai-agents-work">the agent decision example</a> to inspect automation, <a href="/blog/true-cost-of-managing-google-ads-yourself">the cost comparison</a> to value your time and <a href="/google-ads-management">management scope</a> to choose an arrangement that fits.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function whyAdsStopWorking(): array
    {
        return [
            'slug' => 'why-your-google-ads-stop-working',
            'title' => 'Google Ads Not Running? A Diagnostic Checklist',
            'description' => 'Separate a campaign that cannot serve from one that gets clicks but no leads. Check billing, eligibility, keywords, bids and tracking in the right order.',
            'category' => 'Google Ads',
            'read_time' => '7 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-08',
            'content' => <<<'HTML'
<h2>First establish what stopped</h2>
<p>“The ads stopped working” can mean no impressions, no clicks, no recorded conversions or no useful customers. Each needs a different investigation. Start with a dated observation from the platform, rather than a dashboard label alone.</p>
<p>Check the account time zone and reporting delay. Compare complete days, using a period that suits your conversion cycle. An enabled campaign means it is configured to run; it does not prove that an ad entered or won an auction.</p>
<h2>No impressions: check eligibility before rewriting creative</h2>
<ol><li><strong>Account:</strong> billing holds, suspension, advertiser verification and security review.</li><li><strong>Campaign:</strong> enabled status, start and end dates, schedule, locations and budget.</li><li><strong>Ad group and ad:</strong> enabled state, policy review and serving eligibility.</li><li><strong>Keywords:</strong> approval, search demand and exclusions that could block the intended searches.</li><li><strong>Bidding:</strong> limits or targets that could make the campaign too restrictive.</li></ol>
<p>Record the exact status and reason at each level. Google's <a href="https://support.google.com/google-ads/answer/9208915?hl=en">Search delivery troubleshooting</a> is a useful checklist, and its <a href="https://support.google.com/google-ads/answer/148778">Ad Preview and Diagnosis tool</a> helps check a specific search without repeatedly searching for your own ad.</p>
<h2>Worked example: enabled is not serving</h2>
<p><strong>Hypothetical diagnostic example.</strong> A campaign has an A$30 average daily budget, is enabled and reports zero impressions across several complete days. Its only responsive search ad is disapproved.</p>
<p>The confirmed obstacle is the ad's eligibility. Raising the budget or adding more keywords cannot solve that obstacle. Fix the underlying policy or destination issue, resubmit where appropriate, then check Google's resulting review and actual delivery. Do not describe a successful API update as a successful repair until the platform outcome is verified.</p>
<h2>Impressions but few clicks: inspect the offer and intent</h2>
<p>Read the actual search terms alongside the ad and destination. Are people seeking your service, or jobs, training and information you do not sell? Does the ad clearly describe the offer? Does the page confirm that promise?</p>
<p>Compare trends with enough relevant observations. A low-volume campaign can produce large percentage swings from a few clicks. Do not replace all copy solely because one day's click-through rate changed. Also check whether network or device mix changed before judging the headline.</p>
<h2>Clicks but no recorded conversions: test measurement</h2>
<p>Complete the intended action on the website. Check that the form or checkout works and that the correct success event fires once. Verify the action and campaign goal in Google Ads. A working container snippet does not establish working conversion tracking.</p>
<p>Then allow for the time customers take to act. Use the <a href="/blog/how-conversion-tracking-works">conversion tracking checklist</a> to separate implementation failure from genuinely low conversion volume. A test visit without an eligible ad interaction is not proof of a paid-ad conversion.</p>
<h2>Conversions but poor customers: assess the definition</h2>
<p>A campaign can look productive while optimising for a weak signal, such as any contact-button click. Compare recorded leads with accepted enquiries, sales and margin. If the objective is wrong, improve measurement before increasing spend.</p>
<p>Location settings, an ambiguous offer or overly broad keyword intent can also attract unsuitable leads. Review <a href="/blog/google-ads-campaign-structure-mistakes">campaign structure</a> and search terms together rather than assuming every conversion has the same value.</p>
<h2>Keep a repair record</h2>
<p>For every intervention, keep the before state, reason, API or platform response and the follow-up check. If a problem needs billing details, identity verification or website access, it requires the account owner's input; automation should surface that clearly.</p>
<p>Site to Spend's managed plans provide monitoring and supported campaign adjustments. Platform approval and auction demand remain outside our control. See <a href="/ai-ads-management">how AI campaign management works</a> and <a href="/blog/how-budget-pacing-works">how to interpret spend</a> before treating a budget increase as the universal repair.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function hiddenCostOfManaging(): array
    {
        return [
            'slug' => 'true-cost-of-managing-google-ads-yourself',
            'title' => 'Google Ads Management Cost: Fees and Time',
            'description' => 'Compare Google Ads management costs without confusing fees with media spend. Work through DIY time, flat fees and one-time setup using your own numbers.',
            'category' => 'Getting Started',
            'read_time' => '6 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-08',
            'content' => <<<'HTML'
<h2>Compare the total cost, using the same scope</h2>
<p>Google Ads management cost has three parts: the fee for the service, the money paid for advertising and the time your team contributes. Tracking, landing-page work or creative production may be extra. Ask what is included before comparing two prices.</p>
<p>An inexpensive quote can still exclude work you need. A higher fee is not evidence of better results. Compare the proposed responsibilities, who owns access, what gets measured and how you will judge the campaign after launch.</p>
<h2>Four management models</h2>
<ul><li><strong>Do it yourself:</strong> no external management fee, but you own setup, monitoring, reporting and changes.</li><li><strong>Flat fee:</strong> a stated recurring charge for an agreed scope. Site to Spend's managed plan prices are published on the <a href="/pricing">pricing page</a>.</li><li><strong>Percentage of spend:</strong> the fee changes as media spend changes; confirm any minimum charge and extra services.</li><li><strong>One-time setup:</strong> pay for a build and handover, then take responsibility for ongoing work. See <a href="/google-ads-setup">our one-time package</a>.</li></ul>
<p>No model guarantees profitable traffic. The useful question is whether the scope and total cost fit your business, rather than which headline fee looks smallest.</p>
<h2>Worked example: valuing DIY time</h2>
<p><strong>Hypothetical comparison in USD, not an agency-price benchmark.</strong> A business plans US$1,000 of media spend per month and expects to spend four hours managing the account. It values that time at US$40 per hour.</p>
<ul><li>DIY media spend: US$1,000.</li><li>Illustrative value of management time: 4 × US$40 = US$160.</li><li>Combined economic cost: US$1,160, before any tracking or page work.</li></ul>
<p>For a hypothetical flat management fee of US$200, with one hour still required internally, the comparable total would be US$1,240 (1,000 + 200 + 40). These assumptions are deliberately explicit. Delegating could be worth the difference if it frees useful time; it has not automatically saved money or improved conversion performance.</p>
<p>Replace the sample fee with a real quote or current published plan. Use your team's actual hours. If an agency charges 15% in a quote you receive, calculate that against your planned media spend, then include the minimum fee and any tax instead of assuming 15% is the whole bill.</p>
<h2>Calculate acquisition cost from qualified outcomes</h2>
<p>Suppose total media and management cost is US$1,240 and the campaign produces eight qualified leads. The combined cost per qualified lead is US$155. If two become customers, acquisition cost is US$620. Whether that works depends on contribution margin, retention and your cash flow.</p>
<p>Google Ads' platform CPA commonly uses advertising cost and measured conversions. Your business decision also needs the management fee and real lead quality. Keep both views so the platform's optimisation metric does not obscure your economics.</p>
<h2>Questions to ask before paying</h2>
<ol><li>Is this setup only or ongoing management?</li><li>Who holds administrator access to the advertising account?</li><li>Is media spend billed separately, in which currency, and through which process?</li><li>Does tracking cover the actual successful form or purchase event?</li><li>How are account restrictions and ads that stop delivering surfaced?</li><li>What evidence will support keyword, creative or budget changes?</li></ol>
<p>Use <a href="/blog/how-conversion-tracking-works">conversion testing</a> and the <a href="/blog/why-your-google-ads-stop-working">delivery checklist</a> to make those questions concrete.</p>
<h2>Know what Site to Spend offers</h2>
<p>The subscription plans cover ongoing AI campaign management with a separate advertising budget. The one-time Google Ads product covers preparation and a paused handover, without recurring management. Prices and plan coverage should be taken from the <a href="/pricing">current pricing page</a>, not an old article.</p>
<p>Google's <a href="https://support.google.com/google-ads/answer/1704424?hl=en">guide to managing spend</a> explains platform budget limits. Our <a href="/google-ads-management">small business management overview</a> explains the practical work around those limits.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function whySmallBusinessLoses(): array
    {
        return [
            'slug' => 'why-small-businesses-lose-on-google-ads',
            'title' => 'Small Business Google Ads: A First Review',
            'description' => 'Review offer, location, lead quality and budget before scaling a small-business campaign. Includes a worked example using transparent assumptions.',
            'category' => 'Getting Started',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>A small budget needs a specific offer</h2>
<p>A small business can lose money when its campaign describes a broad industry instead of the service it actually sells. High search volume, low estimated CPC or an impressive-looking ad are not enough. Ask whether the searcher is a plausible customer and whether the destination can turn that interest into a useful action.</p>
<p>Start with one offer, the area you serve and a measured enquiry or sale. This makes the first review understandable. It also avoids treating every click as equally valuable.</p>
<h2>Worked example: the cheapest clicks are not the best customers</h2>
<p><strong>Hypothetical comparison, not a benchmark.</strong> Campaign A spends A$200 for 100 clicks and two accepted enquiries. Campaign B spends A$200 for 40 clicks and four accepted enquiries.</p>
<p>A has A$2 CPC and A$100 cost per accepted enquiry. B has A$5 CPC and A$50 cost per accepted enquiry. Judging by click price alone favours A; judging by the defined business action favours B.</p>
<p>This example assumes comparable enquiries and correct measurement. It does not show that any real keyword, industry or platform will produce those rates. Actual customer value and close rate still decide whether either option is affordable.</p>
<h2>Check the six common gaps</h2>
<ol><li><strong>Offer:</strong> ads mention a service or price the business cannot fulfil.</li><li><strong>Intent:</strong> queries concern jobs, training, research or another product.</li><li><strong>Location:</strong> targeting reaches people the business cannot serve.</li><li><strong>Destination:</strong> the page does not explain the promised offer or action.</li><li><strong>Measurement:</strong> a page view or failed form is counted as a lead.</li><li><strong>Follow-up:</strong> suitable enquiries arrive but nobody responds promptly.</li></ol>
<p>The <a href="/blog/google-ads-campaign-structure-mistakes">structure guide</a>, <a href="/blog/negative-keywords-explained">negative keyword checks</a> and <a href="/blog/landing-page-conversion-rate-optimisation">page review</a> help make these checks practical.</p>
<h2>Do not fragment the first experiment unnecessarily</h2>
<p>Many tiny campaigns, channels and creative variants can leave little evidence for any decision. Separate campaigns when the budget, service area or goal needs independent control. Use relevant ad groups for related searches and messages.</p>
<p>There is no universal minimum budget that makes every business viable. Use the expected click costs, your risk allowance and the outcome you can afford. Avoid spending beyond that allowance just to hit an arbitrary conversion count.</p>
<h2>Account for the full economics</h2>
<p>Media spend is not the only cost. Management, setup, website work and lead-handling time can affect acquisition cost. Use <a href="/blog/true-cost-of-managing-google-ads-yourself">the cost worksheet example</a> and <a href="/blog/understanding-roas">margin-aware ROAS</a> rather than assuming revenue attributed to ads is profit.</p>
<p>Google's <a href="https://support.google.com/google-ads/answer/1722066?hl=en">ROI guide</a> describes considering revenue and costs. Use your business's actual margins and sale outcomes, with a clear reporting period.</p>
<h2>Scale after the journey is verified</h2>
<p>Before increasing the budget, confirm ad eligibility, relevant traffic, a working success event and acceptable customer economics. Review complete periods and conversion delay; a quiet afternoon or a handful of clicks is not enough to establish a lasting trend.</p>
<p>Site to Spend's <a href="/google-ads-management">small business management</a> prepares and monitors supported campaign resources. You still supply accurate facts, a fulfilable offer and the account or website actions that cannot be automated.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function campaignStructureMistakes(): array
    {
        return [
            'slug' => 'google-ads-campaign-structure-mistakes',
            'title' => 'Small Budget Google Ads Campaign Structure',
            'description' => 'Build a Google Ads structure around intent, service and landing page without fragmenting a small budget. Includes a worked local-service example.',
            'category' => 'Google Ads',
            'read_time' => '6 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-08',
            'content' => <<<'HTML'
<h2>Structure should support a decision</h2>
<p>A Google Ads account is organised into campaigns, ad groups, keywords, ads and assets. Campaigns hold controls such as budget and targeting. Ad groups bring a related set of searches and messages together. Separate things when you need a different decision; keep them together when the goal and message are genuinely shared.</p>
<p>There is no universal correct number of campaigns or keywords. A tiny budget split across many campaigns can make each harder to assess. A catch-all ad group can make it difficult to write a useful, specific ad. Aim for understandable intent and enough data to learn.</p>
<h2>Worked example: two services in one city</h2>
<p><strong>Hypothetical structure, not a result.</strong> A Brisbane business repairs residential leaks and installs hot-water systems. Both services use the same enquiry goal, but they have different landing pages and messages.</p>
<ul><li><strong>Campaign:</strong> Brisbane residential plumbing, with a single initial budget.</li><li><strong>Ad group 1:</strong> leak-repair searches, ads about leak repair, destination on the leak-repair page.</li><li><strong>Ad group 2:</strong> hot-water installation searches, ads about installation, destination on the hot-water page.</li></ul>
<p>If the business later needs a protected installation budget or different service area, that is a reason to split campaigns. It is not necessary to create a separate campaign for every spelling variation or suburb on day one.</p>
<h2>Keep a keyword-to-page map</h2>
<p>For each theme, record the service sold, the searcher's likely intent, destination and success action. For example: “hot-water installation” → installation service → installation page → successful quote request.</p>
<p>A keyword such as “plumbing apprenticeship” fails the offer test for this business. High volume or a low estimated click price does not make it useful. Review the real search terms after launch because the selected keyword and actual search are not always the same.</p>
<h2>Use match types as controls, not guarantees</h2>
<p>Exact match can include searches with the same meaning or intent; it is not restricted to a literal string. Phrase and broad match expand the matching scope differently. Read Google's <a href="https://support.google.com/google-ads/answer/7478529?hl=en">keyword matching guidance</a> before assuming an exact keyword prevents all irrelevant traffic.</p>
<p>Choose match types alongside your conversion signal, budget and ability to review queries. For a narrow service with limited data, start with a small, relevant set you can inspect. Broad match requires particular care with goals and ongoing review; it is not a substitute for knowing what the business sells.</p>
<h2>Negatives should exclude unsuitable intent</h2>
<p>Negative keywords are useful for searches you cannot serve, such as training or employment if you only sell repairs. Check their matching behaviour before applying a broad shared list. A negative that is too general can block a legitimate service enquiry.</p>
<p>Document why you exclude a term. For a business that repairs a particular brand, excluding that brand just because some searches concern spare parts can remove useful customers as well. See our <a href="/blog/negative-keywords-explained">negative keyword guide</a> for the matching details.</p>
<h2>Separate reporting when intent changes</h2>
<p>Someone searching your business name already knows you. Someone comparing providers may not. If you run brand activity, review it separately so it does not hide the cost of acquiring a new customer. Similarly, report different networks and services clearly before drawing conclusions from a blended total.</p>
<p>Do not force a structure change just to make a chart cleaner. Check whether the separation improves budget control, targeting, the message or interpretation of results.</p>
<h2>Measure the action, then improve the structure</h2>
<p>Before assessing an ad group, verify its <a href="/blog/how-conversion-tracking-works">conversion tracking</a>. A strong click-through rate with no qualified enquiries is not enough. Review the ad, query and page as a sequence, then make a change with a clear reason and follow-up period.</p>
<p>Google's <a href="https://support.google.com/google-ads/answer/2375470?hl=en">account organisation guide</a> explains the hierarchy. For practical budget checks, use <a href="/blog/how-budget-pacing-works">our pacing example</a>. Site to Spend's <a href="/google-ads-management">Google Ads management</a> prepares a campaign around your confirmed business information and lets you inspect it before deployment.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function landingPageConversions(): array
    {
        return [
            'slug' => 'landing-page-conversion-rate-optimisation',
            'title' => 'Landing Page CRO: A Practical Conversion Check',
            'description' => 'Check the promise, mobile journey, form and measurement before rewriting every ad. Includes a worked mobile example and an interpretable test plan.',
            'category' => 'Platform',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>The landing page must finish the promise in the ad</h2>
<p>A click brings a visitor to the destination; it is not yet an enquiry or sale. The page should confirm the service, audience, area and conditions that persuaded the visitor to click, then provide a working next action.</p>
<p>Conversion rate depends on the offer, intent and action being measured. Avoid treating an industry average or a page-speed statistic as a guaranteed target for your business. Begin by inspecting the real journey on the devices your visitors use.</p>
<h2>A first review on your phone</h2>
<ul><li>Does the headline confirm the specific service advertised?</li><li>Are area, price conditions and availability understandable?</li><li>Is the primary action visible and easy to use?</li><li>Can the form be completed with a mobile keyboard?</li><li>Does success appear only after the server accepts the enquiry?</li><li>Do errors, consent controls or overlays block the action?</li></ul>
<p>Trust information should be real: contact details, verifiable reviews and evidence for claims. An invented testimonial is not a CRO improvement. A visitor should be able to understand who fulfils the offer.</p>
<h2>Worked example: a mobile form that cannot finish</h2>
<p><strong>Hypothetical diagnostic example.</strong> A repair ad promises an online quote, but the mobile page has a fixed banner covering the Submit button. A button-click event records some attempts as conversions even though the form never succeeds.</p>
<ol><li>Repair the layout so the form can be completed.</li><li>Test required fields, error states and accepted submission.</li><li>Move the conversion trigger to the confirmed success event.</li><li>Review accepted enquiries after deployment, allowing for traffic and delay.</li></ol>
<p>Changing a headline or increasing a bid does not fix this incident. Correcting measurement may reduce the reported conversion count while making the business outcome more trustworthy.</p>
<h2>Separate message, usability and measurement</h2>
<p>A message mismatch sends the right person to an unclear offer. A usability problem prevents action even when the offer is understood. A measurement problem records the journey incorrectly. Test each before assuming that all low-conversion traffic comes from weak copy.</p>
<p>Google's <a href="https://support.google.com/google-ads/answer/14086?hl=en">landing-page explanation</a> describes relevance and usability. Our <a href="/blog/how-conversion-tracking-works">conversion checklist</a> covers the difference between a click and success.</p>
<h2>Make a page experiment interpretable</h2>
<p>Write one question: for example, whether clarifying the service area increases accepted quote requests. Preserve the conversion definition and comparable traffic where practical. Record the change date and allow enough observations before deciding.</p>
<p>A hypothetical page with three enquiries from 50 visitors has a 6% observed conversion rate; one more enquiry would make it 8%. That sensitivity illustrates why a small sample should not support a sweeping promise of improvement.</p>
<h2>Inspect loading and destination behaviour</h2>
<p>Check the actual expanded destination, redirects, error status and mobile rendering. Test under representative connection conditions and remove unnecessary blocking work where you can. Page speed matters to usability, but there is no universal fixed percentage of sales recovered by removing one second.</p>
<p>Use Google's <a href="https://support.google.com/google-ads/answer/7543502?hl=en">landing-page report guidance</a> alongside <a href="/blog/what-is-ad-rank">ad-quality diagnostics</a>. Review the <a href="/blog/google-ads-campaign-structure-mistakes">keyword-to-page map</a> and <a href="/google-ads-management">campaign management scope</a> so page improvements serve the same business goal as the ads.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function facebookAdsExplained(): array
    {
        return [
            'slug' => 'facebook-ads-for-small-business',
            'title' => 'Facebook Ads for Small Business: A Test Plan',
            'description' => 'Plan a Meta ad around a useful outcome, suitable creative and working measurement. Includes a service-business example and lead-quality review.',
            'category' => 'Getting Started',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>Give a browsing audience a clear reason to act</h2>
<p>A Meta ad can introduce an offer while someone browses Facebook or Instagram. It needs to explain what is offered, who it suits and what happens next. A visually polished image with no clear offer can attract attention without producing a useful customer.</p>
<p>Start with a business outcome and a destination or enquiry process. Choose the objective and available optimisation settings that fit that outcome. Paying for traffic alone is not proof that the campaign is being optimised for qualified enquiries.</p>
<h2>Worked example: a useful service enquiry</h2>
<p><strong>Hypothetical creative brief, not a performance claim.</strong> A business offers garden maintenance in a defined local area and accepts quote requests. Its ad can show the actual type of work, describe the area and invite a quote request.</p>
<p>The destination should confirm the service and ask enough information to decide whether the enquiry can be served. “Guaranteed Same-Day Service” is unsuitable unless the business can genuinely fulfil and qualify that promise.</p>
<p>Use real business photography where available, or accurately reviewed generated imagery. Do not imply an AI-generated project is a completed customer job. Avoid invented before-and-after results and unsupported customer quotes.</p>
<h2>Prepare the placement and the next action together</h2>
<ul><li>Offer and service area.</li><li>Audience hypothesis permitted by platform rules.</li><li>Creative format and dimensions for the intended placements.</li><li>Visible message, required conditions and call to action.</li><li>Working website form, booking path or supported lead form.</li><li>A person responsible for responding to the enquiry.</li></ul>
<p>Meta's <a href="https://about.fb.com/ltam/wp-content/uploads/sites/14/2023/11/LeadGenerationGuide.pdf">lead-generation guide</a> discusses supported enquiry approaches. Check current account capabilities and rules rather than assuming one configuration works for every business or category.</p>
<h2>Measure accepted leads as well as submissions</h2>
<p>Test the actual success event and the intended account or dataset. If more than one integration sends the same logical action, check for duplicate reporting. Respect consent and data-handling requirements; a server integration does not remove those obligations.</p>
<p>Keep a record of whether a submitted lead fits the service and can be contacted. A cheap form submission that cannot be served is different from an accepted enquiry. Review the full journey before crediting creative for a low reported cost.</p>
<h2>Worked calculation: volume can conceal quality</h2>
<p><strong>Hypothetical calculation.</strong> At A$180 spend and 18 submissions, cost per submission is A$10. If six submissions are accepted enquiries, cost per accepted enquiry is A$30. If two become customers, media acquisition cost is A$90 per customer, before other costs.</p>
<p>Use your real records and conversion delay. This is a way to compare definitions, not an expected result for garden maintenance or Meta advertising.</p>
<h2>Review a test before expanding it</h2>
<p>Check whether the audience, offer and response process fit. Change a specific element with a documented reason, then observe qualified outcomes. A few days of low-volume results do not justify replacing every asset or assuming all audiences are exhausted.</p>
<p>Read <a href="/blog/how-ai-writes-your-ad-copy">the creative brief checklist</a>, <a href="/blog/landing-page-conversion-rate-optimisation">destination checks</a> and <a href="/blog/multi-platform-advertising">cross-channel measurement</a>. Site to Spend's <a href="/pricing">current managed plans</a> describe platform coverage; the one-time Google Ads product does not include an ongoing Meta campaign service.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function negativeKeywords(): array
    {
        return [
            'slug' => 'negative-keywords-explained',
            'title' => 'Google Ads Negative Keywords: Matching',
            'description' => 'Exclude unsuitable search intent without blocking useful customers. Work through negative match types, a local-service example and conflict checks.',
            'category' => 'Google Ads',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>Exclude what the business cannot serve</h2>
<p>A negative keyword helps prevent a Search ad from matching an unsuitable search. The point is not to create the longest exclusion list; it is to stop paying for intent that does not fit the offer.</p>
<p>Begin with your actual service, location and customer. A business selling plumbing repairs may exclude employment or training intent. A college advertising plumbing courses would make the opposite decision. A generic negative list copied from another account can remove relevant customers.</p>
<h2>Negative matching differs from positive keyword matching</h2>
<p>For Search, a negative broad match generally blocks searches containing all its terms, even in a different order. Negative phrase match requires the phrase in that order; negative exact match blocks the complete matching search without additional words. Negative keywords do not automatically cover close variants. Google's <a href="https://support.google.com/google-ads/answer/2453972?hl=en">negative keyword guidance</a> explains the rules and how other networks differ.</p>
<p>Review spelling, singular/plural forms and the actual query. Do not assume that excluding one form also excludes every related meaning. Avoid treating these Search rules as universal placement controls for Display or Video.</p>
<h2>Worked example: one query, three possible exclusions</h2>
<p><strong>Hypothetical Search example.</strong> A repair-only plumber receives the query “plumbing apprenticeship Brisbane”. It does not employ apprentices or sell courses.</p>
<ul><li>A negative exact for that complete query excludes a narrow observed search.</li><li>A negative phrase for “plumbing apprenticeship” addresses that phrase across additional words.</li><li>A broad negative for “apprenticeship” excludes a wider training-related intent.</li></ul>
<p>The broader choice may fit this business, but first confirm that the term cannot describe a paid service it offers. Record the reason. After applying it, check that the intended repair searches remain eligible.</p>
<h2>Review actual search terms with business context</h2>
<ol><li>Read the query, not just the selected positive keyword.</li><li>Classify whether the searcher wants the service you sell.</li><li>Check conversion delay and lead quality before declaring a relevant query unproductive.</li><li>Choose the smallest sensible exclusion scope: ad group, campaign or shared list.</li><li>Check matching behaviour and conflicts before saving.</li></ol>
<p>A term with no conversion from two clicks is not enough evidence to label it permanently wasteful. Conversely, a clearly unrelated request does not need weeks of spending to establish that you do not sell the requested product.</p>
<h2>Avoid blocking a useful brand or service</h2>
<p>Suppose a repair business services Brand X equipment. A search about buying spare parts may be unsuitable, while “Brand X repair near me” is useful. Excluding “Brand X” entirely removes both. Separate product-purchase intent from the brand rather than treating every mention as irrelevant.</p>
<p>Conflicts can also occur between a campaign's positives and a shared negative list. Keep a small audit trail: term, match type, scope, reason and date. Recheck it when the business adds a new service.</p>
<h2>Use negatives with structure and measurement</h2>
<p>Negative keywords do not fix inaccurate location targeting, a broken destination or a weak conversion definition. Use the <a href="/blog/google-ads-campaign-structure-mistakes">keyword-to-page map</a>, <a href="/blog/google-ads-audience-targeting">audience controls</a> and <a href="/blog/how-conversion-tracking-works">tracking checks</a> together. <a href="/google-ads-management">Managed campaign review</a> should preserve the intended customer journey while excluding unsuitable traffic.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function adRankExplained(): array
    {
        return [
            'slug' => 'what-is-ad-rank',
            'title' => 'Ad Rank, Quality Score and Search Ad Delivery',
            'description' => 'Understand auction eligibility without confusing Quality Score or Ad Strength with a ranking formula. Includes a low-delivery diagnostic example.',
            'category' => 'Google Ads',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>Ad Rank affects whether and where an ad can appear</h2>
<p>Google evaluates an ad's eligibility and position in each auction. Ad Rank considers bids, auction-time ad and landing-page quality, thresholds, competition, search context and the expected contribution of assets. It is not a public calculation you can reproduce by multiplying your bid by the displayed Quality Score. See Google's <a href="https://support.google.com/google-ads/answer/1722122?hl=en">Ad Rank explanation</a>.</p>
<p>Being enabled is only one condition for delivery. An ad can be enabled but fail a policy review, account requirement or auction threshold.</p>
<h2>Quality Score is a diagnostic, not the auction formula</h2>
<p>The keyword-level 1–10 Quality Score summarises expected click-through rate, ad relevance and landing-page experience. Google says it is not an input in the auction and should not be treated as a performance KPI. Use its components to investigate the experience, rather than promising a fixed CPC reduction for every point gained. Read the <a href="https://support.google.com/google-ads/answer/6167118?hl=en">Quality Score guidance</a>.</p>
<p>Ad Strength is a separate diagnostic about responsive-search-ad assets. It does not guarantee serving or a particular position. A high rating cannot repair billing, advertiser verification or a destination outage.</p>
<h2>Worked example: a low-volume repair campaign</h2>
<p><strong>Hypothetical diagnostic example.</strong> A business sees limited impressions for repair keywords and an “Below average” landing-page component. The destination is a generic homepage with no repair details.</p>
<ol><li>Confirm there is no account or ad-policy restriction.</li><li>Check the query intent and whether the business actually offers the repair.</li><li>Use a relevant page showing the service, service area and enquiry action.</li><li>Make the ad's promise consistent with that page.</li><li>Review delivery and qualified leads after the change, using a suitable period.</li></ol>
<p>The page improvement addresses a concrete mismatch. It does not establish that CPC will fall by a particular percentage or that the ad will become the first result.</p>
<h2>Inspect the appropriate delivery metrics</h2>
<p>Top and absolute-top metrics describe where ads appeared or the share of eligible opportunities reached. They are different from organic search position and from the old idea of one universal ad rank number.</p>
<p>Where available, lost impression share due to rank and budget can help distinguish auction constraints from insufficient budget. Check the metric's campaign scope, freshness and volume. An unavailable value is not a measured zero.</p>
<h2>Improve the experience before buying more exposure</h2>
<ul><li>Match the searcher's actual need with a truthful headline.</li><li>Make the destination useful on mobile and easy to navigate.</li><li>Keep the offer, price conditions and service area clear.</li><li>Use relevant assets without inventing claims.</li><li>Verify conversions so increased clicks can be judged against useful outcomes.</li></ul>
<p>A higher bid can change auction competitiveness, but it can also increase costs. Evaluate the business economics rather than chasing position alone.</p>
<h2>Use a sequence of checks</h2>
<p>Read the <a href="/blog/why-your-google-ads-stop-working">delivery checklist</a> first, then inspect <a href="/blog/google-ads-campaign-structure-mistakes">keyword-to-page structure</a> and <a href="/blog/how-responsive-search-ads-work">responsive ad assets</a>. Site to Spend's <a href="/google-ads-management">managed service</a> monitors supported campaign signals; Google's review and auction decisions remain outside our control.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function responsiveSearchAds(): array
    {
        return [
            'slug' => 'how-responsive-search-ads-work',
            'title' => 'Responsive Search Ads: Assets and Useful Tests',
            'description' => 'Write search-ad assets that work in combinations, use pinning carefully and evaluate real outcomes. Includes a clearly labelled headline example.',
            'category' => 'Google Ads',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>Write assets that make sense in different combinations</h2>
<p>A responsive search ad combines supplied headlines and descriptions into eligible presentations. Google can select different combinations, so every asset should be truthful, complete and compatible with the others. You cannot assume that a sentence split across two headlines will always appear together.</p>
<p>Google's <a href="https://support.google.com/google-ads/answer/7684791?hl=en">responsive search ad guide</a> documents the format, character limits and pinning behaviour. Check the current requirements when preparing assets rather than relying on a screenshot of one rendered ad.</p>
<h2>Use distinct messages rather than repeated synonyms</h2>
<p>A useful asset set covers the service, a verified difference, offer conditions and next action. Five versions of “Best Service” do not give the searcher five useful reasons to act. Avoid unsupported superlatives, invented reviews and a price the destination cannot honour.</p>
<p>If a required qualification changes the meaning of the offer, make sure eligible combinations still communicate it clearly. Pinning can be appropriate for necessary wording, but it reduces flexibility; check which positions can consistently carry the required text.</p>
<h2>Worked example: a bookkeeping enquiry</h2>
<p><strong>Hypothetical copy example, not a performance result.</strong> An accountant offers bookkeeping for small retailers and accepts quote requests online. After verifying those facts, possible headlines are:</p>
<ul><li>Bookkeeping for Retailers</li><li>Monthly Books, Clear Reports</li><li>Request a Bookkeeping Quote</li></ul>
<p>The service, practical value and action are different messages. “Guaranteed Tax Savings” would add a claim the example does not support. “Free” should only appear if the stated service or offer is genuinely free with clear conditions.</p>
<p>Send the ad to a bookkeeping page that confirms the audience and quote process. The headline set cannot compensate for a destination about an unrelated service.</p>
<h2>Ad Strength is guidance, not proof of success</h2>
<p>Ad Strength highlights opportunities to improve asset variety and relevance. Google states that its rating does not directly determine serving eligibility. A higher rating is not a guarantee of lower CPA or higher position. Consult <a href="https://support.google.com/google-ads/answer/9921843?hl=en">the Ad Strength explanation</a>, then assess actual delivery and outcomes.</p>
<p>Keep the diagnostic separate from policy status. An ad with polished assets can still fail a destination or account check.</p>
<h2>Make a creative test interpretable</h2>
<ol><li>Write the question: for example, whether a retailer-specific message improves qualified enquiries.</li><li>Keep the offer, location and destination consistent where practical.</li><li>Confirm the successful enquiry event is measured.</li><li>Allow enough observations and conversion delay before deciding.</li><li>Record the change and review qualified outcomes, not just an asset label.</li></ol>
<p>Responsive assets do not receive identical exposure, and a few clicks are weak evidence. Avoid declaring each individual headline a causal winner from a small descriptive report.</p>
<h2>Review the whole search journey</h2>
<p>Use <a href="/blog/how-ai-writes-your-ad-copy">the AI copy checklist</a>, <a href="/blog/how-conversion-tracking-works">conversion verification</a> and <a href="/blog/landing-page-conversion-rate-optimisation">landing-page review</a> together. <a href="/ai-ads-management">AI management</a> can prepare and review supported assets; it cannot promise which combination Google will show or whether a customer will enquire.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function aiAdCopywriting(): array
    {
        return [
            'slug' => 'how-ai-writes-your-ad-copy',
            'title' => 'AI Google Ad Copy: Brief and Review Checklist',
            'description' => 'Give AI a specific offer, verified claims and a destination, then review the generated copy. Includes an example brief and a practical accuracy checklist.',
            'category' => 'Platform',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>The brief matters more than a request for “better copy”</h2>
<p>AI can draft useful ad variations when the input describes an actual service, buyer and next action. A generic instruction to “write a high-converting ad” encourages generic claims. Begin with facts the business can fulfil, then ask for distinct angles within those facts.</p>
<p>Site to Spend uses your business sources and reviewed profile when preparing the campaign. The website is a source, not an assurance that every extracted detail is current. Correct old prices, outdated service areas and missing conditions before they reach the copy.</p>
<h2>A usable copy brief</h2>
<ul><li>Service or product being advertised.</li><li>Customer and location the offer serves.</li><li>Verified difference, with the evidence behind any claim.</li><li>Price or promotion conditions that must remain clear.</li><li>Destination and the useful action on that page.</li><li>Brand tone and wording to avoid.</li></ul>
<p>Technical format limits and platform policy are additional constraints. Google's <a href="https://support.google.com/google-ads/answer/7684791?hl=en">responsive search ad guidance</a> explains how assets can combine; each message should work independently.</p>
<h2>Worked example: specific beats invented</h2>
<p><strong>Hypothetical brief and copy, not a customer result.</strong> A business provides bookkeeping for independent retailers in Brisbane. It accepts online quote requests and sends monthly reports. It has not measured any claim about faster growth or tax savings.</p>
<p>A useful brief asks for service, reporting and quote-request angles. “Bookkeeping for Retailers” and “Request a Bookkeeping Quote” reflect the supplied facts. “Double Your Profit in 30 Days” does not. The latter remains false even if an AI generated it confidently.</p>
<p>Ask for several concepts, then check that each says something different. Changing “Easy Bookkeeping” to “Simple Bookkeeping” alone is not a meaningful strategic variation.</p>
<h2>Review claims before polish</h2>
<ol><li>Can the business fulfil every service, location and timing claim?</li><li>Is a price or discount current and qualified where needed?</li><li>Does any review, rating, customer count or testimonial have real evidence?</li><li>Does the destination make the same offer?</li><li>Can different headline combinations create an unintended promise?</li><li>Does the wording meet the platform's current editorial and advertising rules?</li></ol>
<p>Do not repair a rejected ad by merely disguising the claim that caused the problem. Correct the underlying offer, destination or policy issue and verify the review outcome.</p>
<h2>Separate search assets from designed image creative</h2>
<p>Search text, a platform image asset and a designed promotional graphic serve different placements. A creative brief should identify the intended placement, aspect ratio, message and brand assets. An image asset suitable for one format does not necessarily need or allow the same text treatment as a social ad.</p>
<p>Review the creative for truthful imagery, readable hierarchy and a clear connection to the offer. Do not infer performance from how polished an AI-generated picture looks.</p>
<h2>Test the idea against a business outcome</h2>
<p>Define the test question and review the outcome after enough relevant traffic and conversion delay. Use <a href="/blog/how-responsive-search-ads-work">the responsive asset checklist</a> and <a href="/blog/how-conversion-tracking-works">measurement checks</a>. <a href="/blog/how-competitor-analysis-works">Competitor research</a> can suggest a difference, while <a href="/ai-ads-management">AI management</a> helps keep the generation and monitoring work connected.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function audienceTargeting(): array
    {
        return [
            'slug' => 'google-ads-audience-targeting',
            'title' => 'Google Ads Audiences: Target or Observe',
            'description' => 'Understand audience restrictions, observation and first-party lists. Includes an example of accidental reach restriction and a review checklist.',
            'category' => 'Google Ads',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>Audience settings can change who is eligible</h2>
<p>In a Search campaign, relevant keywords describe the search intent. Audience settings add another control or reporting dimension. Selecting a list without understanding its setting can unintentionally narrow a campaign that was meant to reach new customers.</p>
<p>Google distinguishes “Observation”, which generally does not restrict reach, from “Targeting”, which restricts eligibility to selected audiences. Its <a href="https://support.google.com/google-ads/answer/7068417?hl=en">Search audience setup guide</a> explains the distinction and notes that Search keywords remain required except for the relevant dynamic format.</p>
<h2>Worked example: a list that is too small to be the whole market</h2>
<p><strong>Hypothetical configuration example.</strong> A new service business has a small website-visitor list. It adds that list to a Search campaign expecting better reporting, but selects Targeting.</p>
<p>The campaign is now limited by the selected audience rather than reaching all otherwise eligible people searching its service keywords. If the list has little eligible activity, delivery can be small. Check the audience mode, list eligibility and intended acquisition goal before increasing the budget.</p>
<p>Observation may suit a reporting question about returning visitors; Targeting may suit a deliberate restricted campaign. Neither is universally right without knowing the objective.</p>
<h2>First-party data is not a licence to upload any list</h2>
<p>Customer Match use depends on account eligibility, feature availability and the applicable data policies. Use information collected in the permitted first-party context, with the required notices and consent. Purchased or scraped email addresses are not a substitute. Read Google's <a href="https://support.google.com/google-ads/answer/6299717?hl=en">Customer Match policy</a> and check the account's available capabilities.</p>
<p>Audience size, platform matching and policy restrictions can limit what a list supports. An uploaded row count is not the same as an eligible advertising audience. Some sensitive categories and campaign types have additional restrictions.</p>
<h2>Review all reach controls together</h2>
<ul><li>Service area and the location option selected.</li><li>Language and campaign schedule.</li><li>Keyword intent and match types.</li><li>Negative keywords and exclusions.</li><li>Audience mode, eligibility and size.</li><li>Ad and account restrictions.</li></ul>
<p>An audience cannot make an unrelated keyword relevant. A visitor list also does not prove a person is ready to buy. Inspect actual queries and qualified outcomes alongside audience reports.</p>
<h2>Evaluate segments without assuming causation</h2>
<p>A returning-visitor segment can convert differently because its members already know the business. That does not prove that adding the segment caused the improvement. Use comparable periods, check the sample size and consider the conversion delay.</p>
<p>If Smart Bidding is in use, check which manual adjustments the selected strategy supports before applying a percentage modifier. Settings, exclusions and reporting dimensions have different effects.</p>
<h2>Where Site to Spend fits</h2>
<p>Audience work uses the available customer data, supported platform actions and campaign goal. It cannot guarantee list matching, override platform restrictions or replace your obligation to provide lawful data.</p>
<p>Use our <a href="/blog/google-ads-campaign-structure-mistakes">structure guide</a> and <a href="/blog/negative-keywords-explained">negative keyword checks</a> to review intent, then verify <a href="/blog/how-conversion-tracking-works">conversion measurement</a>. See <a href="/ai-ads-management">AI management scope</a> for the ongoing monitoring work.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function budgetPacing(): array
    {
        return [
            'slug' => 'how-budget-pacing-works',
            'title' => 'Google Ads Budget Pacing: A Worked Example',
            'description' => 'Understand average daily budgets, monthly spending limits and campaign pacing. Includes a worked budget calculation and checks before changing bids.',
            'category' => 'Google Ads',
            'read_time' => '6 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-08',
            'content' => <<<'HTML'
<h2>Your daily budget is an average, not a fixed daily invoice</h2>
<p>For most Google Ads campaigns, actual daily spend can reach twice the average daily budget. With an unchanged average daily budget through a full month, the usual monthly charging limit is 30.4 times that budget. Different budget types and changes during the month need separate treatment. See Google's <a href="https://support.google.com/google-ads/answer/1704424?hl=en">spending limits guidance</a>.</p>
<p>That distinction matters when judging a busy day. A campaign spending more than its average today is not, by itself, proof that an agent lost control. Nor does a quiet day prove the budget is too low.</p>
<h2>Worked example: an A$912 monthly media allowance</h2>
<p><strong>Hypothetical planning example, not a spending forecast.</strong> Suppose you choose an A$30 average daily budget, leave it unchanged for the month and use a campaign covered by the usual daily and monthly limits.</p>
<ul><li>Average daily budget: A$30.</li><li>Usual daily spending limit: A$60 (30 × 2).</li><li>Usual monthly charging limit: A$912 (30 × 30.4).</li></ul>
<p>If the campaign spent A$270 during the first ten days, a simple straight-line reference at A$30 per day would be A$300. That is A$30 below the reference, but it does not mean the remaining budget should be forced into unsuitable auctions. Demand, eligibility and conversion quality decide whether additional spend is useful.</p>
<h2>Read pacing alongside results</h2>
<p>Compare actual spend with the budget and the outcome the campaign exists to produce. A campaign that spends its entire allowance on irrelevant clicks is paced but unsuccessful. A campaign that spends less and brings profitable customers may not need a higher budget.</p>
<p>For a lead campaign, review qualified leads as well as the recorded conversion count. At A$300 spend and six completed enquiries, recorded cost per enquiry is A$50. If only two enquiries fit the service, qualified cost per lead is A$150. These are illustrative calculations; use your own lead outcomes.</p>
<h2>Diagnose underspend before raising the budget</h2>
<ol><li>Check that the campaign, ad groups, ads and keywords are eligible to serve.</li><li>Confirm billing, dates, advertiser verification and location settings.</li><li>Review whether there is relevant search demand for the selected terms.</li><li>Check whether bid limits or conversion targets are restricting auctions.</li><li>Check reporting freshness and the account time zone.</li></ol>
<p>Increasing a budget cannot repair a disapproved ad or a billing hold. Google's <a href="https://support.google.com/google-ads/answer/9208915?hl=en">Search campaign delivery troubleshooting</a> provides the platform checks.</p>
<h2>Scheduling should follow the business and bidding strategy</h2>
<p>If someone must answer a phone call immediately, operating hours are a practical constraint. A business that accepts enquiries online may still value evening traffic. Use the lead-handling process and a meaningful amount of outcome data rather than assuming overnight clicks are wasted.</p>
<p>Smart Bidding already uses auction signals. Manual bid adjustments are not universally supported across automated strategies. Before adding a time, device or location modifier, check the <a href="https://support.google.com/google-ads/answer/2732132?hl=en">bid adjustment rules for the selected strategy</a>. An ad schedule can restrict eligibility even where a bid modifier is ignored.</p>
<h2>Management fees and media spend are different budgets</h2>
<p>Your Site to Spend subscription covers management. Managed campaign delivery uses ad-spend credits reconciled against actual advertising spend. A one-time setup is instead handed over paused for the customer to add Google billing and manage. Do not add the subscription fee to Google Ads' conversion value.</p>
<p>Review your <a href="/pricing">current plan</a> and keep the management fee, media budget and business margin visible when assessing total acquisition cost. Before using conversion data to adjust budgets, follow the <a href="/blog/how-conversion-tracking-works">tracking checklist</a>.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function multiPlatformAdvertising(): array
    {
        return [
            'slug' => 'multi-platform-advertising',
            'title' => 'Multi-Platform Ads: Budget and Measurement',
            'description' => 'Decide when another ad channel is useful without splitting a small budget blindly. Includes a test plan and checks for duplicate attribution.',
            'category' => 'Getting Started',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>Add a channel to answer a business question</h2>
<p>More platforms create more possible reach and more measurement work. A small business should choose a channel because its customers and offer fit the placement, not because a dashboard supports four networks.</p>
<p>Search can reach people expressing a relevant need. Social creative can introduce an offer while people browse. Professional-context placements can be useful for some business audiences. These are starting hypotheses, not guarantees that one network is cheapest or best for every advertiser.</p>
<h2>Worked example: one channel before two</h2>
<p><strong>Hypothetical test plan.</strong> A business has A$600 for an initial media experiment. It can split that into four A$150 experiments, but each would have limited opportunity to produce interpretable results.</p>
<p>Instead it selects one measurable offer and one channel for the first phase. It records accepted enquiries, not merely clicks. A second-channel test is added when the first journey works and there is enough budget to compare a useful outcome.</p>
<p>This is a planning choice rather than a forecast. If the first channel has no relevant demand, reassess the channel and offer instead of spending the full allowance simply to finish the experiment.</p>
<h2>Give every channel an appropriate brief</h2>
<ul><li>The buyer and buying situation.</li><li>The offer and destination.</li><li>The placement, asset dimensions and required wording.</li><li>The conversion action and expected delay.</li><li>A separate media allowance and review period.</li></ul>
<p>A Search headline set is not a complete social creative brief. A designed social graphic is not automatically a suitable Search image asset. Keep brand facts consistent while adapting the message to the placement.</p>
<h2>Install and test the measurement for each platform</h2>
<p>Google Ads measurement, Microsoft's UET and LinkedIn's Insight Tag have different integrations and settings. A tag-manager container can host tags, but installing the container does not prove any individual conversion works. See <a href="https://learn.microsoft.com/en-us/advertising/guides/universal-event-tracking?view=bingads-13">Microsoft's UET guide</a> and <a href="https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads-reporting/conversion-tracking">LinkedIn's conversion guide</a> for their technical setup.</p>
<p>Test the successful action once, verify the intended platform account and follow relevant consent requirements. Keep a real enquiry or transaction record for reconciliation.</p>
<h2>Do not add platform-attributed conversions blindly</h2>
<p>Two platforms may both attribute influence to the same purchase under their own windows and rules. Summing those counts can overstate the number of customers acquired. Compare platform reports with unique orders or qualified leads in your business system.</p>
<p>Keep spend by channel, platform-attributed results and deduplicated business outcomes as separate views. They answer different questions. A universal “ROAS” score can conceal a mix of estimated leads and actual revenue.</p>
<h2>Choose the service and budget deliberately</h2>
<p>Site to Spend supports Google, Meta, Microsoft and LinkedIn, with platform coverage determined by the current plan. The <a href="/google-ads-setup">one-time product</a> is specifically a Google Ads build; it is not ongoing management across four channels.</p>
<p>Compare <a href="/pricing">plan coverage</a>, use the <a href="/blog/how-conversion-tracking-works">conversion checklist</a> and read <a href="/blog/understanding-roas">ROAS versus profit</a>. Add channels when they improve a measurable customer journey, rather than assuming that wider distribution alone improves results.</p>
HTML,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function understandingRoas(): array
    {
        return [
            'slug' => 'understanding-roas',
            'title' => 'ROAS Explained: Revenue, Margin and Ad Spend',
            'description' => 'Calculate return on ad spend without mistaking attributed revenue for profit. Includes a worked margin example and conversion-value checks.',
            'category' => 'Google Ads',
            'read_time' => '5 min read',
            'published' => '2026-05-03',
            'modified' => '2026-10-09',
            'content' => <<<'HTML'
<h2>ROAS answers a specific question</h2>
<p>Return on ad spend is measured conversion value divided by advertising cost. If the value is actual revenue, it tells you how much attributed revenue you recorded for each unit of media spend. It does not subtract product costs, service delivery, management fees or refunds automatically.</p>
<p>If the conversion value is an estimated lead value instead of a sale, say so. A dashboard labelled “4x ROAS” can describe different economics depending on what the numerator contains. Google's <a href="https://support.google.com/google-ads/answer/13064207?hl=en">conversion-value guide</a> explains value measurement and its use in bidding.</p>
<h2>Worked example: 4x revenue ROAS, modest contribution</h2>
<p><strong>Hypothetical calculation, not a customer result.</strong> A shop attributes A$2,000 of revenue to A$500 of media spend. Revenue ROAS is 2,000 ÷ 500 = 4x, or 400%.</p>
<p>Suppose the orders have a 30% contribution margin before advertising. That gives A$600 of contribution. Subtract A$500 media spend and A$100 remains before the management fee and other costs. The campaign's 4x revenue figure has not established a large profit.</p>
<p>With that simplified margin and no other acquisition costs, media break-even revenue ROAS would be 1 ÷ 0.30, or about 3.33x. Returns, fixed fees and repeat purchases can change the business decision. Use your own accounting assumptions rather than adopting the example's margin.</p>
<h2>Lead values need an explicit method</h2>
<p>For a service business, an enquiry is not completed revenue. If a documented estimate is useful, show the close rate, expected contribution and period used. For example, a hypothetical 20% close rate and A$300 contribution per customer imply A$60 expected contribution per comparable lead, before lead acquisition and handling costs.</p>
<p>That estimate is only as good as the data and definition behind it. Ten spam submissions are not ten equivalent leads. Update the estimate when qualification or sales outcomes change; do not claim booked revenue from a button click.</p>
<h2>Check the value before optimising towards it</h2>
<ul><li>Actual sale or estimated lead value?</li><li>Correct amount and currency?</li><li>One count per logical order or enquiry?</li><li>Handling of refunds, cancellations and repeat purchases?</li><li>Conversion action and attribution window used?</li><li>Comparable reporting period, with conversion delay allowed?</li></ul>
<p>Use the <a href="/blog/how-conversion-tracking-works">tracking checklist</a> to test the event. A value multiplied by the wrong currency or duplicated checkout event can make an unprofitable campaign look strong.</p>
<h2>A target is not a guarantee</h2>
<p>Value-based bidding uses the configured measurement to pursue an outcome. It cannot guarantee revenue or know your margin unless the signal and business model express what matters. Setting a very high target can restrict delivery rather than manufacture a better offer.</p>
<p>Read <a href="/blog/what-is-smart-bidding">the bidding guide</a> before changing targets. Keep platform efficiency and full business acquisition cost as separate views.</p>
<h2>Make the decision with complete costs</h2>
<p>Include media spend, management, creative or landing-page costs where appropriate, and the team's lead-handling time. The <a href="/blog/true-cost-of-managing-google-ads-yourself">management-cost example</a> explains that comparison.</p>
<p>For multiple channels, reconcile unique business outcomes instead of adding every platform's attributed sales. Use our <a href="/blog/multi-platform-advertising">measurement plan</a> and compare <a href="/pricing">current fees</a> before deciding whether the campaign economics support more spend.</p>
HTML,
        ];
    }
}
