<?php

// This copy feeds visible FAQs and JSON-LD. :setup_fee comes from the same
// Stripe configuration as checkout; management fees and media spend are separate.

return [
    'landing' => [
        0 => [
            'question' => 'How does sitetospend work?',
            'answer' => 'Start with your website address. We prepare a business profile, research your market and generate a campaign plan. You confirm the business facts, review the ads and choose a budget before launch. Managed plans include ongoing agent monitoring; one-time setup ends with a paused handover.',
        ],
        1 => [
            'question' => 'Which ad platforms does sitetospend support?',
            'answer' => 'Google Ads, Meta across Facebook and Instagram, Microsoft Ads and LinkedIn Ads are supported. Available platforms depend on your plan. We manage campaigns through customer ad accounts under our platform management account.',
        ],
        2 => [
            'question' => 'Do I need a credit card to start?',
            'answer' => 'No card is required to create an account and explore the free experience. A paid service and an advertising budget are required to deploy real campaigns. The one-time package is commissioned when its setup payment is confirmed.',
        ],
        3 => [
            'question' => 'Can I pay once instead of subscribing?',
            'answer' => 'Yes. One-time Google Ads setup is US$:setup_fee for an account and campaign build, ad copy and conversion-tracking configuration. It is handed over paused so you can complete your own Google billing and run it. Website access or tag installation may be required. Ongoing management and ad spend are separate.',
        ],
        4 => [
            'question' => 'Will I still own my ad accounts and data?',
            'answer' => 'Customer campaigns run in customer advertising accounts under our management account. The one-time package includes a Google Ads administrator invitation and paused handover. Access and billing arrangements depend on the service you choose; review them before launch. Your business information and generated assets remain available in your workspace.',
        ],
        5 => [
            'question' => 'How is this different from hiring an agency?',
            'answer' => 'Site to Spend charges a published plan fee for supported campaign preparation and management. Agency scopes and fees vary, so compare what each service includes. The agents record changes and their reasons, but campaign results still depend on your offer, destination, budget, measurement and platform eligibility.',
        ],
        6 => [
            'question' => 'What happens when an ad gets disapproved?',
            'answer' => 'Agents check policy and delivery issues, attempt supported repairs and verify the outcome. Google still controls review and approval. Account verification, billing or website changes may require your input rather than an automatic copy rewrite.',
        ],
        7 => [
            'question' => 'How quickly will I see results?',
            'answer' => 'Campaign preparation and ad review happen before delivery. Google review, billing, account verification and auction eligibility can delay serving. Performance needs reliable conversion data and enough observations; there is no guaranteed launch time or result in the first week.',
        ],
        8 => [
            'question' => 'Do I need to know anything about Google Ads?',
            'answer' => 'The flow explains the business profile, goal, budget and ad review in plain language. You still need to confirm accurate business facts and complete any required account, billing or website steps. The dashboard records activity so you can inspect what changed and why.',
        ],
    ],
    'pricing' => [
        0 => [
            'question' => 'What\'s included in the free tier?',
            'answer' => 'The free experience lets you explore business profiling, campaign generation and a sandbox without spending on real ads. Creative and feature limits vary by plan; see the plan cards for current inclusions. A paid plan is required for managed live campaign deployment.',
        ],
        1 => [
            'question' => 'Does the subscription price include my ad budget?',
            'answer' => 'No. The subscription pays for campaign management and platform features. Advertising spend is additional. Managed campaigns use prepaid ad-spend credits reconciled against actual platform spend. One-time customers take over a paused Google campaign and add their own Google billing.',
        ],
        2 => [
            'question' => 'Is there a one-off option instead of a monthly plan?',
            'answer' => 'Yes. One-time Google Ads setup is US$:setup_fee for an account and campaign build, ad copy and conversion-tracking configuration, followed by a paused handover. A single payment, with no recurring management. Website access or tag installation may be needed to complete tracking; ad spend is separate.',
        ],
        3 => [
            'question' => 'How does ad spend billing work?',
            'answer' => 'Managed campaigns begin with prepaid credit based on seven days of estimated advertising spend. Actual platform spend is reconciled against that balance, with replenishment when the balance runs low. Review the credit balance, transactions and chosen campaign budget in Billing.',
        ],
        4 => [
            'question' => 'What happens if a payment fails?',
            'answer' => 'We notify you and retry failed ad-spend replenishment. Unresolved payment issues can reduce budgets and then pause campaigns to limit further spend. After payment is resolved, resuming delivery still depends on the platform accepting the campaign changes and the account being eligible.',
        ],
        5 => [
            'question' => 'How do I update my payment method?',
            'answer' => 'Open Billing → Ad Spend to update your payment method. If a replenishment failed, use the available retry action after updating the card. Check both the credit status and campaign delivery status after recovery.',
        ],
        6 => [
            'question' => 'What do the AI specialists actually do?',
            'answer' => 'Agents research competitors, monitor campaign delivery, attempt supported repairs, review budgets, test creative and work with available audience data. Actions depend on the platform, plan and evidence available. Activity records explain why changes were made.',
        ],
        7 => [
            'question' => 'How does competitor analysis work?',
            'answer' => 'We read your business sources and competitor websites to compare offers, messaging and positioning. The resulting intelligence informs strategy and supported campaign changes. It is context for a decision, not proof that a competitor is bidding on every keyword or that copying them will improve results.',
        ],
        8 => [
            'question' => 'What happens if my ad gets disapproved?',
            'answer' => 'Agents check the policy reason and attempt supported repairs where appropriate, then verify review and delivery status. Google decides approval. Issues such as verification, billing or destination problems may need your input and cannot always be fixed by rewriting an ad.',
        ],
        9 => [
            'question' => 'How does it know what my brand looks like?',
            'answer' => 'We read your website and source material to prepare brand colours, tone and business facts for campaign generation. Review the extracted profile and proposed assets before launch; source material can be incomplete or inaccurate.',
        ],
        10 => [
            'question' => 'I already have a Google Ads account — can you use it?',
            'answer' => 'Where supported, an existing Google Ads account can be linked under our management account with a manager request. Acceptance, account access and billing must be confirmed before delivery. Ask support to confirm whether an existing-account arrangement fits the service you want.',
        ],
        11 => [
            'question' => 'What if I don\'t have a Google Ads account yet?',
            'answer' => 'We can provision a customer account under our Google Ads management account. Managed and one-time services have different billing and handover arrangements. For the one-time package, account provisioning triggers an administrator invitation to your signup email after payment is confirmed.',
        ],
        12 => [
            'question' => 'What access do you actually get to my ad account?',
            'answer' => 'The management integration can create and adjust supported campaign resources in the customer account. Billing and access depend on your service: managed campaigns use ad-spend credits; one-time builds are handed over paused for your own Google billing. Review the arrangements and Terms before choosing.',
        ],
        13 => [
            'question' => 'Can I switch plans later?',
            'answer' => 'You can manage your subscription from your dashboard. Check the current plan inclusions and Terms before changing or cancelling, including any setup fee conditions that apply to an existing account build.',
        ],
    ],
];
