<?php

namespace App\Support;

/** Authored public copy. These props feed both the initial HTML and React. */
class MarketingPages
{
    /** @return array<string, mixed>|null */
    public static function find(string $slug, string $setupFee): ?array
    {
        $pages = [
            'google-ads-management' => [
                'title' => 'Small Business Google Ads Management | sitetospend',
                'description' => 'Google Ads management for small businesses: relevant keywords, campaign reviews, conversion tracking and clear budgets. Compare managed and one-time setup.',
                'eyebrow' => 'For small businesses',
                'headline' => 'Google Ads management built around your actual customers',
                'intro' => 'Reach people searching for the service you sell. Site to Spend builds a campaign from your website, lets you review the plan and keeps monitoring it after launch on a managed plan.',
                'content' => <<<'HTML'
<h2>Start with a useful enquiry, not a long keyword list</h2>
<p>A small business rarely needs to reach everyone. It needs people in the right location, asking for a service it can deliver, at a cost the business can afford. A keyword with high search volume is a poor choice if the searcher wants a different product.</p>
<p>Site to Spend reads your website and sources to prepare your business profile, research keywords and write Google Ads copy. You review the business facts, offer, audience and proposed ads before deployment. Start with one clear offer and a landing page that makes the next action obvious.</p>
<h2>What ongoing management covers</h2>
<ul>
<li><strong>Campaign preparation:</strong> keyword research, ad copy and assets based on your confirmed business information.</li>
<li><strong>Budget and goal:</strong> a daily advertising budget and the result you want, such as a qualified enquiry or paid signup.</li>
<li><strong>Monitoring:</strong> platform delivery, ad policy status, spend, keywords and available conversion signals.</li>
<li><strong>Optimisation:</strong> campaign changes informed by the available performance data, with an activity history so you can see the reason for a change.</li>
</ul>
<p>Google decides which ads are eligible and which auctions they win. An enabled campaign can still be restricted by account verification, billing, policy review, low search demand or bid settings. Management includes checking those conditions; it cannot guarantee impressions, leads or revenue.</p>
<h2>Worked example: a local plumbing service</h2>
<p><strong>Hypothetical planning example, not a customer result.</strong> A plumber who only repairs residential leaks in Brisbane should begin with leak-repair intent and the area they actually serve. Terms about plumbing jobs, training or wholesale parts would not describe the offer.</p>
<ol>
<li>Choose the main action: a completed enquiry or a connected, relevant phone call.</li>
<li>Use a leak-repair landing page with service area, availability and contact details.</li>
<li>Group related leak-repair searches together, with ad copy that matches the service.</li>
<li>Review real search terms and lead quality after launch. Add negatives for clearly unsuitable intent, while checking they do not block legitimate enquiries.</li>
</ol>
<p>At an illustrative A$30 average daily budget, a hypothetical A$5 cost per click would buy about six clicks per day on average. That calculation describes a budget constraint, not an expected lead count. You need actual conversion and sales data to judge whether the campaign is paying for itself.</p>
<h2>Keep the management fee separate from advertising spend</h2>
<p>The managed plan has a subscription fee. Your advertising budget is additional; the currency shown for that budget can differ from the USD subscription price. Review both before launching. On managed accounts, ad-spend credits fund delivery and are reconciled against actual spend.</p>
<p>If you want a campaign built once and then managed by you, the <a href="/google-ads-setup">one-time Google Ads setup</a> is a different product. It is handed over paused, without ongoing management.</p>
<h2>What to prepare before you start</h2>
<ul><li>A readable business website with an accurate offer and service area.</li><li>The action that counts as a useful result, and what you can afford to pay for it.</li><li>Access to your website or tag manager to complete and test conversion tracking.</li><li>A budget you are comfortable spending while the campaign gathers evidence.</li></ul>
<p>Read our <a href="/blog/how-conversion-tracking-works">conversion tracking checklist</a>, <a href="/blog/how-budget-pacing-works">budget pacing example</a> and <a href="/blog/google-ads-campaign-structure-mistakes">campaign structure guide</a> before choosing your plan.</p>
HTML,
                'faqs' => [
                    ['question' => 'Do you guarantee leads?', 'answer' => 'No. Results depend on demand, competition, the offer, landing page, budget and measurement. We provide campaign preparation and monitoring; Google controls ad review and auction delivery.'],
                    ['question' => 'Do I need to choose every keyword?', 'answer' => 'No. The campaign flow researches keywords from your business information. Review the recommendations for relevance to what you sell and the places you serve. You can add your own terms.'],
                    ['question' => 'Is ad spend included in the subscription?', 'answer' => 'No. The subscription pays for management. Advertising spend is separate and uses the budget you confirm before launch.'],
                ],
            ],
            'ai-ads-management' => [
                'title' => 'AI Ads Management & Campaign Automation | sitetospend',
                'description' => 'See how AI prepares, monitors and adjusts ad campaigns with business context, delivery checks and measurable goals. Review your campaign before launch.',
                'eyebrow' => 'Campaign automation',
                'headline' => 'AI ads management with a plan you can inspect',
                'intro' => 'Automation is useful when it knows what your business sells, measures the right outcome and checks whether changes worked. Site to Spend combines campaign generation with ongoing monitoring on a managed plan.',
                'content' => <<<'HTML'
<h2>Give the agents business context first</h2>
<p>A website URL is a starting point, not proof that every extracted detail is right. Site to Spend reads your website and supplied sources to build a business profile: the offer, audience, locations, brand and tone. The onboarding review lets you correct those facts before they shape a campaign.</p>
<p>The campaign plan brings that context into ad copy, keyword research and creative directions. This helps avoid a generic campaign for a broad industry when you sell a specific service to a specific buyer.</p>
<h2>How generation and optimisation differ</h2>
<p><strong>Generation</strong> prepares a strategy, copy and relevant assets from the brief. <strong>Optimisation</strong> uses delivery and performance data after deployment to decide what may need changing. A persuasive generated headline is not evidence of a successful campaign.</p>
<ul><li>Creative work uses the confirmed offer and brand information.</li><li>Keyword decisions should match the business and the searcher's intent.</li><li>Delivery checks distinguish an enabled campaign from one that is actually getting impressions.</li><li>Performance decisions depend on reliable conversions and enough relevant observations.</li></ul>
<p>You review the proposed campaign and budget before launch. The dashboard records agent activity and the reason for changes. If you choose <a href="/google-ads-setup">one-time setup</a>, the engagement ends with a paused handover; ongoing agent management belongs to the subscription plans.</p>
<h2>Worked example: diagnosing a campaign with no conversions</h2>
<p><strong>Hypothetical example, not a performance claim.</strong> Imagine a campaign spent A$120 across 24 clicks but reports no enquiries. The right response depends on the cause:</p>
<ol><li>Confirm that the enquiry form works and that its success event is measured once.</li><li>Check whether the searches and location targeting match the actual service.</li><li>Compare the promise in the ad with the page visitors see.</li><li>Check how long customers usually take to enquire before judging recent clicks.</li></ol>
<p>If tracking is broken, raising the budget will not fix the missing signal. If the queries are irrelevant, rewriting every headline may miss the real problem. That diagnostic order matters more than the number of automated changes.</p>
<h2>What automation can and cannot control</h2>
<p>Agents can make supported API changes, monitor delivery and report problems. They cannot bypass an advertising platform's account verification, policy decisions or billing requirements. Your business must still provide truthful claims, fulfil the offer and give visitors a working place to enquire or buy.</p>
<p>Conversion setup can require a website change, tag-manager access or testing of a form or checkout event. An installed GTM snippet is only the container; it does not prove the intended conversion is being recorded.</p>
<h2>Choose the scope that fits your business</h2>
<p>Start with the channel and goal you can measure. A limited budget spread across too many channels can make each experiment hard to judge. Current plan prices and platform coverage are on the <a href="/pricing">pricing page</a>.</p>
<p>For a search-focused starting point, see <a href="/google-ads-management">small business Google Ads management</a>. For the practical checks behind automation, read <a href="/blog/why-your-google-ads-stop-working">why Google Ads stops delivering</a> and <a href="/blog/how-conversion-tracking-works">how to verify conversion tracking</a>.</p>
HTML,
                'faqs' => [
                    ['question' => 'Does AI replace Google Smart Bidding?', 'answer' => 'No. Google controls its bidding and auctions. Site to Spend prepares and manages supported campaign settings, copy, keywords and budgets around the goals and data available to the account.'],
                    ['question' => 'Can I inspect what the agents changed?', 'answer' => 'The dashboard provides an activity history with the reasons for agent actions. Review the campaign, proposed ads and budget before deployment.'],
                    ['question' => 'Will the agents fix every issue automatically?', 'answer' => 'No. Some issues require your input or a platform review, such as advertiser verification, payment details or website access. Automated repairs remain subject to platform rules and available evidence.'],
                ],
            ],
            'google-ads-setup' => [
                'title' => 'One-Time Google Ads Setup & Handover | sitetospend',
                'description' => 'Pay once for Google Ads setup. Review your ads, receive an administrator invitation and take over a paused campaign. Advertising spend is separate.',
                'eyebrow' => 'One payment. Your campaign to run.',
                'headline' => 'One-time Google Ads setup for US$:setup_fee',
                'intro' => 'Get a Google Ads account and campaign prepared for your business, then take over a paused campaign. The setup fee is a single payment, with no ongoing management subscription.',
                'content' => <<<'HTML'
<h2>What the one-time fee buys</h2>
<p>The US$:setup_fee setup engagement covers Google Ads account preparation, a campaign plan, keyword research, ad copy and conversion-tracking configuration. The campaign is handed over paused so you can inspect it, complete your own Google billing and choose when to start spending.</p>
<p>Advertising spend is additional. This package does not include recurring campaign management or a promise of sales. If you want agents to keep monitoring and adjusting campaigns after launch, compare the <a href="/google-ads-management">managed Google Ads plans</a>.</p>
<h2>The setup journey</h2>
<ol>
<li><strong>Confirm the business:</strong> enter your website, review the extracted profile and add any missing source information.</li>
<li><strong>Pay the one-time fee:</strong> checkout commissions the build. Provisioning starts after payment is confirmed.</li>
<li><strong>Accept Google Ads access:</strong> account provisioning triggers an administrator invitation for the signup email. Accept it with a Google account that can use that address. Platform access restrictions or account limits can require resolution before access is complete.</li>
<li><strong>Review the campaign:</strong> check the offer, keywords, ad copy, destination and advertising budget.</li>
<li><strong>Complete tracking and billing:</strong> install or authorise the required website tags, test the real success event and add your payment method in Google Ads.</li>
<li><strong>Take over:</strong> inspect the paused campaign in Google Ads and enable it when you are ready.</li>
</ol>
<h2>Account access is part of a usable handover</h2>
<p>A link to a dashboard is not the same as administrator access to Google Ads. Use the invitation sent to your signup email, complete Google's acceptance process and confirm you can see the intended customer account. Keep your Google account secure and check the account ID before adding billing.</p>
<p>The build uses Site to Spend's management account to provision the Google Ads customer account. You receive an administrator invitation to the customer account for the paused campaign handover.</p>
<h2>Conversion tracking needs an end-to-end check</h2>
<p>Creating a conversion action or publishing a tag does not by itself prove that an enquiry or sale will be counted. Website access, GTM permissions and the form or checkout implementation affect what can be configured. You may need to install a snippet or ask your website administrator to expose a successful form event.</p>
<p>Before launching, use the <a href="/blog/how-conversion-tracking-works">conversion tracking checklist</a> to test a successful action and verify the right account, action and value. Keep a page view or button click separate from a qualified lead or purchase.</p>
<h2>Worked example: setup cost versus media budget</h2>
<p><strong>Hypothetical budget example.</strong> The setup fee is US$:setup_fee once. If you later choose a US$20 average daily Google Ads budget and leave it unchanged for a full month, the usual monthly charging limit for most campaigns is US$608 (20 × 30.4). The media budget is separate from the setup fee; it is not a subscription payment to Site to Spend.</p>
<p>Actual daily delivery varies. Review <a href="/blog/how-budget-pacing-works">how average daily budgets work</a> and Google's billing settings before enabling the paused campaign.</p>
<h2>Choose this if you want to run the account yourself</h2>
<p>The one-time package fits a business with someone ready to review search terms, answer enquiries and manage the account after handover. If you prefer ongoing monitoring, keyword changes and creative testing, use a managed plan instead. You can review <a href="/pricing">current pricing and inclusions</a> before deciding.</p>
HTML,
                'faqs' => [
                    ['question' => 'Is the setup fee recurring?', 'answer' => 'No. It is a single US$:setup_fee payment for the setup engagement. Ongoing management and advertising spend are separate.'],
                    ['question' => 'Will ads start spending as soon as I pay?', 'answer' => 'No. The package hands over a paused campaign. You review it, complete Google Ads billing and choose when to enable it.'],
                    ['question' => 'What email should I use?', 'answer' => 'Use an address you can receive email at and use to accept Google Ads account access. The administrator invitation is sent to the signup address after account provisioning.'],
                ],
            ],
        ];

        $page = $pages[$slug] ?? null;
        if (! $page) {
            return null;
        }

        foreach (['headline', 'content'] as $field) {
            $page[$field] = str_replace(':setup_fee', $setupFee, $page[$field]);
        }
        $page['faqs'] = array_map(fn (array $faq) => [
            ...$faq,
            'answer' => str_replace(':setup_fee', $setupFee, $faq['answer']),
        ], $page['faqs']);
        $page['slug'] = $slug;

        return $page;
    }
}
